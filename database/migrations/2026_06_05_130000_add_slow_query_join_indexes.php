<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Indexes for the correlated-subquery join columns surfaced by the slow query
     * log. The Contact "accessible by agent" filter (Contact::scopeAccessibleByAgent)
     * runs EXISTS subqueries that join on these columns; with no index MySQL was
     * examining 40–53 MILLION rows per query (200s+), which is what saturated the
     * DB CPU. tasks(moduleId, module) backs the per-enquiry/per-property task
     * lookup that was full-scanning ~440k rows.
     *
     * table => [ [indexName, [columns...]], ... ]
     */
    private array $indexes = [
        // exists(enquiries where contacts.id = enquiries.contactId ...)
        'enquiries' => [
            ['idx_contactId', ['contactId']],
        ],
        // exists(properties where contacts.id = properties.physicalOwnerId ...)
        'properties' => [
            ['idx_physicalOwnerId', ['physicalOwnerId']],
        ],
        // exists(offers where contacts.id = offers.buyerId)
        'offers' => [
            ['idx_buyerId', ['buyerId']],
        ],
        // tasks where moduleId = X and module = 'enquiry'
        'tasks' => [
            ['idx_moduleId_module', ['moduleId', 'module']],
        ],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $defs) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $existing = $this->existingIndexNames($table);

            foreach ($defs as [$name, $columns]) {
                // Skip if any column is missing or the index already exists.
                if (! $this->columnsExist($table, $columns) || in_array(strtolower($name), $existing, true)) {
                    continue;
                }

                $this->addIndex($table, $name, $columns);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $defs) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $existing = $this->existingIndexNames($table);

            Schema::table($table, function ($blueprint) use ($defs, $existing) {
                foreach ($defs as [$name, $columns]) {
                    if (in_array(strtolower($name), $existing, true)) {
                        $blueprint->dropIndex($name);
                    }
                }
            });
        }
    }

    /**
     * On MySQL, build with online DDL (ALGORITHM=INPLACE, LOCK=NONE) so large
     * tables stay readable and writable while the index builds. Other drivers
     * (SQLite in tests) use the portable schema builder.
     */
    private function addIndex(string $table, string $name, array $columns): void
    {
        if (DB::getDriverName() === 'mysql') {
            $cols = implode(', ', array_map(fn ($c) => "`{$c}`", $columns));
            DB::statement("ALTER TABLE `{$table}` ADD INDEX `{$name}` ({$cols}), ALGORITHM=INPLACE, LOCK=NONE");

            return;
        }

        Schema::table($table, fn ($blueprint) => $blueprint->index($columns, $name));
    }

    /** Existing index names, lowercased (getIndexes() lowercases names). */
    private function existingIndexNames(string $table): array
    {
        return collect(Schema::getIndexes($table))
            ->map(fn ($idx) => strtolower($idx['name']))
            ->all();
    }

    private function columnsExist(string $table, array $columns): bool
    {
        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }
};
