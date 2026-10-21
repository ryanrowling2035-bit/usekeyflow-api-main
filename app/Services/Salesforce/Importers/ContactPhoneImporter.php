<?php

namespace App\Services\Salesforce\Importers;

use Illuminate\Support\Facades\DB;

/**
 * Backfills phone numbers onto contacts that were imported without one.
 *
 * Reads the same Contact.csv as ContactImporter but does one narrow job: for a Salesforce
 * contact already mapped to a CRM contact that currently has no phone, fill it in — taking
 * MobilePhone first and falling back to Phone (the reverse of the main importer, which leads
 * with Phone). It never creates a contact and never overwrites a phone the CRM already holds,
 * so it is safe to re-run.
 */
class ContactPhoneImporter extends ContactImporter
{
    /** Width of the contacts.phone column; longer values are truncated by clampToColumns. */
    private const PHONE_MAX_LEN = 20;

    /** crmId => true for contacts that already hold a phone (snapshot at run start). */
    private array $hasPhone = [];

    /** Phone-based shard filter (see setShard()); null = process every number. */
    private ?int $phoneShardIndex = null;

    private ?int $phoneShardTotal = null;

    public function label(): string
    {
        return 'Contact phone backfill';
    }

    /**
     * Shard by the phone value, not the Salesforce Id. The base Id-based sharding would split a
     * duplicate number across shards, and two parallel shards can't see each other's uncommitted
     * writes — so both would try to claim it and one would hit the UNIQUE phone constraint. Keying
     * the shard on the (clamped) phone keeps every occurrence of a number in one shard, where the
     * in-run dedup in transform()/beforeUpdate() handles it safely. We store our own shard state
     * and leave the base shard inactive; the actual filtering happens in transform().
     */
    public function setShard(?int $index, ?int $total): void
    {
        if ($index === null || $total === null || $total <= 1) {
            $this->phoneShardIndex = $this->phoneShardTotal = null;

            return;
        }
        $this->phoneShardIndex = $index;
        $this->phoneShardTotal = $total;
    }

    protected function beforeImport(): void
    {
        // Leave any contact that already has a phone untouched.
        foreach (DB::table('contacts')
            ->whereNotNull('phone')->where('phone', '!=', '')
            ->pluck('id') as $id) {
            $this->hasPhone[(int) $id] = true;
        }
    }

    /** Update-only: a row for a contact we never imported is skipped, never inserted. */
    protected function insertNewRecords(): bool
    {
        return false;
    }

    /** Only ever write the phone column. */
    protected function updateOnlyColumns(): array
    {
        return ['phone'];
    }

    /**
     * Emit a phone only for a mapped contact that currently has none — MobilePhone first,
     * Phone second. Every other row is skipped (and logged) rather than written.
     */
    protected function transform(array $row): ?array
    {
        $phone = $this->formatPhone($this->first($row, ['MobilePhone', 'Phone']));
        if ($phone === null) {
            return null;
        }

        // Use the value that will actually be stored (the column clamps to 20 chars) as both the
        // uniqueness key and the shard key, so numbers that would collide always travel together.
        $phone = mb_substr($phone, 0, self::PHONE_MAX_LEN);

        // Phone-based sharding: every row storing the same number lands in the same shard, so
        // parallel shards never contend for one value on the UNIQUE phone column (see setShard()).
        // crc32 is non-negative on 64-bit PHP.
        if ($this->phoneShardTotal !== null
            && crc32($phone) % $this->phoneShardTotal !== $this->phoneShardIndex) {
            return null;
        }

        $crmId = $this->resolve('Contact', $row['Id'] ?? null);
        if ($crmId === null || isset($this->hasPhone[$crmId])) {
            return null;
        }

        return ['phone' => $phone];
    }

    /**
     * phone is UNIQUE (varchar(20)): drop the value when another contact already owns it —
     * including one filled earlier in this same run — so a shared/duplicate number can't hit the
     * constraint. The check compares the value that will actually be stored: the column clamps
     * anything longer than 20 chars, so two over-length junk numbers sharing a 20-char prefix
     * would otherwise collide only after truncation. Runs correctly single-process, where each
     * update auto-commits and is visible to the next row's check (parallel shards would race).
     */
    protected function beforeUpdate(array $attrs, int $id, array $row): array
    {
        if (empty($attrs['phone'])) {
            return $attrs;
        }

        $attrs['phone'] = mb_substr((string) $attrs['phone'], 0, self::PHONE_MAX_LEN);
        if (DB::table('contacts')
            ->where('phone', $attrs['phone'])
            ->where('id', '!=', $id)
            ->exists()) {
            unset($attrs['phone']);
        }

        return $attrs;
    }
}
