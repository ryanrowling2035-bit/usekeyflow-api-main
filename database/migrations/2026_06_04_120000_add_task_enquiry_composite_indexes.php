<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * table => [ [indexName, [columns...]], ... ]
     */
    private array $indexes = [
        'tasks' => [
            ['idx_dueDate_status', ['dueDate', 'status']],
        ],
        'enquiries' => [
            ['idx_agentId_status', ['agentId', 'status']],
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
                    foreach ($columns as $column) {
                        if (! Schema::hasColumn($table, $column)) {
                            continue 2;
                        }
                    }

                    // Skip if the index already exists.
                    // getIndexes() returns names lowercased, so compare case-insensitively.
                    if ($existing->contains(fn ($idx) => strcasecmp($idx['name'], $name) === 0)) {
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
                    if ($existing->contains(fn ($idx) => strcasecmp($idx['name'], $name) === 0)) {
                        $blueprint->dropIndex($name);
                    }
                }
            });
        }
    }
};
