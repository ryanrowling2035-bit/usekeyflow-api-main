<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Index on properties.referenceNumber. It backs the bulk re-import UPDATE that
     * joins properties -> properties_bkp on referenceNumber; with no index the join
     * full-scanned 28k rows per row and timed out. Also speeds any referenceNumber
     * lookup.
     */
    private string $table = 'properties';

    private string $name = 'idx_refno';

    private array $columns = ['referenceNumber'];

    public function up(): void
    {
        if (! Schema::hasTable($this->table) || ! Schema::hasColumn($this->table, $this->columns[0])) {
            return;
        }

        if (in_array(strtolower($this->name), $this->existingIndexNames(), true)) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            $cols = implode(', ', array_map(fn ($c) => "`{$c}`", $this->columns));
            DB::statement("ALTER TABLE `{$this->table}` ADD INDEX `{$this->name}` ({$cols}), ALGORITHM=INPLACE, LOCK=NONE");

            return;
        }

        Schema::table($this->table, fn ($blueprint) => $blueprint->index($this->columns, $this->name));
    }

    public function down(): void
    {
        if (! Schema::hasTable($this->table)) {
            return;
        }

        if (! in_array(strtolower($this->name), $this->existingIndexNames(), true)) {
            return;
        }

        Schema::table($this->table, fn ($blueprint) => $blueprint->dropIndex($this->name));
    }

    /** Existing index names, lowercased (getIndexes() lowercases names). */
    private function existingIndexNames(): array
    {
        return collect(Schema::getIndexes($this->table))
            ->map(fn ($idx) => strtolower($idx['name']))
            ->all();
    }
};
