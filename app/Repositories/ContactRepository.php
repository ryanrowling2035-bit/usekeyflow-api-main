<?php

namespace App\Repositories;

use App\Constants\Constants;
use App\Models\AssignTeamMember;
use App\Models\Closing;
use App\Models\Contact;
use App\Models\Enquiry;
use App\Models\Offer;
use App\Models\Property;
use Illuminate\Http\Request;

class ContactRepository
{
    public function getAll(Request $request)
    {
        $perPage = $request->filled('per_page') && $request->per_page > 0
            ? $request->per_page
            : 10;

        // withCount rather than loading the relation: the list only needs to know
        // whether a contact is a master, not which contacts it absorbed. One
        // subquery for the page instead of a query per row.
        $page = $this->baseQuery($request)
            ->withCount('mergedContacts')
            ->latest()
            ->paginate($perPage);

        // Resolve the duplicate badge for the whole page in one query rather than
        // asking per row. The rule compares country codes and names, which lives in
        // Contact::isSamePerson() rather than in SQL — so the badge stays correct
        // without a schema change, and the export (which shares baseQuery) pays
        // nothing for a flag no CSV column renders.
        $flags = Contact::flagDuplicates($page->getCollection());

        $page->getCollection()->each(function ($contact) use ($flags) {
            $contact->isDuplicateFlag = $flags[$contact->id] ?? false;
        });

        return $page;
    }

    /**
     * The filtered Contact query (no pagination) — used by CSV export so it can
     * be streamed lazily in chunks instead of loaded all at once.
     */
    public function exportQuery(Request $request)
    {
        return $this->baseQuery($request)->latest();
    }

