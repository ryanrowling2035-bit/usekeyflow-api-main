<?php

namespace App\Http\Resources\V1;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DealResource extends JsonResource
{
    /**
     * Transform the deal resource into a snake_case array with ISO 8601 UTC dates.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference_no' => $this->referenceNo,
            'name' => $this->name,
            'status' => $this->status,
            'listing_type' => $this->listingType,
            'sale_rental_price' => $this->saleRentalPrice !== null ? (float) $this->saleRentalPrice : null,
            'closing_probability' => $this->closingProbability !== null ? (int) $this->closingProbability : null,
            'commission_details' => [
                'total_commission' => $this->totalCommission !== null ? (float) $this->totalCommission : null,
                'buyer_commission' => $this->buyerCommission !== null ? (float) $this->buyerCommission : null,
                'seller_commission' => $this->sellerCommission !== null ? (float) $this->sellerCommission : null,
                'agent_commission' => $this->agentCommission !== null ? (float) $this->agentCommission : null,
                'company_commission' => $this->companyCommission !== null ? (float) $this->companyCommission : null,
                'seller_agent_split_percentage' => $this->sellerAgentSplitPercentage !== null ? (float) $this->sellerAgentSplitPercentage : null,
                'seller_agent_split_amount' => $this->sellerAgentSplitAmount !== null ? (float) $this->sellerAgentSplitAmount : null,
                'buyer_agent_split_percentage' => $this->buyerAgentSplitPercentage !== null ? (float) $this->buyerAgentSplitPercentage : null,
                'buyer_agent_split_amount' => $this->buyerAgentSplitAmount !== null ? (float) $this->buyerAgentSplitAmount : null,
            ],
            'client_lead_source' => $this->clientLeadSource,
            'client_nationality' => $this->clientNationality,
            'target_close_date' => $this->toIsoUtc($this->targetCloseDate),
            'closing_date' => $this->toIsoUtc($this->closingDate),
            'agent' => $this->whenLoaded('assignedAgent', function () {
                if (! $this->assignedAgent) {
                    return null;
                }

                return [
                    'id' => $this->assignedAgent->id,
                    'name' => $this->assignedAgent->name,
                    'email' => $this->assignedAgent->email,
                    'phone' => $this->assignedAgent->phone,
                    'agent_code' => $this->assignedAgent->agentCode,
                ];
            }),
            'buyer' => $this->whenLoaded('buyer', function () {
                if (! $this->buyer) {
                    return null;
                }

                return [
                    'id' => $this->buyer->id,
                    'name' => $this->buyer->name,
                    'email' => $this->buyer->email,
                    'phone' => $this->buyer->phone,
                ];
            }),
            'listing' => $this->whenLoaded('property', function () {
                if (! $this->property) {
                    return null;
                }

                return [
                    'id' => $this->property->id,
                    'reference_number' => $this->property->referenceNumber,
                    'title' => $this->property->title,
                    'price' => $this->property->price,
                    'listing_type' => $this->property->listingType,
                ];
            }),
            'lead' => $this->whenLoaded('enquiry', function () {
                if (! $this->enquiry) {
                    return null;
                }

                $extras = is_string($this->enquiry->extras) ? json_decode($this->enquiry->extras, true) : (is_array($this->enquiry->extras) ? $this->enquiry->extras : []);

                return [
                    'id' => $this->enquiry->id,
                    'reference_no' => $this->enquiry->referenceNo,
                    'status' => $this->enquiry->status,
                    'marketing_os_lead_id' => $extras['marketing_os_lead_id'] ?? ($extras['mid'] ?? null),
                    'utm' => [
                        'source' => $extras['utm_source'] ?? ($extras['src'] ?? null),
                        'medium' => $extras['utm_medium'] ?? ($extras['med'] ?? null),
                        'campaign' => $extras['utm_campaign'] ?? ($extras['cmp'] ?? null),
                        'term' => $extras['utm_term'] ?? ($extras['trm'] ?? null),
                        'content' => $extras['utm_content'] ?? ($extras['cnt'] ?? null),
                    ],
                ];
            }),
            'created_at' => $this->toIsoUtc($this->created_at),
            'updated_at' => $this->toIsoUtc($this->updated_at),
        ];
    }

    protected function toIsoUtc($date): ?string
    {
        if (! $date) {
            return null;
        }

        return Carbon::parse($date)->utc()->toIso8601ZuluString();
    }
}
