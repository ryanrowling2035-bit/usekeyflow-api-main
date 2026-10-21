<?php

namespace App\Services;

use App\Constants\Constants;
use App\Models\Contact;
use App\Models\PropertyCommunity;
use App\Models\PropertyLocation;
use App\Models\PropertySubCommunity;
use App\Models\PropertyTower;
use App\Models\User;
use App\Models\UserActivity;
use App\Repositories\UserActivityRepository;
use Illuminate\Http\Request;

class UserActivityService
{
    protected UserActivityRepository $repository;

    /**
     * Field-to-model resolver map.
     * Each entry: 'fieldName' => [Model::class, 'lookupColumn', 'displayColumn']
     */
    private static array $fieldResolvers = [
        'agentId' => [User::class, 'id', 'name'],
        'assignedAgentId' => [User::class, 'id', 'name'],
        'createdBy' => [User::class, 'id', 'name'],
        'approvedBy' => [User::class, 'id', 'name'],
        'publishedBy' => [User::class, 'id', 'name'],
        'contactId' => [Contact::class, 'id', 'name'],
        'buyerId' => [Contact::class, 'id', 'name'],
        'physicalOwnerId' => [Contact::class, 'id', 'name'],
        'assignedTo' => [User::class, 'id', 'name'],
        'userId' => [User::class, 'id', 'name'],
        'pfCity' => [PropertyLocation::class, 'locationId', 'name'],
        'pfCommunity' => [PropertyCommunity::class, 'communityId', 'name'],
        'pfSubCommunity' => [PropertySubCommunity::class, 'propertySubCommunityId', 'name'],
        'pfBuilding' => [PropertyTower::class, 'propertyTowerId', 'name'],
    ];

    /**
     * Fields that reference a User ID (for link generation).
     */
    private static array $userFields = ['agentId', 'assignedAgentId', 'createdBy', 'approvedBy', 'publishedBy', 'assignedTo', 'userId'];

    /**
     * Human-readable field labels per module.
     */
    private static array $fieldLabels = [
        Constants::MODULE_PROPERTY => [
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
            'pfPriceFrequency' => 'Rent Frequency',
            'pfCheques' => 'No. of Cheques',
            'bayutOccupancyStatus' => 'Occupancy Status',
            'bayutMinimumContractPeriod' => 'Min Contract Period',
            'bayutVacatingNoticePeriod' => 'Vacating Notice Period',
            'bayutMaintenanceFee' => 'Maintenance Fee',
            'bayutMaintenanceFeePaidBy' => 'Maintenance Fee Paid By',
            'createdBy' => 'Created By',
            'status' => 'Status',
            'approvalStatus' => 'Approval Status',
            'approvedBy' => 'Approved By',
            'publishedBy' => 'Published By',
        ],
        Constants::MODULE_ENQUIRY => [
            'referenceNo' => 'Reference No',
            'phone' => 'Phone',
            'enquiryType' => 'Enquiry Type',
            'status' => 'Status',
            'listingType' => 'Listing Type',
            'leadSource' => 'Lead Source',
            'contactId' => 'Contact',
            'agentId' => 'Agent',
            'assignedAgentId' => 'Assigned Agent',
            'comments' => 'Comments',
            'createdBy' => 'Created By',
        ],
        Constants::MODULE_OFFER => [
            'name' => 'Name',
            'referenceNo' => 'Reference No',
            'status' => 'Status',
            'offeredAmount' => 'Offered Amount',
            'listingType' => 'Listing Type',
            'buyerId' => 'Buyer',
            'assignedAgentId' => 'Assigned Agent',
            'sellerLandlord' => 'Seller/Landlord',
            'terms' => 'Terms',
            'createdBy' => 'Created By',
        ],
        Constants::MODULE_CLOSING => [
            'name' => 'Name',
            'status' => 'Status',
            'listingType' => 'Listing Type',
            'buyerId' => 'Buyer',
            'assignedAgentId' => 'Assigned Agent',
            'sellerLandlord' => 'Seller/Landlord',
            'saleRentalPrice' => 'Sale/Rental Price',
            'buyerCommission' => 'Buyer Commission',
            'sellerCommission' => 'Seller Commission',
            'totalCommission' => 'Total Commission',
            'targetCloseDate' => 'Target Close Date',
            'closingDate' => 'Closing Date',
            'createdBy' => 'Created By',
        ],
        Constants::MODULE_CONTACT => [
            'name' => 'Name',
            'phone' => 'Phone',
            'email' => 'Email',
            'contactType' => 'Contact Type',
            'source' => 'Source',
            'address' => 'Address',
            'nationality' => 'Nationality',
            'createdBy' => 'Created By',
        ],
        Constants::MODULE_TASK => [
            'title' => 'Title',
            'status' => 'Status',
            'dueDate' => 'Due Date',
            'priority' => 'Priority',
            'assignedTo' => 'Assigned To',
            'createdBy' => 'Created By',
        ],
    ];

