<?php

namespace App\Repositories;

use App\Constants\Constants;
use App\Models\AssignTeamMember;
use App\Models\Boost;

class BoostRepository
{
    public function getAll($request)
    {
        // Eager-load the listing, its agent and the agent's team(s) so the
        // resource can expose Ref No / status / listingType / agent / team
        // without N+1 queries.
        $query = Boost::query()->with(['property.agent.teams', 'creator', 'approver']);

        // portal=propertyFinder | bayut — the same camelCase keys used for portal
        // settings and lead sources, rather than the boost column names.
        if ($request->filled('portal')) {
            if ($request->portal === 'propertyFinder') {
                $query->where('isPropertyFinder', 1);
            }
            if ($request->portal === 'bayut') {
                $query->where('isBayut', 1);
            }
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('propertyId')) {
            $query->where('propertyId', $request->propertyId);
        }

        // Search matches the listing's name, reference number and the listing
        // agent's name (admins search boosts by agent).
        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('property', function ($q) use ($search) {
                $q->where('title', 'LIKE', "%{$search}%")
                    ->orWhere('referenceNumber', 'LIKE', "%{$search}%")
                    ->orWhereHas('agent', fn ($a) => $a->where('name', 'LIKE', "%{$search}%"));
            });
        }

        // listingScope filters the boosted listing, mirroring the `listing` filter in
        // PropertyRepository so both screens mean the same thing by the same option:
        //   published         → All Active Listings  (status = published)
        //   myActiveListings  → My Active Listings   (published + created by me)
        //   rentSold          → My Sold Listings     (status = rentSold)
        //   all               → All Listings         (no filter)
        if ($request->filled('listingScope')) {
            $userId = user()->id ?? null;

            if ($request->listingScope === 'published') {
                $query->whereHas('property', fn ($q) => $q->where('status', Constants::PROPERTY_STATUS_PUBLISHED));
            }
            if ($request->listingScope === 'myActiveListings' && $userId) {
                $query->whereHas('property', fn ($q) => $q->where('agentId', $userId)
                    ->where('status', Constants::PROPERTY_STATUS_PUBLISHED));
            }
            if ($request->listingScope === 'rentSold') {
                $query->whereHas('property', fn ($q) => $q->where('status', Constants::PROPERTY_STATUS_RENTED_SOLD));
            }
            if ($request->listingScope === 'active') {
                $query->whereHas('property', function ($q) {
                    $q->where('status', Constants::PROPERTY_STATUS_PUBLISHED);
                });
            }
        }

        // Exact status of the boosted listing (upcoming, published, rentSold, …).
        if ($request->filled('listingStatus')) {
            $query->whereHas('property', fn ($q) => $q->where('status', $request->listingStatus));
        }

        if ($request->filled('listingType')) {
            $query->whereHas('property', fn ($q) => $q->where('listingType', $request->listingType));
        }

        // Agent = the listing's agent.
        if ($request->filled('agentId')) {
            $query->whereHas('property', fn ($q) => $q->where('agentId', $request->agentId));
        }

        // Team = listings whose agent belongs to the given team.
        if ($request->filled('teamId')) {
            $memberIds = AssignTeamMember::where('teamId', $request->teamId)->pluck('userId')->all();
            $query->whereHas('property', fn ($q) => $q->whereIn('agentId', $memberIds ?: [0]));
        }

        // Date range filters the boost's own created_at (UTC-day boundaries).
        if ($request->filled('startDate')) {
            $query->where('created_at', '>=', localDayStartUtc($request->startDate));
        }
        if ($request->filled('endDate')) {
            $query->where('created_at', '<=', localDayEndUtc($request->endDate));
        }

        $perPage = $request->filled('per_page') && $request->per_page > 0
            ? $request->per_page
            : 10;

        return $query->latest()->paginate($perPage);
    }

    public function findById($id)
    {
        return Boost::find($id);
    }

    public function create(array $data)
    {
        return Boost::create($data);
    }

    public function update($id, array $data)
    {
        $boost = $this->findById($id);
        if ($boost) {
            $boost->update($data);
        }

        return $boost;
    }

    public function delete($id)
    {
        $boost = $this->findById($id);
        if ($boost) {
            $boost->delete();
        }

        return $boost;
    }

    public function getByPropertyId($propertyId)
    {
        return Boost::with(['property', 'creator'])->where('propertyId', $propertyId)->get();
    }
}
