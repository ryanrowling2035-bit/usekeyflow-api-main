<?php

namespace App\Services;

use App\Constants\Constants;
use App\Models\Contact;
use App\Models\Enquiry;
use App\Models\EnquiryHistory;
use App\Models\Property;
use App\Models\User;
use App\Repositories\EnquiryHistoryRepository;
use Illuminate\Http\Request;

class EnquiryHistoryService
{
    protected EnquiryHistoryRepository $repository;

    /**
     * Field-to-model resolver map.
     * Each entry: 'fieldName' => [Model::class, 'lookupColumn', 'displayColumn']
     */
    private static array $fieldResolvers = [
        'agentId' => [User::class, 'id', 'name'],
        'createdBy' => [User::class, 'id', 'name'],
        'contactId' => [Contact::class, 'id', 'name'],
        'listingId' => [Property::class, 'id', 'title'],
        'referredBy' => [User::class, 'id', 'name'],
    ];

    /**
     * Human-readable labels for every enquiry field.
     */
    private static array $fieldLabels = [
        'agentId' => 'Agent',
        'contactId' => 'Contact',
        'listingId' => 'Listing',
        'referenceNo' => 'Reference No',
        'phone' => 'Phone',
        'enquiryType' => 'Enquiry Type',
        'status' => 'Status',
        'referredBy' => 'Referred By',
        'priceMin' => 'Min Price',
        'priceMax' => 'Max Price',
        'sizeMin' => 'Min Size',
        'sizeMax' => 'Max Size',
        'bedroomsMin' => 'Min Bedrooms',
        'bedroomsMax' => 'Max Bedrooms',
        'bathroomsMin' => 'Min Bathrooms',
        'bathroomsMax' => 'Max Bathrooms',
        'listingType' => 'Listing Type',
        'lostReason' => 'Lost Reason',
        'enquiryPropertyType' => 'Property Type',
        'enquiryPropertySubType' => 'Property Sub Type',
        'leadSource' => 'Lead Source',
        'technicalSource' => 'Technical Source',
        'comments' => 'Comments',
        'isOpenGame' => 'Open Game',
        'isQualified' => 'Qualified',
        'isActive' => 'Active',
        'createdBy' => 'Created By',
    ];

    /**
     * Enum fields mapped to their Constants display values.
     */
    private static array $enumResolvers = [
        'status' => Constants::ENQUIRY_STATUS,
        'enquiryType' => Constants::ENQUIRY_TYPE,
        'listingType' => Constants::PROPERTY_PURPOSE_TYPE,
        'enquiryPropertyType' => Constants::ENQUIRY_PROPERTY_TYPE,
        'leadSource' => Constants::LEAD_SOURCE_VARIANT_LABELS,
        'technicalSource' => Constants::TECHNICAL_SOURCE,
    ];

    /**
     * Human-readable action labels for non-update actions.
     */
    private static array $actionLabels = [
        'created' => 'created a new enquiry',
        'updated' => 'updated the enquiry',
        'deleted' => 'deleted the enquiry',
        'exported_csv' => 'exported enquiries to CSV',
        'generated_pdf' => 'generated a PDF',
        'email_sent' => 'sent an email',
        'compose_email' => 'composed and sent an email',
        'property_quotation_sent' => 'sent a property quotation email',
        'favorited' => 'added the enquiry to favorites',
        'unfavorited' => 'removed the enquiry from favorites',
        'followed' => 'started following the enquiry',
        'unfollowed' => 'stopped following the enquiry',
        'property_linked' => 'linked a property to the enquiry',
        'property_unlinked' => 'unlinked a property from the enquiry',
        'offer_created' => 'created an offer',
        'offer_updated' => 'updated the offer',
        'offer_deleted' => 'deleted the offer',
        'offer_exported_csv' => 'exported offers to CSV',
        'closing_created' => 'created a closing',
        'closing_updated' => 'updated the closing',
        'closing_deleted' => 'deleted the closing',
        'closing_exported_csv' => 'exported closings to CSV',
        'task_created' => 'created a task',
        'task_updated' => 'updated the task',
        'task_deleted' => 'deleted the task',
        'take_ownership' => 'took ownership of the enquiry',
        'isOpenGame' => 'changed open game status',
        'isQualified' => 'changed qualification status',
    ];

    /**
     * Fields that reference a User ID (for link generation in changes).
     */
    private static array $userFields = ['agentId', 'createdBy', 'referredBy'];

    public function __construct(EnquiryHistoryRepository $repository)
    {
        $this->repository = $repository;
    }

    public function getAll(Request $request)
    {
        return $this->repository->getAll($request);
    }

    public static function log(array $params): ?EnquiryHistory
    {
        $user = auth('api')->user();
        $userId = $user?->id ?? $params['userId'] ?? null;
        $field = $params['field'];
        $originalValue = $params['originalValue'] ?? null;
        $newValue = $params['newValue'] ?? null;
        $createdBy = $user?->name ?? 'System';
        $roleName = $user?->role?->name ?? 'User';
        $actorId = $userId;

        // Auto-resolve enquiry reference
        $enquiryRef = Enquiry::where('id', $params['enquiryId'])->value('referenceNo') ?? "#{$params['enquiryId']}";

        $comments = self::buildComments(
            $field,
            $originalValue,
            $newValue,
            $createdBy,
            $enquiryRef,
            $roleName,
            $actorId,
            $params['enquiryId'],
            $params['comments'] ?? null
        );

        if (! empty($params['extraData']) && is_array($params['extraData'])) {
            $comments = array_merge($comments, $params['extraData']);
        }

        return EnquiryHistory::create([
            'enquiryId' => $params['enquiryId'],
            'userId' => $userId,
            'field' => $field,
            'originalValue' => is_array($originalValue) ? json_encode($originalValue) : $originalValue,
            'newValue' => is_array($newValue) ? json_encode($newValue) : $newValue,
            'comments' => is_array($comments) ? json_encode($comments) : $comments,
        ]);
    }

