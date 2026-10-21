<?php

namespace App\Services\Salesforce\Importers;

use App\Constants\Constants;
use Illuminate\Support\Facades\DB;

class ContactImporter extends AbstractObjectImporter
{
    /** normalised phone key => CRM contact id */
    private array $phoneToId = [];

    private const CONTACT_TYPE_MAP = [
        'buyer' => Constants::CONTACT_TYPE_BUYER,
        'seller' => Constants::CONTACT_TYPE_SELLER,
        'landlord' => Constants::CONTACT_TYPE_LANDLORD,
        'tenant' => Constants::CONTACT_TYPE_TENANT,
        'renter' => Constants::CONTACT_TYPE_TENANT,
    ];

    public function objectType(): string
    {
        return 'Contact';
    }

    public function module(): string
    {
        return Constants::MODULE_CONTACT;
    }

    public function file(): string
    {
        return 'Contact.csv';
    }

    protected function table(): string
    {
        return 'contacts';
    }

    protected function beforeImport(): void
    {
        // Needed to resolve createdBy / contactOwner to CRM users.
        $this->loadMap('User');

        // Existing contact phones for duplicate prevention (phone is UNIQUE).
        foreach (DB::table('contacts')->get(['id', 'phone']) as $c) {
            $pk = $this->phoneKey($c->phone);
            if ($pk) {
                $this->phoneToId[$pk] = (int) $c->id;
            }
        }
    }

    /** Re-import only refreshes identity fields; all other CRM contact data is preserved. */
    protected function updateOnlyColumns(): array
    {
        return ['name', 'phone', 'email'];
    }

    /**
     * phone and email are UNIQUE. Skip overwriting either when the incoming value already
     * belongs to a different contact, so a re-import refreshes what it safely can instead of
     * aborting the whole run on a duplicate-key violation.
     */
    protected function beforeUpdate(array $attrs, int $id, array $row): array
    {
        foreach (['phone', 'email'] as $col) {
            if (! empty($attrs[$col]) && DB::table('contacts')
                ->where($col, $attrs[$col])
                ->where('id', '!=', $id)
                ->exists()) {
                unset($attrs[$col]);
            }
        }

        return $attrs;
    }

    protected function matchExisting(array $row, array $attrs): ?int
    {
        $pk = $this->phoneKey($attrs['phone'] ?? null);

        return ($pk && isset($this->phoneToId[$pk])) ? $this->phoneToId[$pk] : null;
    }

    protected function afterSave(int $crmId, array $row, bool $isNew): void
    {
        if (! $isNew) {
            return;
        }
        $pk = $this->phoneKey($this->formatPhone($this->first($row, ['Phone', 'MobilePhone'])));
        if ($pk) {
            $this->phoneToId[$pk] = $crmId;
        }
    }

