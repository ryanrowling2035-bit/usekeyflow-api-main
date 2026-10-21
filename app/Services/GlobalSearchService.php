<?php

namespace App\Services;

use App\Constants\Constants;
use App\Models\Closing;
use App\Models\Contact;
use App\Models\Enquiry;
use App\Models\MarketingAsset;
use App\Models\Offer;
use App\Models\Property;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;

class GlobalSearchService
{
    public function search(Request $request): array
    {
        $search = $request->input('search', '');
        $limit = (int) $request->input('limit', 5);

        if (empty(trim($search))) {
            return $this->emptyResults();
        }

        $term = trim($search);

        return [
            'users' => $this->searchUsers($term, $limit),
            'teams' => $this->searchTeams($term, $limit),
            'contacts' => $this->searchContacts($term, $limit),
            'listings' => $this->searchListings($term, $limit),
            'enquiries' => $this->searchEnquiries($term, $limit),
            'offers' => $this->searchOffers($term, $limit),
            'closings' => $this->searchClosings($term, $limit),
            'marketingAssets' => $this->searchMarketingAssets($term, $limit),
        ];
    }

    private function searchUsers(string $term, int $limit): array
    {
        $query = User::where(function ($q) use ($term) {
            $q->where('name', 'LIKE', "%{$term}%")
                ->orWhere('email', 'LIKE', "%{$term}%")
                ->orWhere('phone', 'LIKE', "%{$term}%")
                ->orWhere('userName', 'LIKE', "%{$term}%")
                ->orWhere('agentCode', 'LIKE', "%{$term}%");
        });

        $results = (clone $query)
            ->select('id', 'name', 'status', 'agentCode', 'createdBy', 'created_at', 'updated_at')
            ->with('creator:id,name')
            ->limit($limit)
            ->get()
            ->map(fn ($user) => [
                'id' => $user->id,
                'displayName' => $user->agentCode
                    ? '#'.$user->agentCode.' - '.$user->name
                    : $user->name,
                'statusLabel' => Constants::USER_STATUS[$user->status] ?? null,
                'created_at' => convertToTimezone($user->created_at),
                'updated_at' => convertToTimezone($user->updated_at),
                'creator' => $user->creator ? ['id' => $user->creator->id, 'name' => $user->creator->name] : null,
            ]);

        return [
            'data' => $results,
            'total' => $query->count(),
        ];
    }

    private function searchTeams(string $term, int $limit): array
    {
        $query = Team::where(function ($q) use ($term) {
            $q->where('name', 'LIKE', "%{$term}%")
                ->orWhere('description', 'LIKE', "%{$term}%");
        });

        $results = (clone $query)
            ->select('id', 'name', 'status', 'created_at', 'updated_at')
            ->limit($limit)
            ->get()
            ->map(fn ($team) => [
                'id' => $team->id,
                'displayName' => $team->name,
                'statusLabel' => $team->status == 1 ? 'Active' : 'Inactive',
                'created_at' => convertToTimezone($team->created_at),
                'updated_at' => convertToTimezone($team->updated_at),
                'creator' => null,
            ]);

        return [
            'data' => $results,
            'total' => $query->count(),
        ];
    }

    private function searchContacts(string $term, int $limit): array
    {
        // Searching a merged-away contact's name or number should surface the
        // surviving contact, not the alias — so aliases are excluded here and the
        // master matches on its own details.
        $query = Contact::notMerged()->where(function ($q) use ($term) {
            $q->where('name', 'LIKE', "%{$term}%")
                ->orWhere('phone', 'LIKE', "%{$term}%")
                ->orWhere('alternatePhone', 'LIKE', "%{$term}%")
                ->orWhere('email', 'LIKE', "%{$term}%")
                ->orWhere('address', 'LIKE', "%{$term}%");
        });

        $results = (clone $query)
            ->select('id', 'name', 'createdBy', 'created_at', 'updated_at')
            ->with('creator:id,name')
            ->limit($limit)
            ->get()
            ->map(fn ($contact) => [
                'id' => $contact->id,
                'displayName' => $contact->name,
                'statusLabel' => null,
                'created_at' => convertToTimezone($contact->created_at),
                'updated_at' => convertToTimezone($contact->updated_at),
                'creator' => $contact->creator ? ['id' => $contact->creator->id, 'name' => $contact->creator->name] : null,
            ]);

        return [
            'data' => $results,
            'total' => $query->count(),
        ];
    }

