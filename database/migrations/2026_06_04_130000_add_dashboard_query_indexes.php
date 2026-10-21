<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * table => [ [indexName, [columns...]], ... ]
     */
    private array $indexes = [
        'tasks' => [
            ['idx_dueDate_status', ['dueDate', 'status']],
            ['idx_assignedTo_status', ['assignedTo', 'status']],
        ],
        'enquiries' => [
            ['idx_agentId_status', ['agentId', 'status', 'isOpenGame']],
            ['idx_created_at', ['created_at']],
        ],
        'properties' => [
            ['idx_status_approval', ['status', 'approvalStatus']],
        ],
        'closings' => [
            ['idx_status_agent', ['status', 'assignedAgentId']],
        ],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $defs) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $existing = $this->existingIndexNames($table);
            $clauses = [];

            foreach ($defs as [$name, $columns]) {
                // Skip if any column is missing or the index already exists.
                if (! $this->columnsExist($table, $columns)) {
                    continue;
                }
                if (in_array(strtolower($name), $existing, true)) {
                    continue;
                }

                $cols = implode(', ', array_map(fn ($c) => "`{$c}`", $columns));
                $clauses[] = "ADD INDEX `{$name}` ({$cols})";
            }

            if ($clauses === []) {
                continue;
            }

            // One ALTER per table → MySQL scans each table once instead of
            // once per index. INPLACE/LOCK=NONE keeps the table readable and
            // writable while the index builds (online DDL), so large tables
            // (e.g. tasks ~440k rows) don't block traffic.
            DB::statement(sprintf(
                'ALTER TABLE `%s` %s, ALGORITHM=INPLACE, LOCK=NONE',
                $table,
                implode(', ', $clauses)
            ));
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $defs) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $existing = $this->existingIndexNames($table);
            $clauses = [];

            foreach ($defs as [$name, $columns]) {
                if (in_array(strtolower($name), $existing, true)) {
                    $clauses[] = "DROP INDEX `{$name}`";
                }
            }

            if ($clauses === []) {
                continue;
            }

            DB::statement(sprintf('ALTER TABLE `%s` %s', $table, implode(', ', $clauses)));
        }
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
