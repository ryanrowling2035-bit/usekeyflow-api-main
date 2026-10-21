<?php

namespace App\Http\Resources\EnquiryResource;

use App\Constants\Constants;
use Illuminate\Http\Resources\Json\JsonResource;

class GetAllEnquiryResource extends JsonResource
{
    public function toArray($request)
    {
        $isFollowing = userIsFollowing(Constants::MODULE_ENQUIRY, $this->id);
        $isFavorite = userIsFavorite(Constants::MODULE_ENQUIRY, $this->id);

        // Determine if current user has full access to this enquiry's contact.
        // Full access: own enquiry, open game enquiry, or non-agent (admin).
        // Other agents see the full enquiry but with the contact details masked.
        $isOwn = user() && $this->agentId == user()->id;
        $isOpenGame = $this->isOpenGame ?? false;
        $hasFullAccess = ! isAgent() || $isOwn || $isOpenGame;

        // Where this enquiry's contact has been merged into another, show the
        // surviving contact. The enquiry still stores the alias's id — merging
        // moves nothing — but the person is one person, so it reads as one.
        $contact = $this->contact?->masterOrSelf();

        return [
            'id' => $this->id,
            'agentId' => $this->agentId,
            'agent' => $this->agent ? [
                'id' => $this->agent->id,
                'name' => $this->agent->name,
            ] : null,
            'contactId' => $hasFullAccess ? $contact?->id : null,
            'contact' => $hasFullAccess && $contact ? [
                'id' => $contact->id,
                'name' => $contact->name,
            ] : null,
            'listingId' => $this->listingId,
            'listing' => $this->listing ? [
                'id' => $this->listing->id,
                'title' => $this->listing->title,
                'referenceNumber' => $this->listing->referenceNumber,
                'qrIdentifier' => $this->listing->id,
            ] : null,
            'phone' => $hasFullAccess ? normalizePhoneE164($this->phone ?: $contact?->phone) : null,
            'enquiryType' => $this->enquiryType ? (Constants::ENQUIRY_TYPE[strtolower($this->enquiryType)] ?? $this->enquiryType) : null,
            'status' => $this->status ? (Constants::ENQUIRY_STATUS[$this->status] ?? $this->status) : null,
            // leadRating is the materialised effective score (manual or auto);
            // fall back to the status-derived value for any un-backfilled row.
            'leadScore' => $this->leadRating ?: leadScoreForStatus($this->status),
            'leadScoreSource' => $this->leadScoreLocked ? 'manual' : 'auto',
            'referredBy' => $this->referredBy,
            'actualPrice' => $this->actualPrice,
            'priceMin' => $this->priceMin,
            'priceMax' => $this->priceMax,
            'sizeMin' => $this->sizeMin,
            'sizeMax' => $this->sizeMax,
            'referenceNo' => $this->referenceNo,
            'bedroomsMin' => $this->bedroomsMin,
            'bedroomsMinLabel' => $this->bedroomsMin == -1 ? 'Studio' : $this->bedroomsMin,
            'bedroomsMax' => $this->bedroomsMax,
            'bedroomsMaxLabel' => $this->bedroomsMax == -1 ? 'Studio' : $this->bedroomsMax,
            'bathroomsMin' => formatDecimal($this->bathroomsMin),
            'bathroomsMax' => formatDecimal($this->bathroomsMax),
            'listingType' => $this->listingType ? (Constants::ENQUIRY_LISTING_TYPE[$this->listingType] ?? $this->listingType) : null,
            'lostReason' => $this->lostReason,
            'enquiryPropertyType' => $this->enquiryPropertyType ? (Constants::ENQUIRY_PROPERTY_TYPE[$this->enquiryPropertyType] ?? $this->enquiryPropertyType) : null,
            'enquiryPropertySubType' => $this->enquiryPropertySubType,
            'leadSource' => Constants::leadSourceVariantLabel($this->leadSource),
            'technicalSource' => $this->technicalSource ? (Constants::TECHNICAL_SOURCE[$this->technicalSource] ?? $this->technicalSource) : null,
            'isOpenGame' => $isOpenGame,
            'isQualified' => $this->isQualified ?? false,
            'isActive' => $this->isActive ?? true,
            'flyerSent' => (bool) $this->flyerSent,
            'createdBy' => $this->createdBy,
            'creator' => $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ] : null,
            'createdAt' => convertToTimezone($this->created_at),
            'updatedAt' => convertToTimezone($this->updated_at),
            'lastActivityDate' => convertToTimezone($this->lastActivityAt),
            // Only serialize history/follows when the caller eager-loaded them. The list and
            // dashboard cards don't render them, so lazy-loading per row (N+1) and shipping the
            // full history payload was pure overhead. Detail/other callers that need them just
            // eager-load ->with(['history','follows']).
            'history' => $this->whenLoaded('history'),
            'follows' => $this->whenLoaded('follows'),
            'isFollowing' => $isFollowing,
            'isFavorite' => $isFavorite,
            'isOwnedByCurrentAgent' => isAgent() ? ($this->agentId === user()->id) : true,
            'city' => $this->city,
            'cityLabel' => $this->cityInfo?->name,
            'community' => $this->community,
            'communityLabel' => $this->communityInfo?->name,
            'community_2' => $this->community_2,
            'communityLabel_2' => $this->community2Info?->name,
            'community_3' => $this->community_3,
            'communityLabel_3' => $this->community3Info?->name,
            'locationBreadcrumb' => implode(' > ', array_filter(array_map(
                fn ($name) => $name ? ucwords(strtolower($name)) : null,
                [
                    $this->cityInfo?->name,
                    $this->communityInfo?->name,
                    $this->community2Info?->name,
                    $this->community3Info?->name,
                ]
            ))) ?: null,
            'leadQualification' => $this->leadQualification,
            'projectName' => $this->projectName,
            'contactMethod' => $this->contactMethod,
            'webUrl' => $this->webUrl,
            'propertyPropertyFinder' => $this->propertyPropertyFinder,
            'recordType' => $this->recordType,
            'extras' => $this->extras,
            'leadRating' => $this->leadRating,
            'leadOutcome' => $this->leadOutcome,
            'outcome' => $this->outcome,
            'enquiryRecordUrl' => $this->enquiryRecordUrl,
            'filterNumber' => $this->filterNumber,
            'smsOptOut' => $this->smsOptOut ?? false,
            'unitNumber' => $this->unitNumber,
            'unitType' => $this->unitType,
            'yearBuiltMin' => $this->yearBuiltMin,
            'yearBuiltMax' => $this->yearBuiltMax,
            'floorMin' => $this->floorMin,
            'floorMax' => $this->floorMax,
            'parkingSpaceMin' => $this->parkingSpaceMin,
            'parkingSpaceMax' => $this->parkingSpaceMax,
            'privateAmenities' => $this->privateAmenities,
            'view' => $this->view,
            'furnished' => $this->furnished,
            'elPartners' => $this->elPartners,
            'partnersEmail' => $this->partnersEmail,
            'partnerEmailCc' => $this->partnerEmailCc,
            'investmentMotivation' => $this->investmentMotivation,
            'budget' => $this->budget,
            'dubaiExperience' => $this->dubaiExperience,
            'timeline' => $this->timeline,
            'apartmentType' => $this->apartmentType,
            'comments' => $this->whenLoaded('internalNotes', function () {
                return $this->internalNotes->sortByDesc('id')->first()?->content;
            }),

            'latestActivity' => $this->whenLoaded('tasks', function () {
                $task = $this->latestActivity;

                if (! $task) {
                    return null;
                }

                $isUpcoming = $task->dueDate
                    && $task->dueDate >= now()->setTimezone(appTimezone())->toDateString();

                return [
                    'id' => $task->id,
                    'subject' => $task->subject,
                    'type' => $task->type,
                    'typeLabel' => $task->type ? (Constants::TASK_TYPE[$task->type] ?? $task->type) : null,
                    'dueDate' => $task->dueDate,
                    'status' => $task->status,
                    'statusLabel' => $task->status ? (Constants::TASK_STATUS[$task->status] ?? $task->status) : null,
                    'comments' => $task->comments,
                    'isUpcoming' => (bool) $isUpcoming,
                    'assignedTo' => $task->assignedUser ? [
                        'id' => $task->assignedUser->id,
                        'name' => $task->assignedUser->name,
                    ] : null,
                ];
            }),
        ];
    }
}
