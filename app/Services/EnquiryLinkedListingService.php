<?php

namespace App\Services;

use App\Models\Enquiry;
use App\Models\EnquiryLinkedListing;
use App\Models\Property;
use App\Repositories\EnquiryLinkedListingRepository;
use App\Repositories\EnquiryRepository;
use App\Repositories\PropertyRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class EnquiryLinkedListingService
{
    public function __construct(
        protected EnquiryLinkedListingRepository $enquiryLinkedListingRepository,
        protected PropertyRepository $propertyRepository,
        protected EnquiryRepository $enquiryRepository
    ) {}

    public function linkPropertiesToEnquiry(array $data): array
    {
        $enquiryId = $data['enquiryId'];
        $propertyIds = $data['propertyId'];
        $createdBy = $data['createdBy'];

        $linked = [];

        foreach ($propertyIds as $propertyId) {
            $exists = $this->enquiryLinkedListingRepository->checkIfLinkExists(
                $enquiryId,
                $propertyId
            );

            if ($exists) {
                continue;
            }

            $linkedListing = $this->enquiryLinkedListingRepository->create([
                'enquiryId' => $enquiryId,
                'propertyId' => $propertyId,
                'createdBy' => $createdBy,
            ]);

            $linked[] = $linkedListing;
        }

        return $linked;
    }

    public function unlinkPropertiesFromEnquiry(int $enquiryId, array $propertyIds): array
    {
        $unlinkedCount = 0;
        $notLinked = [];

        foreach ($propertyIds as $propertyId) {
            $linkedListing = $this->enquiryLinkedListingRepository->findByEnquiryAndProperty(
                $enquiryId,
                $propertyId
            );

            if (! $linkedListing) {
                $notLinked[] = $propertyId;

                continue;
            }

            $this->enquiryLinkedListingRepository->delete($linkedListing);
            $unlinkedCount++;
        }

        return [
            'unlinked_count' => $unlinkedCount,
            'not_linked' => $notLinked,
        ];
    }

    public function getLinkedPropertiesByEnquiry(int $enquiryId, Request $request): LengthAwarePaginator
    {
        return $this->enquiryLinkedListingRepository->getLinkedPropertiesByEnquiry($enquiryId, $request);
    }

    public function linkEnquiriesToProperty(array $data): array
    {
        $propertyId = $data['propertyId'];
        $enquiryIds = $data['enquiryId'];
        $createdBy = $data['createdBy'];

        $linked = [];

        foreach ($enquiryIds as $enquiryId) {
            $exists = $this->enquiryLinkedListingRepository->checkIfLinkExists(
                $enquiryId,
                $propertyId
            );

            if ($exists) {
                continue;
            }

            $linkedListing = $this->enquiryLinkedListingRepository->create([
                'enquiryId' => $enquiryId,
                'propertyId' => $propertyId,
                'createdBy' => $createdBy,
            ]);

            $linked[] = $linkedListing;
        }

        return $linked;
    }

    public function unlinkEnquiriesFromProperty(int $propertyId, array $enquiryIds): array
    {
        $unlinkedCount = 0;
        $notLinked = [];

        foreach ($enquiryIds as $enquiryId) {
            $linkedListing = $this->enquiryLinkedListingRepository->findByEnquiryAndProperty(
                $enquiryId,
                $propertyId
            );

            if (! $linkedListing) {
                $notLinked[] = $enquiryId;

                continue;
            }

            $this->enquiryLinkedListingRepository->delete($linkedListing);
            $unlinkedCount++;
        }

        return [
            'unlinked_count' => $unlinkedCount,
            'not_linked' => $notLinked,
        ];
    }

    public function getLinkedEnquiriesByProperty(int $propertyId, $request = null)
    {
        return $this->enquiryLinkedListingRepository->getLinkedEnquiriesByProperty($propertyId, $request);
    }

    /**
     * enquiry/{enquiryId}/linkedProperties — properties matching the enquiry's stored
     * criteria. Returns the same paginator/shape as enquiry/{id}/matchingProperties;
     * filters come from the enquiry's stored fields rather than Request params.
     */
    public function getMatchingPropertiesForEnquiry(int $enquiryId, Request $request): LengthAwarePaginator
    {
        $enquiry = Enquiry::findOrFail($enquiryId);

        $paginator = $this->propertyRepository->matchActiveListingsForEnquiry($enquiry)
            ->with(['mediaThumbnails', 'propertyCategories', 'community', 'subCommunity', 'buildingTower', 'city', 'agent', 'creator', 'meta'])
            ->paginate($this->resolvePerPage($request));

        $linkedIds = EnquiryLinkedListing::where('enquiryId', $enquiryId)
            ->whereIn('propertyId', $paginator->getCollection()->pluck('id'))
            ->pluck('propertyId')
            ->all();

        $paginator->getCollection()->each(function ($property) use ($linkedIds) {
            $property->flyerSent = in_array($property->id, $linkedIds);
            // Raw model output (no resource) — strip trailing ".0" from the decimal bathrooms column.
            $property->bathrooms = formatDecimal($property->bathrooms);
        });

        return $paginator;
    }

    /**
     * properties/{propertyId}/linkedEnquiries — enquiries matching the property's stored
     * criteria. Returns the same paginator/shape as properties/{id}/matchingEnquiries;
     * filters come from the property's stored fields rather than Request params.
     */
    public function getAllLinkedEnquiriesByProperty(int $propertyId, Request $request): LengthAwarePaginator
    {
        $property = Property::findOrFail($propertyId);

        return $this->enquiryRepository->matchActiveEnquiriesForProperty($property)
            ->with(['agent:id,name', 'contact:id,name,phone,email', 'listing:id,title,referenceNumber', 'creator:id,name'])
            ->paginate($this->resolvePerPage($request));
    }

    private function resolvePerPage(Request $request): int
    {
        $perPage = (int) $request->input('per_page', 10);
        if ($perPage <= 0) {
            return 10;
        }

        return min($perPage, 100);
    }
}
