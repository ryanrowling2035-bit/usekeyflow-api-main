<?php

namespace App\Http\Resources\ContactResources;

use App\Constants\Constants;
use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GetContactResource extends JsonResource
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
            'contactCategory' => $this->contactCategory,
            'contactCategoryLabel' => $this->contactCategory ? (Constants::CONTACT_CATEGORIES[$this->contactCategory] ?? $this->contactCategory) : null,
            'phone' => normalizePhoneE164($this->phone),
            'alternatePhone' => normalizePhoneE164($this->alternatePhone),
            'otherPhone' => normalizePhoneE164($this->otherPhone),
            'email' => $this->email,
            'source' => $this->source,
            'sourceLabel' => Constants::leadSourceGroupLabel($this->source),
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
            // Merge state, so the UI can badge the contact and offer the unmerge.
            // mergedContacts lists what this contact absorbed; mergedInto is set on
            // the other side, for the alias itself.
            'mergedContacts' => $this->mergedContacts->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'phone' => normalizePhoneE164($c->phone),
                'email' => $c->email,
                'mergedAt' => convertToTimezone($c->mergedAt),
            ])->values(),
            // True while another live contact still shares this number and neither has
            // been merged — the state a "Merge" button acts on. It is symmetric:
            // both contacts in a pair report it until one is merged into the other.
            'isDuplicate' => $this->hasPotentialDuplicate(),
            // The master of this contact's merge family: the contact it was merged
            // into, or its own id when it IS the master. "" when the contact is in
            // no merge at all. Either way the frontend reads one field to know which
            // record survives, without comparing it against mergedContacts.
            'masterContactId' => $this->resolveMasterContactId(),
        ];
    }

    /**
     * "" unless this contact is part of a merge — then the id of whichever record
     * survives it. mergedContacts is eager loaded by findById(), so the master case
     * costs nothing extra.
     */
    private function resolveMasterContactId(): string
    {
        if ($this->isMergedAway()) {
            return (string) $this->mergedIntoId;
        }

        return $this->mergedContacts->isNotEmpty() ? (string) $this->id : '';
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