    /**
     * Module-specific enum resolvers.
     */
    private static function enumResolvers(): array
    {
        return [
            Constants::MODULE_PROPERTY => [
                'status' => Constants::PROPERTY_STATUS,
                'approvalStatus' => Constants::PROPERTY_APPROVAL_STATUS,
                'listingType' => Constants::PROPERTY_PURPOSE_TYPE,
                'completionStatus' => Constants::COMPLETION_STATUS,
                'category' => Constants::PROPERTY_CLASSIFICATION_TYPE,
                'pfPropertyType' => Constants::pfPropertyTypeLabels(),
                'pfPriceFrequency' => Constants::PROPERTY_PRICE_FREQUENCIES,
                'pfCheques' => Constants::PF_NUMBER_OF_CHEQUES,
                'bayutOccupancyStatus' => Constants::BAYUT_OCCUPANCY_STATUS,
                'bayutMaintenanceFeePaidBy' => Constants::BAYUT_MAINTENANCE_PAID_BY,
            ],
            Constants::MODULE_ENQUIRY => [
                'status' => Constants::ENQUIRY_STATUS,
                'enquiryType' => Constants::ENQUIRY_TYPE,
                'listingType' => Constants::PROPERTY_PURPOSE_TYPE,
            ],
            Constants::MODULE_OFFER => [
                'status' => Constants::OFFER_STATUS,
            ],
            Constants::MODULE_TASK => [
                'status' => Constants::TASK_STATUS,
            ],
            Constants::MODULE_USER => [
                'status' => Constants::USER_STATUS,
            ],
        ];
    }

    /**
     * Human-readable action labels.
     */
    private static array $actionLabels = [
        'created' => 'created',
        'updated' => 'updated',
        'deleted' => 'deleted',
        'cloned' => 'cloned',
        'exported_csv' => 'exported to CSV',
        'email_sent' => 'sent an email',
        'compose_email' => 'composed and sent an email',
        'imported' => 'imported records',
        'status_updated' => 'updated the status',
        'linked' => 'linked a record',
        'unlinked' => 'unlinked a record',
    ];

    /**
     * Module display names.
     */
    private static array $moduleLabels = [
        Constants::MODULE_PROPERTY => 'property',
        Constants::MODULE_ENQUIRY => 'enquiry',
        Constants::MODULE_CONTACT => 'contact',
        Constants::MODULE_OFFER => 'offer',
        Constants::MODULE_CLOSING => 'closing',
        Constants::MODULE_TASK => 'task',
        Constants::MODULE_CALENDAR_EVENT => 'calendar event',
        Constants::MODULE_TEAM => 'team',
        Constants::MODULE_USER => 'user',
        Constants::MODULE_DOCUMENT => 'document',
        Constants::MODULE_EMAIL_TEMPLATE => 'email template',
        Constants::MODULE_SETTING => 'setting',
        Constants::MODULE_MARKETING_ASSET => 'marketing asset',
        Constants::MODULE_BOOST => 'boost',
        Constants::MODULE_FAVORITE => 'favorite',
        Constants::MODULE_FOLLOW => 'follow',
    ];

    public function __construct(UserActivityRepository $repository)
    {
        $this->repository = $repository;
    }

    public function getAll(Request $request)
    {
        return $this->repository->getAll($request);
    }

    public static function log(array $params): ?UserActivity
    {
        $user = auth('api')->user();

        $action = $params['action'] ?? null;
        $module = $params['module'] ?? null;
        $oldValues = $params['oldValues'] ?? null;
        $newValues = $params['newValues'] ?? null;

        if ($action === 'updated' && $oldValues && $newValues) {
            $changedOld = [];
            $changedNew = [];

            foreach ($newValues as $key => $newVal) {
                $oldVal = $oldValues[$key] ?? null;

                $oldValStr = is_array($oldVal) ? json_encode($oldVal) : (string) $oldVal;
                $newValStr = is_array($newVal) ? json_encode($newVal) : (string) $newVal;

                if ($oldValStr !== $newValStr) {
                    $changedOld[$key] = $oldVal;
                    $changedNew[$key] = $newVal;
                }
            }

            if (empty($changedNew)) {
                return null;
            }

            $oldValues = $changedOld;
            $newValues = $changedNew;
        }

        $description = $params['description'] ?? null;
        $comments = self::buildComments($action, $module, $params['moduleId'] ?? null, $oldValues, $newValues, $description, $user);

        if (! empty($params['extraData']) && is_array($params['extraData'])) {
            $comments = array_merge($comments, $params['extraData']);
        }

        return UserActivity::create([
            'userId' => $params['userId'] ?? $user?->id,
            'action' => $action,
            'module' => $module,
            'moduleId' => $params['moduleId'] ?? null,
            'description' => $comments,
            'oldValues' => $oldValues,
            'newValues' => $newValues,
            'ipAddress' => $params['ipAddress'] ?? request()->ip(),
        ]);
    }