    protected function transform(array $row): ?array
    {
        $name = trim((string) ($this->str($row['FirstName'] ?? null).' '.$this->str($row['LastName'] ?? null)));
        if ($name === '') {
            $name = $this->first($row, ['pba__CompanyName__c', 'Email', 'Phone']) ?? 'Unknown';
        }

        $isIndividual = $this->isTrue($row['pba__SystemIsIndividual__c'] ?? null);

        return [
            'name' => mb_substr($name, 0, 255),
            'salutation' => $this->str($row['Salutation'] ?? null, 50),
            'contactType' => ($ct = $this->lookup($row['pba__ContactType_pb__c'] ?? null, self::CONTACT_TYPE_MAP)) ? [$ct] : null,
            'contactCategory' => $isIndividual ? Constants::CONTACT_INDIVIDUAL : Constants::CONTACT_COMPANY,
            'phone' => $this->formatPhone($this->first($row, ['Phone', 'MobilePhone'])),
            'alternatePhone' => $this->formatPhone($this->first($row, ['HomePhone', 'AssistantPhone'])),
            'otherPhone' => $this->formatPhone($row['OtherPhone'] ?? null),
            'email' => $this->str($row['Email'] ?? null, 255),
            'source' => $this->str($this->first($row, ['LeadSource', 'pba__LeadSourceCategory_pb__c']), 50) ?? 'Manual',
            'leadSourceDetails' => $this->str($row['pba__LeadSourceDetail_pb__c'] ?? null),
            'routingSource' => $this->str($row['pba__LeadRoutingSource__c'] ?? null),
            'dob' => $this->parseDate($row['Birthdate'] ?? null),
            'notes' => $this->first($row, ['Description', 'Notes__c']),
            'title' => $this->str($row['Title'] ?? null),
            'companyName' => $this->str($row['pba__CompanyName__c'] ?? null),
            'leadRating' => $this->str($row['pba__LeadRating_pb__c'] ?? null, 50),
            'outcome' => $this->str($row['OUTCOME__c'] ?? null),
            'lastContacted' => $this->parseDate($row['pba__LastContactedDate_pb__c'] ?? null),
            'pastClient' => (int) $this->isTrue($row['pba__IsPastClient_pb__c'] ?? null),
            'whatsappLead' => 0,
            'bayutWhatsappInitiated' => (int) $this->isTrue($row['Buyer_WhatsApp_Initiated__c'] ?? null),
            'tenantWhatsappInitiated' => (int) $this->isTrue($row['Tenant_WhatsApp_initiated__c'] ?? null),
            'offPlanWhatsappInitiated' => (int) $this->isTrue($row['Off_plan_WhatsApp_Initiated__c'] ?? null),
            'referralSource' => $this->str($row['pba__ReferralSource__c'] ?? null),
            'referralPercentDue' => $this->num($row['pba__ReferralPercentDue__c'] ?? null),
            'referralFeePaidThru' => $this->parseDate($row['pba__ReferralFeePaidThruDate_pb__c'] ?? null),
            'address' => $this->str($row['MailingStreet'] ?? null),
            'city' => $this->str($row['MailingCity'] ?? null),
            'state' => $this->str($row['MailingState'] ?? null),
            'country' => $this->str($row['MailingCountry'] ?? null),
            'zipCode' => $this->str($row['MailingPostalCode'] ?? null, 50),
            'otherAddress' => $this->str($row['OtherStreet'] ?? null),
            'otherCity' => $this->str($row['OtherCity'] ?? null),
            'otherState' => $this->str($row['OtherState'] ?? null),
            'otherCountry' => $this->str($row['OtherCountry'] ?? null),
            'otherZipCode' => $this->str($row['OtherPostalCode'] ?? null, 50),
            'communityPreference' => json_encode($this->communityPreferences($row)),
            'createdBy' => $this->resolve('User', $this->first($row, ['CreatedById', 'OwnerId'])),
            'contactOwner' => $this->resolve('User', $row['OwnerId'] ?? null),
        ];
    }

    protected function identifier(array $row): string
    {
        $name = trim((string) ($this->str($row['FirstName'] ?? null).' '.$this->str($row['LastName'] ?? null)));

        return $this->first($row, ['Email', 'Phone', 'MobilePhone'])
            ?? ($name !== '' ? $name : (string) ($row['Id'] ?? ''));
    }

    protected function aliasEntries(string $sfId, array $row): array
    {
        $aliases = [['Contact', $sfId]];
        // Person Accounts mirror this contact — let Account references resolve here too.
        if (! empty($row['AccountId'])) {
            $aliases[] = ['Account', $row['AccountId']];
        }

        return $aliases;
    }

    private function communityPreferences(array $row): array
    {
        $prefs = [];
        if ($this->isTrue($row['HasOptedOutOfEmail'] ?? null)) {
            $prefs[] = 'emailOptOut';
        }
        if ($this->isTrue($row['pba__HasOptedOutOfAutomatedEmail__c'] ?? null)) {
            $prefs[] = 'automatedEmailOptOut';
        }
        if ($this->isTrue($row['pba__HasOptedOutOfEmailCampaign__c'] ?? null)) {
            $prefs[] = 'emailCampaignOptOut';
        }
        if ($this->isTrue($row['Whatsapp_Opt_Out__c'] ?? null)) {
            $prefs[] = 'whatsappOptOut';
        }
        if ($this->isTrue($row['DoNotCall'] ?? null)) {
            $prefs[] = 'doNotCall';
        }

        return $prefs;
    }
}
