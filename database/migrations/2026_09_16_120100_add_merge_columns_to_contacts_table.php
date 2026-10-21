<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A merge is recorded on the duplicate itself — one merge, one duplicate row,
     * so no join table is needed. Merging three contacts into a master leaves three
     * duplicate rows, each pointing at the master.
     *
     * Merging changes no data. It does not rewrite a field on either contact and it
     * does not move enquiries, listings, offers or notes off the duplicate. The
     * duplicate simply becomes an alias of the master: hidden from the places you
     * browse or pick a contact, while everything it owns surfaces under the master
     * and anything referencing it resolves to the master.
     *
     * That is why there is nothing to snapshot — an unmerge has nothing to put back,
     * it just clears the flag.
     *
     * State reads off these columns:
     *   mergedIntoId IS NOT NULL AND revertedAt IS NULL  -> currently merged away
     *   revertedAt IS NOT NULL                           -> merged, then unmerged
     * revertedAt is what marks the unmerge, rather than clearing mergedIntoId, so
     * the record of which master it had been merged into survives the revert.
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            // The surviving contact this one is an alias of.
            $table->unsignedBigInteger('mergedIntoId')->nullable();
            $table->unsignedBigInteger('mergedBy')->nullable();
            $table->timestamp('mergedAt')->nullable();
            $table->unsignedBigInteger('revertedBy')->nullable();
            $table->timestamp('revertedAt')->nullable();
        });

        // Backs both hot reads: excluding aliases from every contact listing, and
        // collecting a master's aliases to roll their records up onto it.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `contacts` ADD INDEX `contacts_mergedintoid_index` (`mergedIntoId`), ALGORITHM=INPLACE, LOCK=NONE');

            return;
        }

        Schema::table('contacts', fn (Blueprint $table) => $table->index('mergedIntoId', 'contacts_mergedintoid_index'));
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex('contacts_mergedintoid_index');
            $table->dropColumn([
                'mergedIntoId',
                'mergedBy',
                'mergedAt',
                'revertedBy',
                'revertedAt',
            ]);
        });
    }
};
