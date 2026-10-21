<?php

namespace App\Services;

use App\Models\Contact;
use App\Repositories\ContactRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContactService
{
    public function __construct(
        protected ContactRepository $contactRepository,
        protected ContactRepository $enquiryRepository,
        protected FileStorageService $fileStorage
    ) {}

    public function getAll(Request $request)
    {
        return $this->contactRepository->getAll($request);
    }

    /**
     * Filtered contacts query (unpaginated) for streaming CSV export.
     */
    public function exportQuery(Request $request)
    {
        return $this->contactRepository->exportQuery($request);
    }

    public function getById(int $id)
    {
        return $this->contactRepository->findById($id);
    }

    public function create(Request $request, $data)
    {
        if ($request->hasFile('contactProfile')) {
            $file = $request->file('contactProfile');
            $originalName = round(microtime(true) * 1000).'-'.str_replace(' ', '', $file->getClientOriginalName());
            $data['contactProfile'] = $this->fileStorage->uploadPublicFile($file, 'contacts', $originalName);
        }

        $data['source'] = $data['source'] ?? 'Manual';

        $data = normalizePhoneFields($data, ['phone', 'alternatePhone', 'otherPhone']);

        return $this->contactRepository->create($data);
    }

    public function update(Request $request, $id, $data)
    {

        $contact = $this->contactRepository->findById($id);

        if (! empty($data['removeContactProfile'])) {
            $this->fileStorage->deletePublicByUrl($data['removeContactProfile']);
            $data['contactProfile'] = null;
            unset($data['removeContactProfile']);
        }

        if ($request->hasFile('contactProfile')) {
            if ($contact && $contact->contactProfile) {
                $this->fileStorage->deletePublicByUrl($contact->contactProfile);
            }

            $file = $request->file('contactProfile');
            $originalName = round(microtime(true) * 1000).'-'.str_replace(' ', '', $file->getClientOriginalName());
            $data['contactProfile'] = $this->fileStorage->uploadPublicFile($file, 'contacts', $originalName);
        }

        $data = normalizePhoneFields($data, ['phone', 'alternatePhone', 'otherPhone']);

        return $this->contactRepository->update($id, $data);
    }

    public function delete(int $id): bool
    {
        return $this->contactRepository->delete($id);
    }

    /**
     * Merge contacts into a master.
     *
     * Nothing is copied and nothing is moved. Each merged contact keeps its own
     * name, phone and email, and keeps its enquiries, listings, offers, closings
     * and notes exactly where they are. It is only flagged as an alias of the
     * master, which hides it wherever a contact is browsed or picked, rolls its
     * records up under the master, and makes anything referencing it display the
     * master instead.
     *
     * That is also why the undo is trivial: revertMerge() clears the flag and
     * every record is already where it started.
     *
     * @param  array<int>  $mergeIds  contacts to become aliases of the master
     *
     * @throws \InvalidArgumentException when the merge would be ambiguous
     */
    public function merge(int $masterId, array $mergeIds, int $actorId): Contact
    {
        $master = Contact::find($masterId);

        if (! $master) {
            throw new \InvalidArgumentException('The contact to merge into was not found.');
        }

        // An alias resolves to its master in a single hop, so a master may never
        // itself be an alias — that would leave records pointing at a contact that
        // resolves to a hidden one.
        if ($master->isMergedAway()) {
            throw new \InvalidArgumentException('That contact has itself been merged into another contact. Merge into the surviving contact instead.');
        }

        $mergeIds = array_values(array_unique(array_map('intval', $mergeIds)));

        if (in_array($masterId, $mergeIds, true)) {
            throw new \InvalidArgumentException('A contact cannot be merged into itself.');
        }

        $duplicates = Contact::whereIn('id', $mergeIds)->get();

        if ($duplicates->count() !== count($mergeIds)) {
            throw new \InvalidArgumentException('One or more of the selected contacts were not found.');
        }

        foreach ($duplicates as $duplicate) {
            if ($duplicate->isMergedAway()) {
                throw new \InvalidArgumentException("\"{$duplicate->name}\" is already merged into another contact.");
            }

            // Same one-hop rule from the other side: merging a contact that already
            // has aliases would strand those aliases behind a hidden record.
            if ($duplicate->mergedContacts()->exists()) {
                throw new \InvalidArgumentException("\"{$duplicate->name}\" has other contacts merged into it. Revert those first.");
            }
        }

        DB::transaction(function () use ($duplicates, $masterId, $actorId) {
            foreach ($duplicates as $duplicate) {
                $duplicate->update([
                    'mergedIntoId' => $masterId,
                    'mergedBy' => $actorId,
                    'mergedAt' => now(),
                    // A contact merged, reverted, then merged again reads as merged:
                    // revertedAt is the state marker, so it has to be cleared.
                    'revertedBy' => null,
                    'revertedAt' => null,
                ]);
            }
        });

        return $master->load('mergedContacts');
    }

    /**
     * Undo a merge: the given aliases become ordinary contacts again, visible
     * everywhere they were before. Since merging moved nothing, there is nothing
     * to put back.
     *
     * The merge columns are cleared outright — mergedIntoId, mergedBy and mergedAt
     * all go back to null, so the row looks exactly as it did before it was ever
     * merged. revertedBy and revertedAt are stamped instead and are the only trace
     * left: who unmerged it and when. Which contact it had been merged into is not
     * kept; the user activity log records that.
     *
     * @param  array<int>  $contactIds  the aliases to unmerge
     * @return Collection<int, Contact> the contacts restored
     *
     * @throws \InvalidArgumentException when a contact is not currently merged away
     */
    public function revertMerge(array $contactIds, int $actorId): Collection
    {
        $contactIds = array_values(array_unique(array_map('intval', $contactIds)));
        $contacts = Contact::whereIn('id', $contactIds)->get();

        if ($contacts->count() !== count($contactIds)) {
            throw new \InvalidArgumentException('One or more of the selected contacts were not found.');
        }

        foreach ($contacts as $contact) {
            if (! $contact->isMergedAway()) {
                throw new \InvalidArgumentException("\"{$contact->name}\" is not currently merged into another contact.");
            }
        }

        DB::transaction(function () use ($contacts, $actorId) {
            foreach ($contacts as $contact) {
                $contact->update([
                    'mergedIntoId' => null,
                    'mergedBy' => null,
                    'mergedAt' => null,
                    'revertedBy' => $actorId,
                    'revertedAt' => now(),
                ]);
            }
        });

        return $contacts;
    }

    public function syncBuyerFields(?int $contactId, array $data): void
    {
        if (! $contactId) {
            return;
        }

        $updates = array_intersect_key($data, array_flip(['dob', 'nationality', 'residencyStatus']));
        if (empty($updates)) {
            return;
        }

        $this->contactRepository->update($contactId, $updates);
    }

    public function getContactsByAssignedAgent($agentId, Request $request)
    {
        return $this->contactRepository->getContactsByAssignedAgent($agentId, $request);
    }

    public function getLinkedEnquiriesByContactId($contactId, $request)
    {
        return $this->enquiryRepository->getEnquiriesByContactId($contactId, $request);
    }

    public function getListingsByOwner($contactId, $request)
    {
        return $this->contactRepository->getListingsByOwner($contactId, $request);
    }

    public function getOffersByContact($contactId, $request)
    {
        return $this->contactRepository->getOffersByContact($contactId, $request);
    }

    public function getClosingsByContact($contactId, $request)
    {
        return $this->contactRepository->getClosingsByContact($contactId, $request);
    }
}
