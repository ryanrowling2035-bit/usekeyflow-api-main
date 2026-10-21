<?php

namespace App\Repositories;

use App\Models\DailyEnquiryEmail;
use App\Models\DailyListingEmail;

class DailyMatchingLogRepository
{
    public function hasBeenSentListing(int $agentId, int $propertyId, ?int $enquiryId): bool
    {
        $query = DailyListingEmail::query()
            ->where('agent_id', $agentId)
            ->where('property_id', $propertyId);

        if ($enquiryId === null) {
            $query->whereNull('enquiry_id');
        } else {
            $query->where('enquiry_id', $enquiryId);
        }

        return $query->exists();
    }

    public function recordListingSent(int $agentId, int $propertyId, ?int $enquiryId): void
    {
        DailyListingEmail::firstOrCreate([
            'agent_id' => $agentId,
            'property_id' => $propertyId,
            'enquiry_id' => $enquiryId,
        ]);
    }

    public function hasBeenSentEnquiry(int $agentId, int $enquiryId, ?int $propertyId): bool
    {
        $query = DailyEnquiryEmail::query()
            ->where('agent_id', $agentId)
            ->where('enquiry_id', $enquiryId);

        if ($propertyId === null) {
            $query->whereNull('property_id');
        } else {
            $query->where('property_id', $propertyId);
        }

        return $query->exists();
    }

    public function recordEnquirySent(int $agentId, int $enquiryId, ?int $propertyId): void
    {
        DailyEnquiryEmail::firstOrCreate([
            'agent_id' => $agentId,
            'enquiry_id' => $enquiryId,
            'property_id' => $propertyId,
        ]);
    }
}
