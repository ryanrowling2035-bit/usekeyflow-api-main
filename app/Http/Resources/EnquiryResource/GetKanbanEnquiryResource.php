<?php

namespace App\Http\Resources\EnquiryResource;

use App\Constants\Constants;
use Illuminate\Http\Resources\Json\JsonResource;

class GetKanbanEnquiryResource extends JsonResource
{
    public function toArray($request)
    {
        // A merged-away contact shows as the contact it was merged into.
        $contact = $this->contact?->masterOrSelf();

        return [
            'id' => $this->id,
            'referenceNo' => $this->referenceNo,
            'listingReference' => $this->listing?->title,
            'contact' => $contact ? [
                'id' => $contact->id,
                'name' => $contact->name,
                'phone' => normalizePhoneE164($contact->phone),
            ] : null,
            'agent' => $this->agent ? [
                'id' => $this->agent->id,
                'name' => $this->agent->name,
            ] : null,
            'createdBy' => $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ] : null,
            'status' => $this->status,
            'statusLabel' => $this->status ? (Constants::enquiryStatusMap($this->enquiryType)[$this->status] ?? $this->status) : null,
            'enquiryType' => $this->enquiryType,
            'enquiryTypeLabel' => $this->enquiryType ? (Constants::ENQUIRY_TYPE[strtolower($this->enquiryType)] ?? $this->enquiryType) : null,
            'leadSource' => $this->leadSource,
            'leadSourceLabel' => Constants::leadSourceGroupLabel($this->leadSource),
            'isQualified' => (bool) ($this->isQualified ?? false),
            'isOpenGame' => (bool) ($this->isOpenGame ?? false),
            'isOwnedByCurrentAgent' => isAgent() ? ($this->agentId === user()->id) : true,
            'createdAt' => convertToTimezone($this->created_at),
            'updatedAt' => convertToTimezone($this->updated_at),
            'lastActivityDate' => convertToTimezone($this->lastActivityAt),
            'internalNotes' => $this->whenLoaded('internalNotes', function () {
                return $this->internalNotes->sortByDesc('created_at')->values();
            }),
        ];
    }
}