    private static function buildComments(string $field, $originalValue, $newValue, string $createdBy, string $enquiryRef, string $roleName, ?int $actorId, int $enquiryId, ?string $rawMessage = null): array
    {
        $actorLink = self::userLink($actorId, $createdBy, $roleName);
        $enquiryLink = self::enquiryLink($enquiryId, $enquiryRef);

        $comments = [
            'action' => $field,
            'createdBy' => $createdBy,
            'role' => $roleName,
            'enquiry' => $enquiryRef,
        ];

        // For update actions with array changes — build per-field human-readable message
        if ($field === 'updated' && is_array($originalValue) && is_array($newValue)) {
            $changes = [];
            $changeSentences = [];

            foreach ($newValue as $key => $newVal) {
                $oldVal = $originalValue[$key] ?? null;
                $label = self::$fieldLabels[$key] ?? self::formatFieldName($key);

                $change = [
                    'field' => $key,
                    'label' => $label,
                    'from' => $oldVal,
                    'to' => $newVal,
                ];

                // Resolve display values for ID fields (agent, contact, listing, etc.)
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

                // Resolve enum values (status, enquiryType, listingType, etc.)
                if (isset(self::$enumResolvers[$key])) {
                    $enumMap = self::$enumResolvers[$key];
                    if ($oldVal && isset($enumMap[$oldVal])) {
                        $displayFrom = $enumMap[$oldVal];
                        $change['fromLabel'] = $displayFrom;
                    }
                    if ($newVal && isset($enumMap[$newVal])) {
                        $displayTo = $enumMap[$newVal];
                        $change['toLabel'] = $displayTo;
                    }
                }

                // Resolve boolean fields
                if (in_array($key, ['isOpenGame', 'isQualified', 'isActive'])) {
                    $displayFrom = $oldVal ? 'Yes' : 'No';
                    $displayTo = $newVal ? 'Yes' : 'No';
                    $change['fromLabel'] = $displayFrom;
                    $change['toLabel'] = $displayTo;
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
                $comments['message'] = "{$actorLink} has updated {$changeSentences[0]} on enquiry {$enquiryLink}.";
            } else {
                $bulletList = implode('; ', $changeSentences);
                $comments['message'] = "{$actorLink} has updated the following on enquiry {$enquiryLink}: {$bulletList}.";
            }

            return $comments;
        }

        // For status change actions
        if ($field === 'status') {
            $oldStatus = self::extractMessageValue($originalValue);
            $newStatus = self::extractMessageValue($newValue);

            $oldLabel = Constants::ENQUIRY_STATUS[$oldStatus] ?? ucfirst($oldStatus ?? 'unknown');
            $newLabel = Constants::ENQUIRY_STATUS[$newStatus] ?? ucfirst($newStatus ?? 'unknown');

            $comments['message'] = "{$actorLink} changed the status from \"<strong>{$oldLabel}</strong>\" to \"<strong>{$newLabel}</strong>\" on enquiry {$enquiryLink}.";

            return $comments;
        }

        // For boolean toggle actions (isOpenGame, isQualified)
        if (in_array($field, ['isOpenGame', 'isQualified'])) {
            $label = self::$fieldLabels[$field] ?? self::formatFieldName($field);
            $oldVal = self::extractMessageValue($originalValue);
            $newVal = self::extractMessageValue($newValue);

            // System-triggered Open Game: "1 - {Status}: {reason}" → trimmed message
            if ($field === 'isOpenGame' && is_string($newVal) && preg_match('/^1 - [^:]+:\s*(.+)$/', $newVal, $m)) {
                $reason = ucfirst(trim($m[1]));
                $comments['message'] = "{$reason} on enquiry {$enquiryLink}.";

                return $comments;
            }

            $comments['message'] = "{$actorLink} changed {$label} from \"{$oldVal}\" to \"{$newVal}\" on enquiry {$enquiryLink}.";

            return $comments;
        }

        // For all other actions — use action label map
        $actionText = self::$actionLabels[$field] ?? str_replace('_', ' ', $field);
        $extraDetail = self::extractMessageValue($newValue);

        if ($extraDetail && ! in_array($field, ['favorited', 'unfavorited', 'followed', 'unfollowed'])) {
            $comments['message'] = "{$actorLink} {$actionText} on enquiry {$enquiryLink} — {$extraDetail}.";
        } else {
            $comments['message'] = "{$actorLink} {$actionText} {$enquiryLink}.";
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
     * Generate an HTML link for an enquiry.
     */
    private static function enquiryLink(int $id, string $ref): string
    {
        $baseUrl = config('app.frontend_url').'/dashboard';

        return "<a href=\"{$baseUrl}/enquiries/{$id}/view\">{$ref}</a>";
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
}
