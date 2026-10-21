<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * property_meta had no indexes beyond the primary key, so every lookup
     * (the per-property `meta` eager-load and especially the off-plan matcher's
     * correlated EXISTS subqueries over `propertyId = X AND metaKey IN (...)`)
     * was a full table scan. On a multi-row-per-property table that is the main
     * driver of the DB CPU spikes under concurrent CRM usage.
     *
     * table => [ [indexName, [columns...]], ... ]
     */
    private array $indexes = [
        'property_meta' => [
            ['idx_propertyId_metaKey', ['propertyId', 'metaKey']],
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
     * On MySQL, build with online DDL (ALGORITHM=INPLACE, LOCK=NONE) so the table
     * stays readable and writable while the index builds. Other drivers (SQLite in
     * tests) use the portable schema builder.
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