    private function searchListings(string $term, int $limit): array
    {
        $query = Property::where(function ($q) use ($term) {
            $q->where('title', 'LIKE', "%{$term}%")
                ->orWhere('referenceNumber', 'LIKE', "%{$term}%")
                ->orWhere('description', 'LIKE', "%{$term}%")
                ->orWhere('pfAmenities', 'LIKE', "%{$term}%")
                ->orWhere('bayutAmenities', 'LIKE', "%{$term}%")
                ->orWhere('reraPermitNo', 'LIKE', "%{$term}%")
                ->orWhereHas('city', fn ($q) => $q->where('name', 'LIKE', "%{$term}%"))
                ->orWhereHas('community', fn ($q) => $q->where('name', 'LIKE', "%{$term}%"))
                ->orWhereHas('subCommunity', fn ($q) => $q->where('name', 'LIKE', "%{$term}%"))
                ->orWhereHas('buildingTower', fn ($q) => $q->where('name', 'LIKE', "%{$term}%"));
        });

        $results = (clone $query)
            ->select('id', 'title', 'status', 'referenceNumber', 'reraPermitNo', 'createdBy', 'created_at', 'updated_at')
            ->with('creator:id,name')
            ->limit($limit)
            ->get()
            ->map(fn ($property) => [
                'id' => $property->id,
                'displayName' => $property->referenceNumber
                    ? '#'.$property->referenceNumber.' - '.$property->title
                    : $property->title,
                'statusLabel' => Constants::PROPERTY_STATUS[$property->status] ?? null,
                'reraPermitNo' => $property->reraPermitNo,
                'created_at' => convertToTimezone($property->created_at),
                'updated_at' => convertToTimezone($property->updated_at),
                'creator' => $property->creator ? ['id' => $property->creator->id, 'name' => $property->creator->name] : null,
            ]);

        return [
            'data' => $results,
            'total' => $query->count(),
        ];
    }

    private function searchEnquiries(string $term, int $limit): array
    {
        $query = Enquiry::where(function ($q) use ($term) {
            $q->where('referenceNo', 'LIKE', "%{$term}%")
                ->orWhere('phone', 'LIKE', "%{$term}%")
                ->orWhere('comments', 'LIKE', "%{$term}%")
                ->orWhere('enquiryType', 'LIKE', "%{$term}%")
                ->orWhere('leadSource', 'LIKE', "%{$term}%")
                ->orWhereHas('contact', fn ($q) => $q->where('name', 'LIKE', "%{$term}%")->orWhere('email', 'LIKE', "%{$term}%"))
                ->orWhereHas('agent', fn ($q) => $q->where('name', 'LIKE', "%{$term}%"));
        });

        $results = (clone $query)
            ->select('id', 'referenceNo', 'status', 'contactId', 'createdBy', 'created_at', 'updated_at')
            ->with(['contact:id,name', 'creator:id,name'])
            ->limit($limit)
            ->get()
            ->map(fn ($enquiry) => [
                'id' => $enquiry->id,
                'displayName' => $enquiry->referenceNo
                    ? '#'.$enquiry->referenceNo.' - '.($enquiry->contact?->masterOrSelf()->name ?? '')
                    : ($enquiry->contact?->masterOrSelf()->name ?? ''),
                'statusLabel' => Constants::ENQUIRY_STATUS[$enquiry->status] ?? null,
                'created_at' => convertToTimezone($enquiry->created_at),
                'updated_at' => convertToTimezone($enquiry->updated_at),
                'creator' => $enquiry->creator ? ['id' => $enquiry->creator->id, 'name' => $enquiry->creator->name] : null,
            ]);

        return [
            'data' => $results,
            'total' => $query->count(),
        ];
    }

