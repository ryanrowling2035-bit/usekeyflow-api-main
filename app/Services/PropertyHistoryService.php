<?php

namespace App\Services;

use App\Constants\Constants;
use App\Models\Contact;
use App\Models\Property;
use App\Models\PropertyCommunity;
use App\Models\PropertyListingHistory;
use App\Models\PropertyLocation;
use App\Models\PropertySubCommunity;
use App\Models\PropertyTower;
use App\Models\User;
use App\Repositories\PropertyHistoryRepository;
use Illuminate\Http\Request;

class PropertyHistoryService
{
    protected PropertyHistoryRepository $repository;

    /**
     * Field-to-model resolver map.
     * Each entry: 'fieldName' => [Model::class, 'lookupColumn', 'displayColumn']
     */
    private static array $fieldResolvers = [
        'agentId' => [User::class, 'id', 'name'],
        'createdBy' => [User::class, 'id', 'name'],
        'approvedBy' => [User::class, 'id', 'name'],
        'publishedBy' => [User::class, 'id', 'name'],
        'physicalOwnerId' => [Contact::class, 'id', 'name'],
        'pfCity' => [PropertyLocation::class, 'locationId', 'name'],
        'pfCommunity' => [PropertyCommunity::class, 'communityId', 'name'],
        'pfSubCommunity' => [PropertySubCommunity::class, 'propertySubCommunityId', 'name'],
        'pfBuilding' => [PropertyTower::class, 'propertyTowerId', 'name'],
    ];

    /**
     * Human-readable labels for every property field.
     */
    private static array $fieldLabels = [
        'title' => 'Title',
        'description' => 'Description',
        'referenceNumber' => 'Reference Number',
        'category' => 'Category',
        'listingType' => 'Listing Type',
        'sizeArea' => 'Size (sqft)',
        'plotSize' => 'Plot Size',
        'bedrooms' => 'Bedrooms',
        'bathrooms' => 'Bathrooms',
        'completionStatus' => 'Completion Status',
        'price' => 'Price',
        'reraPermitNo' => 'RERA Permit No',
        'govtQR' => 'Government QR',
        'agentId' => 'Agent',
        'physicalOwnerId' => 'Owner',
        'pfPropertyType' => 'Property Finder Type',
        'bayutPropertyType' => 'Bayut Type',
        'pfCity' => 'City',
        'pfCommunity' => 'Community',
        'pfSubCommunity' => 'Sub Community',
        'pfBuilding' => 'Building / Tower',
        'pfLatitude' => 'Latitude',
        'pfLongitude' => 'Longitude',
        'pfAmenities' => 'PF Amenities',
        'bayutLocation' => 'Bayut Location',
        'bayutLocationId' => 'Bayut Location ID',
        'bayutAmenities' => 'Bayut Amenities',
        'pfPriceFrequency' => 'Rent Frequency (PF)',
        'pfCheques' => 'No. of Cheques',
        'bayutOccupancyStatus' => 'Occupancy Status',
        'bayutMinimumContractPeriod' => 'Min Contract Period',
        'bayutVacatingNoticePeriod' => 'Vacating Notice Period',
        'bayutMaintenanceFee' => 'Maintenance Fee',
        'bayutMaintenanceFeePaidBy' => 'Maintenance Fee Paid By',
        'createdBy' => 'Created By',
        'status' => 'Status',
        'approvalStatus' => 'Approval Status',
        'latestApprovalComment' => 'Approval Comment',
        'approvedBy' => 'Approved By',
        'publishedBy' => 'Published By',
        'permitRequestedAt' => 'Permit Requested At',
        'approvedAt' => 'Approved At',
        'publishedAt' => 'Published At',
    ];

    /**
     * Enum fields mapped to their Constants display values.
     */
    private static function enumResolvers(): array
    {
        return [
            'status' => Constants::PROPERTY_STATUS,
            'approvalStatus' => Constants::PROPERTY_APPROVAL_STATUS,
            'listingType' => Constants::PROPERTY_PURPOSE_TYPE,
            'completionStatus' => Constants::COMPLETION_STATUS,
            'pfPropertyType' => Constants::pfPropertyTypeLabels(),
            'pfPriceFrequency' => Constants::PROPERTY_PRICE_FREQUENCIES,
            'pfCheques' => Constants::PF_NUMBER_OF_CHEQUES,
            'bayutOccupancyStatus' => Constants::BAYUT_OCCUPANCY_STATUS,
            'bayutMaintenanceFeePaidBy' => Constants::BAYUT_MAINTENANCE_PAID_BY,
            'category' => Constants::PROPERTY_CLASSIFICATION_TYPE,
        ];
    }

