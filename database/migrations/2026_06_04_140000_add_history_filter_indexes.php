<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * table => [ [indexName, [columns...]], ... ]
     */
    private array $indexes = [
        // Avg-response-time aggregation was full-scanning ~200k rows.
        'enquiry_histories' => [
            ['idx_enquiryId_userId_created', ['enquiryId', 'userId', 'created_at']],
        ],
        // Previously had no indexes at all.
        'contact_notes' => [
            ['idx_type_createdBy_created', ['type', 'createdBy', 'created_at']],
        ],
        'login_histories' => [
            ['idx_userId_loginAt', ['userId', 'loginAt']],
        ],
        // Backs the viewings/meetings filter (by type) per agent.
        'tasks' => [
            ['idx_assignedTo_type_dueDate', ['assignedTo', 'type', 'dueDate']],
        ],
        // Backs the sales/leasing (listingType) filter per agent.
        'closings' => [
            ['idx_agent_listingType_status', ['assignedAgentId', 'listingType', 'status']],
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

            Schema::table($table, function (Blueprint $blueprint) use ($defs, $existing) {
                foreach ($defs as [$name, $columns]) {
                    if (in_array(strtolower($name), $existing, true)) {
                        $blueprint->dropIndex($name);
                    }
                }
            });
        }
    }

    /**
     * On MySQL, build the index with online DDL (ALGORITHM=INPLACE, LOCK=NONE)
     * so large tables (e.g. enquiry_histories ~200k rows) stay readable and
     * writable during the build. Other drivers (SQLite in tests) use the
     * portable schema builder.
     */
    private function addIndex(string $table, string $name, array $columns): void
    {
        if (DB::getDriverName() === 'mysql') {
            $cols = implode(', ', array_map(fn ($c) => "`{$c}`", $columns));
            DB::statement("ALTER TABLE `{$table}` ADD INDEX `{$name}` ({$cols}), ALGORITHM=INPLACE, LOCK=NONE");

            return;
        }

        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
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