    private function searchOffers(string $term, int $limit): array
    {
        $query = Offer::where(function ($q) use ($term) {
            $q->where('name', 'LIKE', "%{$term}%")
                ->orWhere('referenceNo', 'LIKE', "%{$term}%")
                ->orWhere('status', 'LIKE', "%{$term}%")
                ->orWhere('listingType', 'LIKE', "%{$term}%")
                ->orWhere('terms', 'LIKE', "%{$term}%")
                ->orWhereHas('buyer', fn ($q) => $q->where('name', 'LIKE', "%{$term}%")->orWhere('email', 'LIKE', "%{$term}%"))
                ->orWhereHas('property', fn ($q) => $q->where('title', 'LIKE', "%{$term}%")->orWhere('referenceNumber', 'LIKE', "%{$term}%"))
                ->orWhereHas('assignedAgent', fn ($q) => $q->where('name', 'LIKE', "%{$term}%"));
        });

        $results = (clone $query)
            ->select('id', 'name', 'referenceNo', 'status', 'createdBy', 'created_at', 'updated_at')
            ->with('creator:id,name')
            ->limit($limit)
            ->get()
            ->map(fn ($offer) => [
                'id' => $offer->id,
                'displayName' => $offer->referenceNo
                    ? '#'.$offer->referenceNo.' - '.$offer->name
                    : $offer->name,
                'statusLabel' => Constants::OFFER_STATUS[$offer->status] ?? null,
                'created_at' => convertToTimezone($offer->created_at),
                'updated_at' => convertToTimezone($offer->updated_at),
                'creator' => $offer->creator ? ['id' => $offer->creator->id, 'name' => $offer->creator->name] : null,
            ]);

        return [
            'data' => $results,
            'total' => $query->count(),
        ];
    }

    private function searchClosings(string $term, int $limit): array
    {
        $query = Closing::where(function ($q) use ($term) {
            $q->where('name', 'LIKE', "%{$term}%")
                ->orWhere('referenceNo', 'LIKE', "%{$term}%")
                ->orWhere('status', 'LIKE', "%{$term}%")
                ->orWhere('listingType', 'LIKE', "%{$term}%")
                ->orWhere('clientLeadSource', 'LIKE', "%{$term}%")
                ->orWhereHas('buyer', fn ($q) => $q->where('name', 'LIKE', "%{$term}%")->orWhere('email', 'LIKE', "%{$term}%"))
                ->orWhereHas('property', fn ($q) => $q->where('title', 'LIKE', "%{$term}%")->orWhere('referenceNumber', 'LIKE', "%{$term}%"))
                ->orWhereHas('assignedAgent', fn ($q) => $q->where('name', 'LIKE', "%{$term}%"));
        });

        $results = (clone $query)
            ->select('id', 'name', 'status', 'referenceNo', 'createdBy', 'created_at', 'updated_at')
            ->with('creator:id,name')
            ->limit($limit)
            ->get()
            ->map(fn ($closing) => [
                'id' => $closing->id,
                'displayName' => $closing->name,
                'statusLabel' => null,
                'created_at' => convertToTimezone($closing->created_at),
                'updated_at' => convertToTimezone($closing->updated_at),
                'creator' => $closing->creator ? ['id' => $closing->creator->id, 'name' => $closing->creator->name] : null,
            ]);

        return [
            'data' => $results,
            'total' => $query->count(),
        ];
    }

    private function searchMarketingAssets(string $term, int $limit): array
    {
        $query = MarketingAsset::where(function ($q) use ($term) {
            $q->where('assetName', 'LIKE', "%{$term}%")
                ->orWhere('category', 'LIKE', "%{$term}%")
                ->orWhere('assetType', 'LIKE', "%{$term}%")
                ->orWhere('description', 'LIKE', "%{$term}%");
        });

        $results = (clone $query)
            ->select('id', 'assetName', 'status', 'uploadedBy', 'created_at', 'updated_at')
            ->with('uploader:id,name')
            ->limit($limit)
            ->get()
            ->map(fn ($asset) => [
                'id' => $asset->id,
                'displayName' => $asset->assetName,
                'statusLabel' => Constants::MARKETING_ASSET_STATUS[$asset->status] ?? null,
                'created_at' => convertToTimezone($asset->created_at),
                'updated_at' => convertToTimezone($asset->updated_at),
                'creator' => $asset->uploader ? ['id' => $asset->uploader->id, 'name' => $asset->uploader->name] : null,
            ]);

        return [
            'data' => $results,
            'total' => $query->count(),
        ];
    }

    private function emptyResults(): array
    {
        return [
            'users' => ['data' => [], 'total' => 0],
            'teams' => ['data' => [], 'total' => 0],
            'contacts' => ['data' => [], 'total' => 0],
            'listings' => ['data' => [], 'total' => 0],
            'enquiries' => ['data' => [], 'total' => 0],
            'offers' => ['data' => [], 'total' => 0],
            'closings' => ['data' => [], 'total' => 0],
            'marketingAssets' => ['data' => [], 'total' => 0],
        ];
    }
}
