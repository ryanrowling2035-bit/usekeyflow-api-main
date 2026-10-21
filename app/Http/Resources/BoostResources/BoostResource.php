<?php

namespace App\Http\Resources\BoostResources;

use App\Constants\Constants;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BoostResource extends JsonResource
{
    public function toArray(Request $request)
    {
        return [
            'id' => $this->id,
            'isPropertyFinder' => $this->isPropertyFinder,
            'isBayut' => $this->isBayut,
            'propertyId' => $this->propertyId,
            'duration' => $this->duration,
            'boostType' => $this->boostType,
            'boostTypeLabel' => $this->boostType ? (Constants::BOOST_TYPE[$this->boostType] ?? $this->boostType) : null,
            'credits' => $this->credits,
            'status' => $this->status,
            'statusLabel' => $this->status ? (Constants::BOOST_STATUS[$this->status] ?? $this->status) : null,
            'notes' => $this->notes,
            'rejectionReason' => $this->rejectionReason,
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'durationLabel' => $this->duration ? (Constants::BOOST_DURATION[$this->duration] ?? $this->duration.' Days') : null,
            'createdBy' => $this->createdBy,
            'created_at' => convertToTimezone($this->created_at),
            'created' => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ],
            // "Boosted by" — who approved / pushed the boost live (null until then).
            'boostedBy' => $this->approver ? [
                'id' => $this->approver->id,
                'name' => $this->approver->name,
            ] : null,
            'property' => $this->property ? [
                'id' => $this->property->id,
                'title' => $this->property->title,
                'referenceNumber' => $this->property->referenceNumber,
                'status' => $this->property->status,
                'statusLabel' => $this->property->status
                    ? (Constants::PROPERTY_STATUS[$this->property->status] ?? $this->property->status)
                    : null,
                'listingType' => $this->property->listingType,
            ] : null,
            // The listing's agent (drives the Agent filter) and the agent's team.
            'agent' => $this->property?->agent ? [
                'id' => $this->property->agent->id,
                'name' => $this->property->agent->name,
            ] : null,
            'team' => $this->property?->agent?->teams?->first() ? [
                'id' => $this->property->agent->teams->first()->id,
                'name' => $this->property->agent->teams->first()->name,
            ] : null,
        ];
    }
}
