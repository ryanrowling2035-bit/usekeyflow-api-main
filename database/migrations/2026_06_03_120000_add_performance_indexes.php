<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hot filter/join columns on the large tables (contacts is in the lakhs).
     * Each entry: table => [ [indexName, [columns...]], ... ]
     */
    private array $indexes = [
        'users' => [
            ['users_createdBy_index', ['createdBy']],
            ['users_roleId_index', ['roleId']],
        ],
        'contacts' => [
            ['contacts_createdBy_index', ['createdBy']],
            ['contacts_assignedAgentId_index', ['assignedAgentId']],
        ],
        'enquiries' => [
            ['enquiries_agent_open_status_index', ['agentId', 'isOpenGame', 'status']],
            ['enquiries_status_index', ['status']],
            ['enquiries_created_at_index', ['created_at']],
        ],
        'properties' => [
            ['properties_agent_status_index', ['agentId', 'status']],
            ['properties_approvalStatus_index', ['approvalStatus']],
            ['properties_created_at_index', ['created_at']],
        ],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $defs) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $existing = collect(Schema::getIndexes($table));

            Schema::table($table, function (Blueprint $blueprint) use ($table, $defs, $existing) {
                foreach ($defs as [$name, $columns]) {
                    // Skip if any column is missing or an equivalent index already exists.
                    if (! $this->columnsExist($table, $columns)) {
                        continue;
                    }
                    if ($this->indexExists($existing, $name, $columns)) {
                        continue;
                    }
                    $blueprint->index($columns, $name);
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $defs) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $existing = collect(Schema::getIndexes($table));

            Schema::table($table, function (Blueprint $blueprint) use ($defs, $existing) {
                foreach ($defs as [$name, $columns]) {
                    if ($existing->contains(fn ($idx) => $idx['name'] === $name)) {
                        $blueprint->dropIndex($name);
                    }
                }
            });
        }
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

    private function indexExists($existing, string $name, array $columns): bool
    {
        return $existing->contains(function ($idx) use ($name, $columns) {
            if ($idx['name'] === $name) {
                return true;
            }

            // Same leading columns in the same order → functionally duplicate.
            return array_values($idx['columns']) === array_values($columns);
        });
    }
};