    /**
     * Build the filtered contacts query shared by listing and export.
     */
    protected function baseQuery(Request $request)
    {
        // Contacts merged into another are aliases of it — they stay in the table
        // with all their records intact, but they are not browsable in their own
        // right. Applies to the CSV export too, which shares this query.
        $query = Contact::query()->notMerged()->with(['creator', 'contactOwnerUser', 'latestEnquiry.agent', 'ownedListings.agent']);
        if (isAgent()) {
            $query->accessibleByAgent(user()->id);
        } elseif ($request->filled('agentId')) {
            $query->accessibleByAgent($request->agentId);
        } elseif ($request->filled('teamId')) {
            $memberIds = AssignTeamMember::where('teamId', $request->teamId)->pluck('userId')->all();
            $query->where(function ($q) use ($memberIds) {
                $q->whereIn('createdBy', $memberIds)
                    ->orWhereHas('ownedListings', fn ($sub) => $sub->whereIn('agentId', $memberIds))
                    ->orWhereHas('enquiries', fn ($sub) => $sub->whereIn('agentId', $memberIds));
            });
            // $query->whereIn('agentId', $memberIds ?: [0]);
        }
        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('phone', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%");
            });
        }
        if ($request->filled('startDate') && $request->filled('endDate')) {
            // created_at is UTC; convert the selected local days to UTC boundaries.
            $query->whereBetween('created_at', [
                localDayStartUtc($request->startDate),
                localDayEndUtc($request->endDate),
            ]);
        }
        if ($request->filled('phone')) {
            $query->where('phone', 'LIKE', "%{$request->phone}%");
        }
        if ($request->filled('agent')) {
            $agent = $request->agent;

            $query->whereHas('creator', function ($q) use ($agent) {
                $q->where('name', 'LIKE', "%{$agent}%");
            });
        }
        if ($request->filled('contactSource')) {
            // Expand the selection to every spelling that rolls up to it, so
            // picking "PIXL" returns all its campaigns and not just the rows
            // stored as the literal value. A variant stays exact.
            $values = Constants::leadSourceGroupMembers($request->contactSource);
            count($values) > 1
                ? $query->whereIn('source', $values)
                : $query->where('source', $request->contactSource);
        }
        if ($request->filled('contactType')) {
            // contactType is now a JSON array; match contacts holding ANY requested type.
            $types = \App\Models\Contact::normalizeContactTypes($request->contactType);
            if (! empty($types)) {
                $query->where(function ($q) use ($types) {
                    foreach ($types as $t) {
                        $q->orWhereJsonContains('contactType', $t);
                    }
                });
            }
        }
        if ($request->filled('id')) {
            $query->orderByRaw(
                'CASE WHEN id = ? THEN 0 ELSE 1 END',
                [$request->id]
            );
        }

        return $query;
    }

    public function findById(int $id)
    {
        return Contact::with(['creator', 'latestEnquiry.agent', 'ownedListings.agent', 'mergedContacts', 'mergedInto'])->find($id);
    }

    public function create(array $data)
    {
        return Contact::create($data);
    }

    public function update(int $id, array $data)
    {
        $contact = Contact::find($id);

        if (! $contact) {
            return null;
        }

        $contact->update($data);

        return $contact;
    }

    public function delete(int $id): bool
    {
        $contact = Contact::find($id);

        if (! $contact) {
            return false;
        }

        return $contact->delete();
    }

    public function getContactsByAssignedAgent($agentId, Request $request)
    {
        $perPage = $request->per_page ?? 10;
        $query = Contact::query()->notMerged();
        if (isAgent()) {
            $query->accessibleByAgent(user()->id);
        } else {
            $query->accessibleByAgent($agentId);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('phone', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%");
            });
        }

        return $query->latest()->paginate($perPage);
    }

    public function getEnquiriesByContactId($contactId, $request)
    {
        $contact = Contact::find($contactId);
        if (! $contact) {
            return null;
        }

        $perPage = $request->per_page ?? 10;
        // A master shows the whole family's enquiries. Merging never moved them off
        // the contacts that were merged in, and those contacts are hidden from every
        // listing — so reading this contact's id alone would bury the history the
        // merge was meant to bring together. aliasIds() is just [id] when nothing
        // has been merged in.
        $query = Enquiry::with(['agent'])
            ->whereIn('contactId', $contact->aliasIds());

        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('referenceNo', 'LIKE', "%{$search}%")
                    ->orWhere('phone', 'LIKE', "%{$search}%")
                    ->orWhereRaw('LOWER(status) LIKE ?', ['%'.strtolower($search).'%'])
                    ->orWhere('listingType', 'LIKE', "%{$search}%");
            });
        }

        return $query->orderBy('id', 'desc')->get();
    }

    public function getListingsByOwner($contactId, $request)
    {
        $contact = Contact::find($contactId);
        if (! $contact) {
            return null;
        }
        // Listings owned by this contact or by anything merged into it.
        $query = Property::whereIn('physicalOwnerId', $contact->aliasIds())
            ->with(['creator', 'agent', 'mediaThumbnails']);
        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('title', 'LIKE', "%{$search}%")
                    ->orWhere('listingType', 'LIKE', "%{$search}%")
                    ->orWhere('price', 'LIKE', "%{$search}%");
            });
        }

        return $query->orderBy('id', 'desc')->get();
    }

    public function getOffersByContact($contactId, $request)
    {
        $contact = Contact::find($contactId);
        if (! $contact) {
            return null;
        }
        $query = Offer::whereIn('buyerId', $contact->aliasIds())
            ->with(['buyer', 'buyer.mergedInto', 'property.physicalOwner', 'property.physicalOwner.mergedInto']);
        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(status) LIKE ?', ['%'.strtolower($search).'%'])
                    ->orWhere('offeredAmount', 'LIKE', "%{$search}%");
            });
        }

        return $query->latest()->get();
    }

    public function getClosingsByContact($contactId, $request)
    {
        $contact = Contact::find($contactId);
        if (! $contact) {
            return null;
        }

        $query = Closing::whereIn('buyerId', $contact->aliasIds())
            ->with(['buyer', 'buyer.mergedInto']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(status) LIKE ?', ['%'.strtolower($search).'%'])
                    ->orWhere('listingType', 'LIKE', "%{$search}%")
                    ->orWhere('module', 'LIKE', "%{$search}%");
            });
        }

        return $query->latest()->get();
    }
}
