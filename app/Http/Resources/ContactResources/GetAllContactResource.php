<?php

namespace App\Http\Resources\ContactResources;

use App\Constants\Constants;
use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GetAllContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $contactProfileUrl = getImageUrl($this->contactProfile);
        $contactTypes = Contact::normalizeContactTypes($this->contactType);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'salutation' => $this->salutation,
            'contactType' => $contactTypes,
            'contactTypeLabel' => array_map(fn ($t) => Constants::CONTACT_TYPE[$t] ?? $t, $contactTypes),
            'phone' => normalizePhoneE164($this->phone),
            'alternatePhone' => normalizePhoneE164($this->alternatePhone),
            'otherPhone' => normalizePhoneE164($this->otherPhone),
            'email' => $this->email,
            'source' => Constants::leadSourceGroupLabel($this->source),
            'contactSource' => $this->contactSource,
            'dob' => $this->dob,
            'notes' => $this->notes,
            'address' => $this->address,
            'nationality' => $this->nationality,
            'residencyStatus' => $this->residencyStatus,
            'residencyStatusLabel' => $this->residencyStatus ? (Constants::USER_RESIDENCY_STATUS[$this->residencyStatus] ?? $this->residencyStatus) : null,
            'contactProfile' => $contactProfileUrl,
            'contactOwner' => $this->contactOwnerUser ? [
                'id' => $this->contactOwnerUser->id,
                'name' => $this->contactOwnerUser->name,
            ] : null,
            'leadSourceDetails' => $this->leadSourceDetails,
            'bayutWhatsappInitiated' => $this->bayutWhatsappInitiated,
            'tenantWhatsappInitiated' => $this->tenantWhatsappInitiated,
            'offPlanWhatsappInitiated' => $this->offPlanWhatsappInitiated,
            'lastContacted' => $this->lastContacted?->format('Y-m-d'),
            'routingSource' => $this->routingSource,
            'outcome' => $this->outcome,
            'qualificationContact' => $this->qualificationContact,
            'leadRating' => $this->leadRating,
            'whatsappLead' => $this->whatsappLead,
            'referralSource' => $this->referralSource,
            'referralCompany' => $this->referralCompany,
            'referralPercentDue' => $this->referralPercentDue,
            'referralContact' => $this->referralContact,
            'referralFeePaidThru' => $this->referralFeePaidThru,
            'country' => $this->country,
            'state' => $this->state,
            'city' => $this->city,
            'zipCode' => $this->zipCode,
            'otherAddress' => $this->otherAddress,
            'otherCountry' => $this->otherCountry,
            'otherState' => $this->otherState,
            'otherCity' => $this->otherCity,
            'otherZipCode' => $this->otherZipCode,
            'company' => $this->company,
            'pastClient' => $this->pastClient,
            'title' => $this->title,
            'communityPreference' => $this->communityPreference,
            'companyName' => $this->companyName,
            'created_at' => convertToTimezone($this->created_at),
            'createdBy' => $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ] : null,
            'assignedAgent' => $this->getAssignedAgent(),
            // Same two fields the contact detail returns, so one card component can
            // render either. isDuplicateFlag is resolved in the list query; the
            // fallback covers callers that build this resource from a plain model
            // (the revert response, for one).
            'isDuplicate' => $this->isDuplicateFlag !== null
                ? (bool) $this->isDuplicateFlag
                : $this->hasPotentialDuplicate(),
            // Same field as the detail response: the id of whichever contact
            // survives this one's merge — its own when it is the master — or ""
            // when it is in no merge at all.
            'masterContactId' => $this->resolveMasterContactId(),
        ];
    }

    /**
     * "" unless this contact is part of a merge — then the id of whichever record
     * survives it.
     *
     * An alias never appears in the listing (notMerged filters it out), so in
     * practice this returns either the contact's own id or "". merged_contacts_count
     * comes from withCount() on the list query; the relation fallback covers callers
     * that build this resource from a plain model, such as the revert response.
     */
    private function resolveMasterContactId(): string
    {
        if ($this->isMergedAway()) {
            return (string) $this->mergedIntoId;
        }

        $isMaster = $this->merged_contacts_count !== null
            ? $this->merged_contacts_count > 0
            : $this->mergedContacts->isNotEmpty();

        return $isMaster ? (string) $this->id : '';
    }

    private function getAssignedAgent(): ?array
    {
        $agent = $this->latestEnquiry?->agent
            ?? $this->ownedListings?->first()?->agent;

        if (! $agent) {
            return null;
        }

        return [
            'id' => $agent->id,
            'name' => $agent->name,
            'email' => $agent->email,
            'agentCode' => $agent->agentCode ?? null,
            'identifier' => trim(($agent->agentCode ?? '').' - '.$agent->name, ' -'),
        ];
    }
}
