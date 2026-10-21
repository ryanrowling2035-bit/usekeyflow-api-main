<?php

namespace App\Http\Resources\EnquiryResource;

use App\Constants\Constants;
use Illuminate\Http\Resources\Json\JsonResource;

class GetEnquiryResource extends JsonResource
{
    public function toArray($request)
    {
        $pastTasks = [];
        $activeTasks = [];
        $lastCallDate = null;

        if ($this->tasks) {
            foreach ($this->tasks as $task) {
                if (in_array($task->status, Constants::TASK_CLOSED_STATUSES)) {
                    $pastTasks[] = $task;
                } else {
                    $activeTasks[] = $task;
                }

                if (strtolower((string) $task->type) === 'call' && $task->dueDate && (! $lastCallDate || $task->dueDate > $lastCallDate)) {
                    $lastCallDate = $task->dueDate;
                }
            }
        }

        // Order activities by their due date (tasks without a date sort last):
        //  - Upcoming & Overdue: closest due date to today first (e.g. a task due
        //    yesterday ranks above one due last week, and one due tomorrow ranks
        //    above one due next month).
        //  - Past Activities: most recent due date first.
        $now = time();
        $dueTs = fn ($task) => $task->dueDate ? strtotime((string) $task->dueDate) : null;

        // Closest-to-today first: compare absolute distance from now (nulls last).
        $byProximity = function ($a, $b) use ($dueTs, $now) {
            $ta = $dueTs($a);
            $tb = $dueTs($b);
            if ($ta === null && $tb === null) {
                return 0;
            }
            if ($ta === null) {
                return 1;
            }
            if ($tb === null) {
                return -1;
            }

            return abs($ta - $now) <=> abs($tb - $now);
        };

        // Most recent due date first (nulls last).
        $byDueDesc = function ($a, $b) use ($dueTs) {
            $ta = $dueTs($a);
            $tb = $dueTs($b);
            if ($ta === $tb) {
                return 0;
            }
            if ($ta === null) {
                return 1;
            }
            if ($tb === null) {
                return -1;
            }

            return $tb <=> $ta;
        };
        usort($activeTasks, $byProximity);
        usort($pastTasks, $byDueDesc);

        $lastModifiedBy = null;
        if ($this->history) {
            $latestHistory = $this->history->sortByDesc('created_at')->first();
            if ($latestHistory && $latestHistory->user) {
                $lastModifiedBy = [
                    'id' => $latestHistory->user->id,
                    'name' => $latestHistory->user->name,
                    'email' => $latestHistory->user->email,
                ];
            }
        }
        $isFollowing = userIsFollowing(Constants::MODULE_ENQUIRY, $this->id);
        $isFavorite = userIsFavorite(Constants::MODULE_ENQUIRY, $this->id);

        // A merged-away contact shows as the contact it was merged into.
        $contact = $this->contact?->masterOrSelf();

        return [
            'id' => $this->id,
            'agentId' => $this->agentId,
            'agent' => $this->agent,
            'contactId' => $contact?->id,
            'contact' => $contact,
            'listingId' => $this->listingId,
            'listing' => $this->listing ? [
                'id' => $this->listing->id,
                'title' => $this->listing->title,
                'referenceNumber' => $this->listing->referenceNumber,
                'qrIdentifier' => $this->listing->id,
            ] : null,
            'phone' => normalizePhoneE164($this->phone),
            'enquiryType' => $this->enquiryType,
            'enquiryTypeLabel' => $this->enquiryType ? (Constants::ENQUIRY_TYPE[strtolower($this->enquiryType)] ?? $this->enquiryType) : null,
            'status' => $this->status,
            'statusLabel' => $this->status ? (Constants::enquiryStatusMap($this->enquiryType)[$this->status] ?? $this->status) : null,
            'referredBy' => $this->referredBy,
            'actualPrice' => $this->actualPrice,
            'priceMin' => $this->priceMin,
            'priceMax' => $this->priceMax,
            'sizeMin' => $this->sizeMin,
            'sizeMax' => $this->sizeMax,
            'bedroomsMin' => $this->bedroomsMin,
            'bedroomsMinLabel' => $this->bedroomsMin == -1 ? 'Studio' : $this->bedroomsMin,
            'bedroomsMax' => $this->bedroomsMax,
            'bedroomsMaxLabel' => $this->bedroomsMax == -1 ? 'Studio' : $this->bedroomsMax,
            'bathroomsMin' => formatDecimal($this->bathroomsMin),
            'bathroomsMax' => formatDecimal($this->bathroomsMax),
            'listingType' => $this->listingType,
            'listingTypeLabel' => $this->listingType ? (Constants::ENQUIRY_LISTING_TYPE[$this->listingType] ?? $this->listingType) : null,
            'lostReason' => $this->lostReason,
            'enquiryPropertyType' => $this->enquiryPropertyType,
            'enquiryPropertyTypeLabel' => $this->enquiryPropertyType ? (Constants::ENQUIRY_PROPERTY_TYPE[$this->enquiryPropertyType] ?? $this->enquiryPropertyType) : null,
            'enquiryPropertySubType' => $this->enquiryPropertySubType,
            'leadSource' => $this->leadSource,
            'leadSourceLabel' => Constants::leadSourceGroupLabel($this->leadSource),
            'technicalSource' => $this->technicalSource,
            'technicalSourceLabel' => $this->technicalSource ? (Constants::TECHNICAL_SOURCE[$this->technicalSource] ?? $this->technicalSource) : null,
            'comments' => $this->comments,
            'isOpenGame' => $this->isOpenGame ?? false,
            'isQualified' => $this->isQualified ?? false,
            'isActive' => $this->isActive ?? true,
            'flyerSent' => (bool) $this->flyerSent,
            'createdBy' => $this->createdBy,
            'creator' => $this->creator,
            'createdAt' => convertToTimezone($this->created_at),
            'updatedAt' => convertToTimezone($this->updated_at),
            'lastActivityDate' => convertToTimezone($this->lastActivityAt),
            'lastModifiedBy' => $lastModifiedBy,
            'lastCallDate' => $lastCallDate ? convertToTimezone($lastCallDate) : null,
            'history' => $this->history,
            'tasks' => $activeTasks,
            'pastTasks' => $pastTasks,
            'follows' => $this->follows,
            'isFollowing' => $isFollowing,
            'isFavorite' => $isFavorite,
            'referenceNo' => $this->referenceNo,
            'city' => $this->city,
            'cityLabel' => $this->cityInfo?->name,
            'community' => $this->community,
            'communityLabel' => $this->communityInfo?->name,
            'community_2' => $this->community_2,
            'communityLabel_2' => $this->community2Info?->name,
            'community_3' => $this->community_3,
            'communityLabel_3' => $this->community3Info?->name,
            'isOwnedByCurrentAgent' => isAgent() ? ($this->agentId === user()->id || (bool) $this->isOpenGame) : true,
            'leadQualification' => $this->leadQualification,
            'projectName' => $this->projectName,
            'contactMethod' => $this->contactMethod,
            'webUrl' => $this->webUrl,
            'propertyPropertyFinder' => $this->propertyPropertyFinder,
            'recordType' => $this->recordType,
            'extras' => $this->extras,
            'leadRating' => $this->leadRating,
            // Effective lead score (materialised in leadRating). leadScoreSource
            // tells the UI whether it's a manual lock or the automatic value.
            'leadScore' => $this->leadRating ?: leadScoreForStatus($this->status),
            'leadScoreSource' => $this->leadScoreLocked ? 'manual' : 'auto',
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
            'internalNotes' => $this->whenLoaded('internalNotes', function () {
                return $this->internalNotes->sortByDesc('created_at')->values();
            }),
        ];
    }
}
