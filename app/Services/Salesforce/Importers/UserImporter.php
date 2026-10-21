<?php

namespace App\Services\Salesforce\Importers;

use App\Constants\Constants;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserImporter extends AbstractObjectImporter
{
    private int $agentRoleId;

    /** email (lowercase) => CRM user id */
    private array $emailToId = [];

    public function objectType(): string
    {
        return 'User';
    }

    public function module(): string
    {
        return Constants::MODULE_USER;
    }

    public function file(): string
    {
        return 'User.csv';
    }

    protected function table(): string
    {
        return 'users';
    }

    protected function beforeImport(): void
    {
        // Agent role id, defaulting to 3 (the Agent role) when missing — roleId is never 0.
        $this->agentRoleId = (int) (DB::table('roles')->where('name', Constants::AGENT)->value('id')) ?: 3;

        foreach (DB::table('users')->get(['id', 'email']) as $u) {
            if ($u->email) {
                $this->emailToId[mb_strtolower($u->email)] = (int) $u->id;
            }
        }
    }

    protected function transform(array $row): ?array
    {
        // Only real, human Salesforce users — skip system/integration accounts.
        if (($row['UserType'] ?? '') !== 'Standard') {
            return null;
        }

        $email = $this->str($row['Email'] ?? null);
        if (! $email) {
            return null;
        }

        $name = trim((string) ($this->str($row['FirstName'] ?? null).' '.$this->str($row['LastName'] ?? null)));
        if ($name === '') {
            $name = $this->str($row['Username'] ?? null) ?? $email;
        }

        return [
            'roleId' => $this->agentRoleId,
            'name' => $name,
            'userName' => $this->str($row['Username'] ?? null, 255),
            'email' => mb_strtolower($email),
            'phone' => $this->formatPhone($this->first($row, ['Phone', 'MobilePhone', 'pba__PropertybasePhone__c'])),
            'agentCode' => $this->first($row, ['Agentcode__c', 'Broker_Id__c']),
            'agentType' => 'agent',
            'status' => $this->isTrue($row['IsActive'] ?? null)
                ? Constants::USER_STATUS_ACTIVE
                : Constants::USER_STATUS_INACTIVE,
            'website' => $this->str($row['pba__WebsiteURL__c'] ?? null, 255),
            'lastLogin' => $this->parseDateTime($row['LastLoginDate'] ?? null),
            'password' => Hash::make(Str::random(40)),
            'force_password_change' => false,
        ];
    }

    protected function identifier(array $row): string
    {
        return $this->first($row, ['Email', 'Username', 'Phone', 'MobilePhone'])
            ?? (string) ($row['Id'] ?? '');
    }

    protected function matchExisting(array $row, array $attrs): ?int
    {
        // Match on email only. Phone is not unique enough across Salesforce
        // users (shared desk/office numbers) and was collapsing distinct
        // people into one CRM user.
        if (! empty($attrs['email']) && isset($this->emailToId[$attrs['email']])) {
            return $this->emailToId[$attrs['email']];
        }

        return null;
    }

    protected function afterSave(int $crmId, array $row, bool $isNew): void
    {
        // Mirror Salesforce user profile fields into user_meta. Keys without a
        // Salesforce User column (gender, nationality, passport, visa, idCard) are
        // intentionally absent — there is no source field to populate them from.
        // Runs on re-import too so existing users backfill.
        $keys = Constants::USER_META_KEYS;
        $meta = [
            // Broker_Id__c is the numeric portal broker id (Bayut agentId).
            $keys['brokerId'] => $this->str($row['Broker_Id__c'] ?? null, 255),
            $keys['phone'] => $this->formatPhone($this->first($row, ['Phone', 'MobilePhone', 'pba__PropertybasePhone__c'])),
            // No dedicated WhatsApp field in Salesforce; the mobile is the WhatsApp number.
            $keys['whatsappNumber'] => $this->formatPhone($this->first($row, ['MobilePhone', 'Phone'])),
            $keys['address'] => $this->str($row['Street'] ?? null),
            $keys['country'] => $this->str($row['Country'] ?? null),
            $keys['state'] => $this->str($row['State'] ?? null),
            $keys['city'] => $this->str($row['City'] ?? null),
            $keys['zipCode'] => $this->str($row['PostalCode'] ?? null, 50),
            $keys['licenseNumber'] => $this->first($row, ['pba__LicenseNumber__c', 'pba__LicenseNumber2__c']),
            $keys['licenseExpiryDate'] => $this->parseDate($row['pba__LicenseExpirationDate_pb__c'] ?? null),
            $keys['description'] => $this->first($row, ['AboutMe__c', 'AboutMe', 'pba__Bio_pb__c', 'About_Me_PSP__c']),
            // No Salesforce source column; mirror the user's email as the PF public profile id.
            $keys['pfPublicProfileId'] => mb_strtolower((string) ($this->str($row['Email'] ?? null) ?? '')),
        ];

        $now = now();
        // Meta keys also listed in insertOnlyColumns() are CRM-managed (e.g. _licenseNumber,
        // _brokerId, _pfPublicProfileId, _defaultAdmin): backfill them when absent, but never
        // overwrite an existing value on re-import. All other keys refresh from Salesforce.
        $insertOnly = array_flip($this->insertOnlyColumns());
        $existing = $isNew ? [] : array_flip(
            DB::table('user_meta')->where('userId', $crmId)->pluck('metaKey')->all()
        );

        foreach ($meta as $metaKey => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (isset($insertOnly[$metaKey], $existing[$metaKey])) {
                continue; // insert-only meta already present — preserve the CRM value
            }
            DB::table('user_meta')->updateOrInsert(
                ['userId' => $crmId, 'metaKey' => $metaKey],
                ['metaValue' => (string) $value, 'updated_at' => $now, 'created_at' => $now],
            );
        }

        if (! $isNew) {
            return;
        }
        $email = mb_strtolower((string) ($this->str($row['Email'] ?? null) ?? ''));
        if ($email !== '') {
            $this->emailToId[$email] = $crmId;
        }
    }

    protected function hasSoftDeletes(): bool
    {
        return true;
    }

    /**
     * Preserve an existing user's password, role and status on re-import: passwords are
     * managed in the CRM (and may be set outside Salesforce), and role/status changes made
     * in the CRM must not be reverted to the Salesforce-derived default.
     */
    protected function insertOnlyColumns(): array
    {
        return ['password', 'email', '_licenseNumber', '_brokerId', '_pfPublicProfileId', '_defaultAdmin', 'roleId', 'status', 'agentCode', 'lastLogin'];
    }
}
