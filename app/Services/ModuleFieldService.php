<?php

namespace App\Services;

use Illuminate\Support\Facades\Schema;

class ModuleFieldService
{
    /**
     * System fields that should be excluded from module fields
     */
    private const EXCLUDED_FIELDS = [
        'id',
        'updated_at',
        'deleted_at',
        'remember_token',
        'email_verified_at',
        'leadScoreLocked',
        'approvalStatus',
        'latestApprovalComment',
        'approvedBy',
        'publishedBy',
        'permitRequestedAt',
        'approvedAt',
        'publishedAt',
        'pfPropertyType',
        'bayutPropertyType',
        'projectText',
    ];

    /**
     * Module to table mapping
     */
    private const MODULE_TABLE_MAP = [
        'property' => 'properties',
        'enquiry' => 'enquiries',
        'contact' => 'contacts',
        'user' => 'users',
    ];

    /**
     * Meta tables mapping - for key-value pair tables
     */
    private const META_TABLE_MAP = [
        'userMeta' => 'user_meta',
    ];

    /**
     * Get all module fields dynamically from database schema
     */
    public function getAllModuleFields(): array
    {
        $moduleFields = [];

        // Get regular table fields
        foreach (self::MODULE_TABLE_MAP as $module => $table) {
            $moduleFields[$module] = $this->getTableFields($table);
        }

        // Expose last modified date for enquiries (excluded globally by default)
        if (isset($moduleFields['enquiry'])) {
            $moduleFields['enquiry'][] = 'updated_at';
        }

        // Property: the raw reraPermitNo column doubles as a RERA Permit and a
        // DTCM Code (Vacation Homes). Present it in the column picker as two
        // clearly labelled virtual fields — "RERA Permit #" and "DTCM #" —
        // instead of the ambiguous auto-formatted "Rera Permit No".
        if (isset($moduleFields['property'])) {
            $moduleFields['property'] = $this->withPropertyPermitFields($moduleFields['property']);
            // Furnishing is stored in property meta, not a column, so expose it as a
            // virtual field so it can be picked in the column widget / CSV export.
            $moduleFields['property'][] = ['fieldName' => 'furnishing', 'fieldLabel' => 'Furnished'];
        }

        // Get meta table fields (key-value pairs)
        foreach (self::META_TABLE_MAP as $metaModule => $metaTable) {
            $moduleFields[$metaModule] = $this->getMetaKeys($metaTable);
        }

        return $moduleFields;
    }

    /**
     * Replace the property module's raw `reraPermitNo` field with two labelled
     * virtual fields so the column picker offers a dedicated "RERA Permit #" and
     * "DTCM #" column. The matching data keys (`reraPermitNumber`, `dtcmNumber`)
     * are produced per listing type in GetAllPropertyResource.
     */
    private function withPropertyPermitFields(array $fields): array
    {
        $fields = array_values(array_filter(
            $fields,
            fn ($field) => $field !== 'reraPermitNo'
        ));

        $fields[] = ['fieldName' => 'reraPermitNumber', 'fieldLabel' => 'RERA Permit #'];
        $fields[] = ['fieldName' => 'dtcmNumber', 'fieldLabel' => 'DTCM #'];

        return $fields;
    }

    /**
     * Get fields for a specific module
     */
    public function getModuleFields(string $module): ?array
    {
        if (! isset(self::MODULE_TABLE_MAP[$module])) {
            return null;
        }

        return $this->getTableFields(self::MODULE_TABLE_MAP[$module]);
    }

    /**
     * Get columns from a database table
     */
    private function getTableFields(string $table): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        $columns = Schema::getColumnListing($table);

        // Filter out system fields
        return array_values(array_filter($columns, function ($column) {
            return ! in_array($column, self::EXCLUDED_FIELDS);
        }));
    }

    /**
     * Get meta keys from meta tables dynamically
     * Fetches all unique metaKey values from the meta table
     */
    private function getMetaKeys(string $table): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        try {
            // Get all unique meta keys from the database
            $metaKeys = \DB::table($table)
                ->select('metaKey')
                ->distinct()
                ->pluck('metaKey')
                ->toArray();

            // Remove underscore prefix from meta keys
            $metaKeys = array_map(function ($key) {
                return ltrim($key, '_');
            }, $metaKeys);

            return array_values($metaKeys);
        } catch (\Exception $e) {
            // If table is empty or error occurs, return empty array
            return [];
        }
    }

    /**
     * Get meta fields for a specific module
     */
    public function getMetaModuleFields(string $metaModule): ?array
    {
        if (! isset(self::META_TABLE_MAP[$metaModule])) {
            return null;
        }

        return $this->getMetaKeys(self::META_TABLE_MAP[$metaModule]);
    }

    /**
     * Add a new module-table mapping dynamically
     */
    public function addModuleTableMapping(string $module, string $table): array
    {
        if (Schema::hasTable($table)) {
            return $this->getTableFields($table);
        }

        return [];
    }
}