    /**
     * Human-readable action labels for non-update actions.
     */
    private static array $actionLabels = [
        'created' => 'created a new property listing',
        'deleted' => 'deleted the property',
        'cloned' => 'cloned the property',
        'uploaded_media' => 'uploaded media files',
        'updated_media' => 'updated media files',
        'deleted_media' => 'deleted media files',
        'reordered_media' => 'reordered media files',
        'generated_pdf' => 'generated a PDF',
        'generated_image' => 'generated an image',
        'exported_csv' => 'exported properties to CSV',
        'email_sent' => 'sent an email',
        'enquiry_quotation_sent' => 'sent a quotation email',
        'sent_custom_email' => 'sent a custom email',
        'compose_email' => 'composed and sent an email',
        'favorited' => 'added the property to favorites',
        'unfavorited' => 'removed the property from favorites',
        'followed' => 'started following the property',
        'unfollowed' => 'stopped following the property',
        'boost_created' => 'created a boost',
        'boost_updated' => 'updated the boost',
        'boost_deleted' => 'deleted the boost',
        'closing_created' => 'created a closing',
        'closing_updated' => 'updated the closing',
        'closing_deleted' => 'deleted the closing',
        'closing_exported_csv' => 'exported closings to CSV',
        'offer_created' => 'created an offer',
        'offer_updated' => 'updated the offer',
        'offer_deleted' => 'deleted the offer',
        'offer_exported_csv' => 'exported offers to CSV',
        'task_created' => 'created a task',
        'task_updated' => 'updated the task',
        'task_deleted' => 'deleted the task',
        'enquiry_linked' => 'linked an enquiry to the property',
        'enquiry_unlinked' => 'unlinked an enquiry from the property',
        'permit_number_entered' => 'entered the permit number',
        'published_to_bayut' => 'published the listing to Bayut',
        'published_to_property_finder' => 'published the listing to Property Finder',
        'bayut_update_failed' => 'failed to update the listing on Bayut',
        'pf_update_failed' => 'failed to update the listing on Property Finder',
    ];

    public function __construct(PropertyHistoryRepository $repository)
    {
        $this->repository = $repository;
    }

    public function getAll(Request $request)
    {
        return $this->repository->getAll($request);
    }

    /**
     * Fields that reference a User ID (for link generation in changes).
     */
    private static array $userFields = ['agentId', 'createdBy', 'approvedBy', 'publishedBy'];

    public static function log(array $params): ?PropertyListingHistory
    {
        $user = auth('api')->user();
        $userId = $user?->id ?? $params['userId'] ?? null;
        $field = $params['field'];
        $originalValue = $params['originalValue'] ?? null;
        $newValue = $params['newValue'] ?? null;
        $createdBy = $user?->name ?? 'System';
        $roleName = $user?->role?->name ?? 'User';
        $actorId = $userId;

        // Auto-resolve property title
        $propertyTitle = Property::where('id', $params['propertyId'])->value('title') ?? "#{$params['propertyId']}";

        $comments = self::buildComments(
            $field,
            $originalValue,
            $newValue,
            $createdBy,
            $propertyTitle,
            $roleName,
            $actorId,
            $params['propertyId'],
            $params['comments'] ?? null
        );

        if (! empty($params['extraData']) && is_array($params['extraData'])) {
            $comments = array_merge($comments, $params['extraData']);
        }

        return PropertyListingHistory::create([
            'propertyId' => $params['propertyId'],
            'userId' => $userId,
            'field' => $field,
            'originalValue' => is_array($originalValue) ? json_encode($originalValue) : $originalValue,
            'newValue' => is_array($newValue) ? json_encode($newValue) : $newValue,
            'comments' => is_array($comments) ? json_encode($comments) : $comments,
        ]);
    }

