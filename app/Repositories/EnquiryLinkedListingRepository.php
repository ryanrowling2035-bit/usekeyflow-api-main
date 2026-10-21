<?php

namespace App\Repositories;

use App\Models\EnquiryLinkedListing;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class EnquiryLinkedListingRepository
{
    public function checkIfLinkExists(int $enquiryId, int $propertyId): bool
    {
        return EnquiryLinkedListing::where('enquiryId', $enquiryId)
            ->where('propertyId', $propertyId)
            ->exists();
    }

    public function create(array $data): EnquiryLinkedListing
    {
        $linkedListing = EnquiryLinkedListing::create($data);

        return $linkedListing->load(['enquiry', 'property', 'creator']);
    }

    public function findById(int $id): ?EnquiryLinkedListing
    {
        return EnquiryLinkedListing::find($id);
    }

    public function findByEnquiryAndProperty(int $enquiryId, int $propertyId): ?EnquiryLinkedListing
    {
        return EnquiryLinkedListing::where('enquiryId', $enquiryId)
            ->where('propertyId', $propertyId)
            ->first();
    }

    public function delete(EnquiryLinkedListing $linkedListing): bool
    {
        return $linkedListing->delete();
    }

    public function getLinkedPropertiesByEnquiry(int $enquiryId, Request $request): LengthAwarePaginator
    {
        $query = EnquiryLinkedListing::where('enquiryId', $enquiryId);

        // If not super admin, filter by created by user
        if (isAgent()) {
            $query->where('createdBy', user()->id);
        }

        // Add search functionality
        if ($request->has('search') && ! empty($request->search)) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->whereHas('property', function ($propertyQuery) use ($search) {
                    $propertyQuery->where('title', 'like', "%{$search}%")
                        ->orWhere('community', 'like', "%{$search}%")
                        ->orWhere('subCommunity', 'like', "%{$search}%")
                        ->orWhere('city', 'like', "%{$search}%")
                        ->orWhere('status', 'like', "%{$search}%")
                        ->orWhere('region', 'like', "%{$search}%")
                        ->orWhere('listingType', 'like', "%{$search}%");
                });
            });
        }

        $perPage = $request->input('per_page', 10);

        if ($perPage > 100) {
            $perPage = 100;
        }

        return $query->with([
            'property',
            'property.propertyCategories',
            'property.creator:id,name',
            'creator:id,name,email',
        ])
            ->latest()
            ->paginate($perPage);
    }

    public function getLinkedEnquiriesByProperty(int $propertyId, $request = null)
    {
        $query = EnquiryLinkedListing::where('propertyId', $propertyId);

        // If agent, filter by created by user
        if (isAgent()) {
            $query->where('createdBy', user()->id);
        }

        $query->with([
            'enquiry',
            'enquiry.contact:id,name,email,phone',
            'enquiry.agent:id,name,email',
            'enquiry.creator:id,name',
            'creator:id,name,email',
        ]);

        // Add search functionality
        if ($request && $request->has('search') && ! empty($request->search)) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->whereHas('enquiry', function ($enquiryQuery) use ($search) {
                    $enquiryQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('type', 'like', "%{$search}%")
                        ->orWhere('status', 'like', "%{$search}%")
                        ->orWhere('requirement', 'like', "%{$search}%")
                        ->orWhereHas('contact', function ($contactQuery) use ($search) {
                            $contactQuery->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%");
                        })
                        ->orWhereHas('agent', function ($agentQuery) use ($search) {
                            $agentQuery->where('name', 'like', "%{$search}%");
                        });
                });
            });
        }

        $query->latest();

        // Always paginate so a property linked to thousands of enquiries can't load
        // the whole set into memory. Defaults to 100/page when no per_page is given.
        $perPage = $request ? $request->input('per_page', 100) : 100;

        if ($perPage > 100) {
            $perPage = 100;
        }

        return $query->paginate($perPage);
    }

    /**
     * @deprecated The enquiry/{enquiryId}/linkedProperties endpoint no longer
     * reads from the enquiry_linked_listings join table. Matching properties
     * are now resolved from the enquiry's stored criteria via
     * EnquiryLinkedListingService::getMatchingPropertiesForEnquiry. Kept for
     * reference only.
     */
    public function getAllLinkedPropertiesByEnquiry(int $enquiryId, Request $request): LengthAwarePaginator
    {
        $query = EnquiryLinkedListing::where('enquiryId', $enquiryId);

        $query->with([
            'property',
            'property.propertyCategories',
            'property.community',
            'property.subCommunity',
            'property.buildingTower',
            'property.city',
            'property.mediaThumbnails',
            'property.creator:id,name',
            'creator:id,name,email',
        ]);

        if ($request->has('search') && ! empty($request->search)) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->whereHas('property', function ($propertyQuery) use ($search) {
                    $propertyQuery->where('title', 'like', "%{$search}%")
                        ->orWhere('referenceNumber', 'like', "%{$search}%")
                        ->orWhere('listingType', 'like', "%{$search}%")
                        ->orWhereRaw('LOWER(status) LIKE ?', ['%'.strtolower($search).'%']);
                });
            });
        }

        $perPage = $request->input('per_page', 10);

        if ($perPage > 100) {
            $perPage = 100;
        }

        return $query->latest()->paginate($perPage);
    }

    public function getAllLinkedEnquiriesByProperty(int $propertyId, $request = null)
    {
        $query = EnquiryLinkedListing::where('propertyId', $propertyId);

        $query->with([
            'enquiry',
            'enquiry.contact:id,name,email,phone,nationality,residencyStatus',
            'enquiry.agent:id,name,email',
            'enquiry.creator:id,name',
            'creator:id,name,email',
        ]);

        if ($request && $request->has('search') && ! empty($request->search)) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->whereHas('enquiry', function ($enquiryQuery) use ($search) {
                    $enquiryQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('type', 'like', "%{$search}%")
                        ->orWhere('status', 'like', "%{$search}%")
                        ->orWhere('requirement', 'like', "%{$search}%")
                        ->orWhereHas('contact', function ($contactQuery) use ($search) {
                            $contactQuery->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%");
                        })
                        ->orWhereHas('agent', function ($agentQuery) use ($search) {
                            $agentQuery->where('name', 'like', "%{$search}%");
                        });
                });
            });
        }

        $query->latest();

        // Always paginate (default 100/page) instead of loading every linked row.
        $perPage = $request ? $request->input('per_page', 100) : 100;

        if ($perPage > 100) {
            $perPage = 100;
        }

        return $query->paginate($perPage);
    }
}
