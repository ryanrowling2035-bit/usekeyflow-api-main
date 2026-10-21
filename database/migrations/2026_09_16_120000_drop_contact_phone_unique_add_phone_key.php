<?php

use App\Models\Contact;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phone is no longer a unique identifier. Two people genuinely share a number
     * (a couple, a family, an office line), so a duplicate is flagged for review
     * rather than rejected — see Contact::hasPotentialDuplicate().
     *
     * The UNIQUE index also never did the job it was added for: it compares the
     * literal string, so "00971502…" sailed past an existing "+971502…" and minted
     * a second record anyway. That is how 3,141 duplicate phone groups accumulated
     * underneath it.
     *
     * phoneKey replaces it with the rule the app has actually been using: the last
     * 9 digits of the normalised number, so every spelling of one number collapses
     * to one key. Indexed, because Contact::matchingPhone() was a full scan of
     * 165k rows — tolerable at import volumes, not on every create and every
     * duplicate badge.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('contacts', 'phoneKey')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->char('phoneKey', 9)->nullable()->after('phone');
            });
        }

        $this->dropIndexIfExists('contacts_phone_unique');

        // Plain index in its place: the contacts list filters on `phone LIKE %…%`,
        // and a prefix-less LIKE can still use it for a covering scan.
        $this->addIndexIfMissing('contacts_phone_index', ['phone']);
        $this->addIndexIfMissing('contacts_phonekey_index', ['phoneKey']);

        $this->backfillPhoneKeys();
    }

    public function down(): void
    {
        $this->dropIndexIfExists('contacts_phonekey_index');
        $this->dropIndexIfExists('contacts_phone_index');

        if (Schema::hasColumn('contacts', 'phoneKey')) {
            Schema::table('contacts', fn (Blueprint $table) => $table->dropColumn('phoneKey'));
        }

        // Restoring the UNIQUE index is only possible where the data still permits
        // it; by the time this runs, duplicates are expected to exist. Null the
        // later of each colliding pair first, as the original migration did for
        // placeholder numbers.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('
                UPDATE contacts c
                JOIN (
                    SELECT MIN(id) AS keepId, phone
                    FROM contacts
                    WHERE phone IS NOT NULL AND phone <> \'\'
                    GROUP BY phone
                    HAVING COUNT(*) > 1
                ) d ON d.phone = c.phone AND c.id <> d.keepId
                SET c.phone = NULL
            ');
        }

        Schema::table('contacts', fn (Blueprint $table) => $table->unique('phone'));
    }

    /**
     * Derive every existing row's key in PHP rather than SQL: the key comes from
     * normalizePhoneE164() first (so a local "042691610" grows its country code
     * before the suffix is taken), which MySQL cannot express. Chunked and written
     * one CASE statement per 1,000 rows — 165 statements instead of 165k, and no
     * single long-running UPDATE holding row locks across the whole table.
     */
    private function backfillPhoneKeys(): void
    {
        DB::table('contacts')
            ->select('id', 'phone')
            ->whereNotNull('phone')
            ->where('phone', '<>', '')
            ->orderBy('id')
            ->chunk(1000, function ($rows) {
                $cases = [];
                $ids = [];

                foreach ($rows as $row) {
                    $key = Contact::phoneKeyFor($row->phone);

                    if ($key === null) {
                        continue;
                    }

                    $cases[] = 'WHEN '.(int) $row->id.' THEN '.DB::getPdo()->quote($key);
                    $ids[] = (int) $row->id;
                }

                if ($ids === []) {
                    return;
                }

                DB::statement(
                    'UPDATE contacts SET phoneKey = CASE id '.implode(' ', $cases).' END'
                    .' WHERE id IN ('.implode(',', $ids).')'
                );
            });
    }

    /**
     * Build with online DDL on MySQL so 165k rows stay readable and writable while
     * the index lands — the same guard the slow-query index migration uses.
     */
    private function addIndexIfMissing(string $name, array $columns): void
    {
        if (in_array(strtolower($name), $this->existingIndexNames(), true)) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            $cols = implode(', ', array_map(fn ($c) => "`{$c}`", $columns));
            DB::statement("ALTER TABLE `contacts` ADD INDEX `{$name}` ({$cols}), ALGORITHM=INPLACE, LOCK=NONE");

            return;
        }

        Schema::table('contacts', fn (Blueprint $table) => $table->index($columns, $name));
    }

    private function dropIndexIfExists(string $name): void
    {
        if (! in_array(strtolower($name), $this->existingIndexNames(), true)) {
            return;
        }

        // Dropping an index is metadata-only on MySQL 8 — instant, no table rebuild.
        Schema::table('contacts', fn (Blueprint $table) => $table->dropIndex($name));
    }

    /** Existing index names, lowercased (getIndexes() lowercases names). */
    private function existingIndexNames(): array
    {
        return collect(Schema::getIndexes('contacts'))
            ->map(fn ($idx) => strtolower($idx['name']))
            ->all();
    }
};