    private static function buildComments(string $field, $originalValue, $newValue, string $createdBy, string $propertyTitle, string $roleName, ?int $actorId, int $propertyId, ?string $rawMessage = null): array
    {
        $actorLink = self::userLink($actorId, $createdBy, $roleName);
        $propertyLink = self::propertyLink($propertyId, $propertyTitle);

        // Check if property is vacationHomes to swap RERA → DTCM Code labels
        $listingType = Property::where('id', $propertyId)->value('listingType');
        $isVacationHomes = strtolower($listingType ?? '') === 'vacationhomes';

        $comments = [
            'action' => $field,
            'createdBy' => $createdBy,
            'role' => $roleName,
            'property' => $propertyTitle,
        ];

        // For update actions with array changes — build per-field human-readable message
        if ($field === 'updated' && is_array($originalValue) && is_array($newValue)) {
            $changes = [];
            $changeSentences = [];

            foreach ($newValue as $key => $newVal) {
                $oldVal = $originalValue[$key] ?? null;
                $label = self::$fieldLabels[$key] ?? self::formatFieldName($key);

                // Replace RERA label with DTCM Code for vacationHomes listings
                if ($isVacationHomes && $key === 'reraPermitNo') {
                    $label = 'DTCM Code';
                }

                $change = [
                    'field' => $key,
                    'label' => $label,
                    'from' => $oldVal,
                    'to' => $newVal,
                ];

                // Resolve display values for ID fields (agent, owner, city, etc.)
                $displayFrom = $oldVal;
                $displayTo = $newVal;

                if (isset(self::$fieldResolvers[$key])) {
                    [$model, $lookupCol, $displayCol] = self::$fieldResolvers[$key];
                    if ($oldVal) {
                        $resolved = $model::where($lookupCol, $oldVal)->value($displayCol);
                        $change['fromLabel'] = $resolved;
                        // Add link for user fields
                        if (in_array($key, self::$userFields) && $resolved) {
                            $fromRole = self::getUserRoleName($oldVal);
                            $displayFrom = self::userLink($oldVal, $resolved, $fromRole);
                        } elseif ($key === 'listingId' && $resolved) {
                            $displayFrom = self::propertyLink($oldVal, $resolved);
                        } else {
                            $displayFrom = $resolved ?? $oldVal;
                        }
                    }
                    if ($newVal) {
                        $resolved = $model::where($lookupCol, $newVal)->value($displayCol);
                        $change['toLabel'] = $resolved;
                        if (in_array($key, self::$userFields) && $resolved) {
                            $toRole = self::getUserRoleName($newVal);
                            $displayTo = self::userLink($newVal, $resolved, $toRole);
                        } elseif ($key === 'listingId' && $resolved) {
                            $displayTo = self::propertyLink($newVal, $resolved);
                        } else {
                            $displayTo = $resolved ?? $newVal;
                        }
                    }
                }

                // Resolve enum values (status, furnishing, completion status, etc.)
                $enumResolvers = self::enumResolvers();
                if (isset($enumResolvers[$key])) {
                    $enumMap = $enumResolvers[$key];
                    if ($oldVal && isset($enumMap[$oldVal])) {
                        $displayFrom = $enumMap[$oldVal];
                        $change['fromLabel'] = $displayFrom;
                    }
                    if ($newVal && isset($enumMap[$newVal])) {
                        $displayTo = $enumMap[$newVal];
                        $change['toLabel'] = $displayTo;
                    }
                }

                $fromStr = self::formatDisplayValue($displayFrom);
                $toStr = self::formatDisplayValue($displayTo);

                if ($fromStr && $toStr) {
                    $changeSentences[] = "{$label} from \"{$fromStr}\" to \"{$toStr}\"";
                } elseif ($toStr) {
                    $changeSentences[] = "{$label} set to \"{$toStr}\"";
                } elseif ($fromStr) {
                    $changeSentences[] = "{$label} cleared (was \"{$fromStr}\")";
                }

                $changes[] = $change;
            }

            $comments['changes'] = $changes;

            if (count($changeSentences) === 1) {
                $comments['message'] = "{$actorLink} has updated {$changeSentences[0]} on property {$propertyLink}.";
            } else {
                $bulletList = implode('; ', $changeSentences);
                $comments['message'] = "{$actorLink} has updated the following on property {$propertyLink}: {$bulletList}.";
            }

            return $comments;
        }

        // For permit requested action
        if ($field === 'permit_requested') {
            $permitLabel = $isVacationHomes ? 'DTCM Code' : 'RERA Permit';
            $comments['message'] = "{$permitLabel} requested by {$actorLink}.";

            return $comments;
        }

        // For status change actions
        if ($field === 'status') {
            $oldStatus = self::extractMessageValue($originalValue);
            $newStatus = self::extractMessageValue($newValue);

            // For approval/rejection actions, use simplified message
            if ($newStatus === Constants::APPROVAL_APPROVED) {
                $comments['message'] = "Listing Approved by {$actorLink}";

                return $comments;
            }

            if ($newStatus === Constants::APPROVAL_REJECTED) {
                $comments['message'] = "Listing Rejected by {$actorLink}";

                return $comments;
            }

            $oldLabel = Constants::PROPERTY_STATUS[$oldStatus] ?? Constants::PROPERTY_APPROVAL_STATUS[$oldStatus] ?? ucfirst($oldStatus ?? 'unknown');
            $newLabel = Constants::PROPERTY_STATUS[$newStatus] ?? Constants::PROPERTY_APPROVAL_STATUS[$newStatus] ?? ucfirst($newStatus ?? 'unknown');

            $comments['message'] = "{$actorLink} changed the status from \"<strong>{$oldLabel}</strong>\" to \"<strong>{$newLabel}</strong>\" on property {$propertyLink}.";

            return $comments;
        }

        // For all other actions — use action label map
        $actionText = self::$actionLabels[$field] ?? str_replace('_', ' ', $field);

        // Replace permit label for vacationHomes listings
        if ($isVacationHomes && $field === 'permit_number_entered') {
            $actionText = 'entered the DTCM Code';
        }
        $extraDetail = self::extractMessageValue($newValue);

        if ($extraDetail && ! in_array($field, ['favorited', 'unfavorited', 'followed', 'unfollowed', 'uploaded_media', 'reordered_media'])) {
            $comments['message'] = "{$actorLink} {$actionText} on property {$propertyLink} — {$extraDetail}.";
        } else {
            $comments['message'] = "{$actorLink} {$actionText} {$propertyLink}.";
        }

        return $comments;
    }