    private static function buildComments(?string $action, ?string $module, $moduleId, $oldValues, $newValues, ?string $description, $user): array
    {
        $createdBy = $user?->name ?? 'System';
        $roleName = $user?->role?->name ?? 'User';
        $actorId = $user?->id;
        $actorLink = self::userLink($actorId, $createdBy, $roleName);
        $moduleLabel = self::$moduleLabels[$module] ?? $module ?? 'record';

        $comments = [
            'action' => $action,
            'createdBy' => $createdBy,
            'role' => $roleName,
            'module' => $module,
            'moduleId' => $moduleId,
        ];

        // For update actions with array changes — build per-field changes
        if ($action === 'updated' && is_array($oldValues) && is_array($newValues)) {
            $changes = [];
            $changeSentences = [];
            $moduleEnums = self::enumResolvers()[$module] ?? [];

            foreach ($newValues as $key => $newVal) {
                $oldVal = $oldValues[$key] ?? null;
                $moduleFieldLabels = self::$fieldLabels[$module] ?? [];
                $label = $moduleFieldLabels[$key] ?? self::formatFieldName($key);

                $change = [
                    'field' => $key,
                    'label' => $label,
                    'from' => $oldVal,
                    'to' => $newVal,
                ];

                $displayFrom = $oldVal;
                $displayTo = $newVal;

                // Resolve ID fields to names
                if (isset(self::$fieldResolvers[$key])) {
                    [$model, $lookupCol, $displayCol] = self::$fieldResolvers[$key];
                    if ($oldVal) {
                        $resolved = $model::where($lookupCol, $oldVal)->value($displayCol);
                        if ($resolved) {
                            $change['fromLabel'] = $resolved;
                            $displayFrom = in_array($key, self::$userFields)
                                ? self::userLink($oldVal, $resolved, self::getUserRoleName($oldVal))
                                : $resolved;
                        }
                    }
                    if ($newVal) {
                        $resolved = $model::where($lookupCol, $newVal)->value($displayCol);
                        if ($resolved) {
                            $change['toLabel'] = $resolved;
                            $displayTo = in_array($key, self::$userFields)
                                ? self::userLink($newVal, $resolved, self::getUserRoleName($newVal))
                                : $resolved;
                        }
                    }
                }

                // Resolve enum values
                if (isset($moduleEnums[$key])) {
                    $enumMap = $moduleEnums[$key];
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
            $moduleLink = self::moduleLink($module, $moduleId);

            if (count($changeSentences) === 1) {
                $comments['message'] = "{$actorLink} has updated {$changeSentences[0]} on {$moduleLabel} {$moduleLink}.";
            } else {
                $bulletList = implode('; ', $changeSentences);
                $comments['message'] = "{$actorLink} has updated the following on {$moduleLabel} {$moduleLink}: {$bulletList}.";
            }

            return $comments;
        }

        // For non-update actions
        $actionText = self::$actionLabels[$action] ?? str_replace('_', ' ', $action ?? '');
        $moduleLink = self::moduleLink($module, $moduleId);

        if ($description) {
            $comments['message'] = "{$actorLink} — {$description} ({$moduleLink})";
        } else {
            $comments['message'] = "{$actorLink} {$actionText} {$moduleLabel} {$moduleLink}.";
        }

        return $comments;
    }

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

    private static function formatFieldName(string $field): string
    {
        $spaced = preg_replace('/([a-z])([A-Z])/', '$1 $2', $field);

        return ucfirst($spaced);
    }

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

    private static function getUserRoleName($userId): ?string
    {
        $user = User::with('role')->find($userId);

        return $user?->role?->name ?? 'User';
    }

    private static function getModuleRoutes(): array
    {
        return [
            Constants::MODULE_PROPERTY => 'properties',
            Constants::MODULE_ENQUIRY => 'enquiries',
            Constants::MODULE_CONTACT => 'contacts',
            Constants::MODULE_OFFER => 'offers',
            Constants::MODULE_CLOSING => 'closings',
            Constants::MODULE_TASK => 'tasks',
            Constants::MODULE_TEAM => 'teams',
            Constants::MODULE_MARKETING_ASSET => 'marketing/assets',
        ];
    }

    private static function moduleLink(?string $module, $moduleId): string
    {
        if (! $module || ! $moduleId) {
            return "#{$moduleId}";
        }

        $routes = self::getModuleRoutes();
        $route = $routes[$module] ?? null;

        if (! $route) {
            return "#{$moduleId}";
        }

        $baseUrl = config('app.frontend_url').'/dashboard';
        $url = "{$baseUrl}/{$route}/{$moduleId}/view";

        return "<a href=\"{$url}\"><strong>#{$moduleId}</strong></a>";
    }
}