    /**
     * Extract the 'message' value from a JSON-encoded string or return raw string.
     */
    private static function extractMessageValue($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $value['message'] ?? json_encode($value);
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded) && isset($decoded['message'])) {
                return $decoded['message'];
            }

            return $value;
        }

        return (string) $value;
    }

    /**
     * Format a display value for human-readable output.
     */
    private static function formatDisplayValue($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return implode(', ', $value);
        }

        return (string) $value;
    }

    /**
     * Convert camelCase field name to human-readable label.
     * e.g. 'bayutMinimumContractPeriod' → 'Bayut Minimum Contract Period'
     */
    private static function formatFieldName(string $field): string
    {
        $spaced = preg_replace('/([a-z])([A-Z])/', '$1 $2', $field);

        return ucfirst($spaced);
    }

    /**
     * Generate an HTML link for a user based on their role.
     */
    private static function userLink(?int $id, string $name, ?string $roleName = null): string
    {
        if (! $id) {
            return "<strong>{$name}</strong>";
        }

        $baseUrl = config('app.frontend_url').'/dashboard';

        if ($roleName === Constants::AGENT) {
            $url = "{$baseUrl}/agents/{$id}/view";
        } else {
            $url = "{$baseUrl}/users/{$id}";
        }

        return "<a href=\"{$url}\"><strong>{$name}</strong></a>";
    }

    /**
     * Generate an HTML link for a property.
     */
    private static function propertyLink(int $id, string $title): string
    {
        $baseUrl = config('app.frontend_url').'/dashboard';

        return "<a href=\"{$baseUrl}/property/{$id}/view\">{$title}</a>";
    }

    /**
     * Get role name for a user by ID.
     */
    private static function getUserRoleName($userId): ?string
    {
        $user = User::with('role')->find($userId);

        return $user?->role?->name ?? 'User';
    }

    /**
     * Resolve a single field value to its display name.
     */
    public static function resolveFieldLabel(string $field, $value): ?string
    {
        if (! $value || ! isset(self::$fieldResolvers[$field])) {
            return null;
        }

        [$model, $lookupCol, $displayCol] = self::$fieldResolvers[$field];

        return $model::where($lookupCol, $value)->value($displayCol);
    }
}
