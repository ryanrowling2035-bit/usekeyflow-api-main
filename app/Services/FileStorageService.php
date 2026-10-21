<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Single choke-point for all AWS S3 access.
 *
 * Public files (images, marketing assets, brochures) are uploaded to the public
 * bucket and their full public URL is stored in the database — so existing
 * read sites that pass values through getImageUrl() keep working unchanged.
 *
 * Private files (confidential documents, ID cards, offer PDFs) are uploaded to
 * the private bucket and only their object key is stored; they are served to the
 * client exclusively through short-lived pre-signed URLs.
 */
class FileStorageService
{
    public const PUBLIC_DISK = 's3-public';

    public const PRIVATE_DISK = 's3-private';

    /**
     * Upload a file to the public bucket and return its full public URL.
     */
    public function uploadPublicFile(UploadedFile $file, string $folder, ?string $name = null): string
    {
        $name = $name ?? $this->uniqueName($file);
        Storage::disk(self::PUBLIC_DISK)->putFileAs($folder, $file, $name);

        return $this->publicUrl($this->buildKey($folder, $name));
    }

    /**
     * Write raw contents (e.g. an encoded image) to the public bucket and return its URL.
     */
    public function putPublic(string $key, string $contents): string
    {
        Storage::disk(self::PUBLIC_DISK)->put($key, $contents);

        return $this->publicUrl($key);
    }

    /**
     * Upload a file to the private bucket and return ONLY its object key.
     */
    public function uploadPrivateFile(UploadedFile $file, string $folder, ?string $name = null): string
    {
        $name = $name ?? $this->uniqueName($file);
        Storage::disk(self::PRIVATE_DISK)->putFileAs($folder, $file, $name);

        return $this->buildKey($folder, $name);
    }

    /**
     * Write raw contents to the private bucket and return ONLY its object key.
     */
    public function putPrivate(string $key, string $contents): string
    {
        Storage::disk(self::PRIVATE_DISK)->put($key, $contents);

        return $key;
    }

    /**
     * Generate a short-lived pre-signed URL for a private object key.
     */
    public function temporaryUrl(?string $key, ?int $minutes = null): ?string
    {
        if (empty($key)) {
            return null;
        }

        // Accept either a bare object key (native uploads store the key) or a full unsigned
        // private-bucket URL (Salesforce-imported rows store the URL). Reduce a URL back to its
        // key before signing — otherwise the whole URL is treated as the key and the bucket host
        // is prepended a second time, yielding a doubled, unusable link.
        if (Str::startsWith($key, ['http://', 'https://'])) {
            $key = $this->keyFromUrl($key) ?? $key;
        }

        $minutes = $minutes ?? (int) config('filesystems.signed_url_ttl', 10);

        return Storage::disk(self::PRIVATE_DISK)->temporaryUrl($key, now()->addMinutes($minutes));
    }

    /**
     * Build the full public URL for a public-bucket object key.
     */
    public function publicUrl(string $key): string
    {
        return Storage::disk(self::PUBLIC_DISK)->url($key);
    }

    /**
     * Build the full (unsigned) private-bucket URL for an object key. The object is not
     * publicly readable — to serve it, sign the key with temporaryUrl() instead.
     */
    public function privateUrl(string $key): string
    {
        return Storage::disk(self::PRIVATE_DISK)->url($key);
    }

    /**
     * Delete a public object given the full URL stored in the database.
     * Legacy values still pointing at the local "public" disk are also handled.
     */
    public function deletePublicByUrl(?string $url): void
    {
        if (empty($url)) {
            return;
        }

        $key = $this->keyFromPublicUrl($url);

        if ($key !== null) {
            Storage::disk(self::PUBLIC_DISK)->delete($key);

            return;
        }

        // Legacy local-disk file stored as "/storage/..." — clean it up where it lives.
        $localPath = preg_replace('#^/?storage/#', '', $url);
        if ($localPath && Storage::disk('public')->exists($localPath)) {
            Storage::disk('public')->delete($localPath);
        }
    }

    /**
     * File size in bytes for a public object given the full URL stored in the DB.
     * Handles legacy local-disk "/storage/..." values too. Returns null when the
     * object cannot be located.
     */
    public function sizePublicByUrl(?string $url): ?int
    {
        if (empty($url)) {
            return null;
        }

        $key = $this->keyFromPublicUrl($url);
        if ($key !== null && Storage::disk(self::PUBLIC_DISK)->exists($key)) {
            return Storage::disk(self::PUBLIC_DISK)->size($key);
        }

        // Legacy local public-disk file stored as "/storage/...".
        $localPath = preg_replace('#^/?storage/#', '', $url);
        if ($localPath && Storage::disk('public')->exists($localPath)) {
            return Storage::disk('public')->size($localPath);
        }

        return null;
    }

    /**
     * Copy a public object (given its stored URL) to a new sibling key and return
     * the new public URL. Legacy local-disk values are copied in place. Returns the
     * original value when there is nothing to copy.
     */
    public function copyPublicByUrl(?string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        $key = $this->keyFromPublicUrl($url);
        if ($key !== null && Storage::disk(self::PUBLIC_DISK)->exists($key)) {
            // Copy via get+put rather than S3 CopyObject — the latter performs ACL
            // operations the ACL-disabled (bucket-owner-enforced) bucket rejects.
            $newKey = $this->siblingKey($key);

            return $this->putPublic($newKey, Storage::disk(self::PUBLIC_DISK)->get($key));
        }

        // Legacy local public-disk file stored as "/storage/...".
        $relative = preg_replace('#^/?storage/#', '', $url);
        if ($relative && Storage::disk('public')->exists($relative)) {
            $newRelative = $this->siblingKey($relative);
            Storage::disk('public')->copy($relative, $newRelative);

            return '/storage/'.$newRelative;
        }

        return $url;
    }

    /**
     * Move a stored document between the private and public buckets to match a new
     * visibility, returning the new stored reference: a full public URL when
     * $toPublic is true, otherwise the bare private object key. Returns the
     * reference unchanged when it is empty or already in the target bucket.
     *
     * Used when a document's isPublic flag is flipped without re-uploading the file.
     */
    public function moveDocumentVisibility(?string $reference, bool $currentlyPublic, bool $toPublic): ?string
    {
        if (empty($reference) || $currentlyPublic === $toPublic) {
            return $reference;
        }

        // Reduce the stored reference to a bare object key. Native uploads already store a key;
        // Salesforce-imported private rows store a full unsigned bucket URL.
        $key = $this->keyFromUrl($reference) ?? $reference;

        if ($toPublic) {
            // Private -> public bucket.
            $url = $this->putPublic($key, Storage::disk(self::PRIVATE_DISK)->get($key));
            Storage::disk(self::PRIVATE_DISK)->delete($key);

            return $url;
        }

        // Public -> private bucket.
        $this->putPrivate($key, Storage::disk(self::PUBLIC_DISK)->get($key));
        Storage::disk(self::PUBLIC_DISK)->delete($key);

        return $key;
    }

    /**
     * Delete a private object by its key.
     */
    public function deletePrivate(?string $key): void
    {
        if (empty($key)) {
            return;
        }

        Storage::disk(self::PRIVATE_DISK)->delete($key);
    }

    /**
     * Resolve the object key from a stored public URL, or null if the value is
     * not an absolute URL (e.g. a legacy local path).
     *
     * The key is everything after the host. This holds for virtual-hosted S3
     * URLs (bucket.s3.region.amazonaws.com/key) and root-mounted CDN domains.
     */
    public function keyFromPublicUrl(string $url): ?string
    {
        return $this->keyFromUrl($url);
    }

    /**
     * Resolve the object key from any absolute S3/CDN URL — the path component after the host,
     * url-decoded. Host-agnostic, so it serves both the public and private buckets. Returns null
     * when the value is not an absolute URL (e.g. a legacy local path or a bare key).
     */
    public function keyFromUrl(string $url): ?string
    {
        if (! Str::startsWith($url, ['http://', 'https://'])) {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);

        return $path ? rawurldecode(ltrim($path, '/')) : null;
    }

    /**
     * Read the raw bytes of a stored reference (public S3 URL or legacy local path).
     * Returns null when the object cannot be found. Used by PDF/image pipelines that
     * need the file contents during rendering.
     */
    public function readBytes(?string $reference): ?string
    {
        if (empty($reference)) {
            return null;
        }

        if (Str::startsWith($reference, ['http://', 'https://'])) {
            $key = $this->keyFromPublicUrl($reference);
            if ($key === null) {
                return null;
            }

            // Single round trip: ask S3 for the object directly and treat a
            // missing key (or any read error) as "not found". The previous
            // exists()+get() pair doubled the S3 latency on every image, which
            // dominated multi-image PDF renders.
            try {
                return Storage::disk(self::PUBLIC_DISK)->get($key);
            } catch (\Throwable $e) {
                return null;
            }
        }

        $relative = preg_replace('#^/?storage/#', '', $reference);

        return $relative && Storage::disk('public')->exists($relative)
            ? Storage::disk('public')->get($relative)
            : null;
    }

    /**
     * Read the raw bytes of many references in a single parallel batch and
     * return a map of [reference => bytes|null].
     *
     * Public S3 URLs are fetched concurrently through the AWS SDK's async client
     * (all requests in flight over one curl multi handler), collapsing N
     * sequential S3 round trips into roughly one. Local/legacy paths and any
     * reference that cannot be resolved fall back to sequential readBytes(), so
     * the result is identical to calling readBytes() on each reference — only
     * dramatically faster for the image-heavy PDF path.
     */
    public function readManyBytes(array $references): array
    {
        $out = [];
        $s3Keys = [];

        foreach ($references as $ref) {
            if (empty($ref) || array_key_exists($ref, $out) || array_key_exists($ref, $s3Keys)) {
                continue;
            }

            if (Str::startsWith($ref, ['http://', 'https://'])) {
                $key = $this->keyFromPublicUrl($ref);
                if ($key !== null) {
                    $s3Keys[$ref] = $key;

                    continue;
                }
            }

            // Local/legacy path (or unresolvable URL): sequential read — rare in
            // the image-heavy PDF path.
            $out[$ref] = $this->readBytes($ref);
        }

        if (empty($s3Keys)) {
            return $out;
        }

        try {
            $client = Storage::disk(self::PUBLIC_DISK)->getClient();
            $bucket = config('filesystems.disks.'.self::PUBLIC_DISK.'.bucket');

            $promises = [];
            foreach ($s3Keys as $ref => $key) {
                $promises[$ref] = $client->getObjectAsync([
                    'Bucket' => $bucket,
                    'Key' => $key,
                ]);
            }

            $results = \GuzzleHttp\Promise\Utils::settle($promises)->wait();
            foreach ($results as $ref => $result) {
                $out[$ref] = ($result['state'] ?? null) === 'fulfilled'
                    ? (string) $result['value']['Body']
                    : null;
            }
        } catch (\Throwable $e) {
            // Whole batch failed (e.g. client unavailable) — degrade to
            // sequential reads so callers still get correct results.
            foreach ($s3Keys as $ref => $key) {
                if (! array_key_exists($ref, $out)) {
                    $out[$ref] = $this->readBytes($ref);
                }
            }
        }

        return $out;
    }

    public function existsPublic(string $key): bool
    {
        return Storage::disk(self::PUBLIC_DISK)->exists($key);
    }

    /** Whether a private-bucket object already exists. */
    public function existsPrivate(string $key): bool
    {
        return Storage::disk(self::PRIVATE_DISK)->exists($key);
    }

    public function deletePublicDirectory(string $directory): void
    {
        if (Storage::disk(self::PUBLIC_DISK)->exists($directory)) {
            Storage::disk(self::PUBLIC_DISK)->deleteDirectory($directory);
        }
    }

    /**
     * Download an object from the given disk to a temporary local file and return
     * its absolute path. Used by pipelines that need a real filesystem path
     * (ffmpeg, dompdf, ZipArchive). Caller is responsible for deleting the file.
     */
    public function downloadToTemp(string $disk, string $key): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 's3_');
        $extension = pathinfo($key, PATHINFO_EXTENSION);

        if ($extension !== '') {
            $withExt = $tmp.'.'.$extension;
            rename($tmp, $withExt);
            $tmp = $withExt;
        }

        file_put_contents($tmp, Storage::disk($disk)->get($key));

        return $tmp;
    }

    /**
     * Build a per-record storage prefix "{root}/{reference|fallback}", S3-key-safe.
     * Groups a record's uploads under its human reference number (e.g.
     * "properties/AC-S-000001"); falls back to the record id when no reference exists.
     */
    public function recordPrefix(string $root, ?string $reference, int|string|null $fallback = null): string
    {
        $ref = trim((string) $reference);
        if ($ref === '') {
            $ref = (string) ($fallback ?? 'misc');
        }
        $ref = preg_replace('/[^A-Za-z0-9._-]+/', '-', $ref); // spaces/slashes -> '-'

        return rtrim($root, '/').'/'.trim($ref, '/');
    }

    /**
     * Generate a collision-resistant filename preserving the original extension.
     */
    public function uniqueName(UploadedFile $file): string
    {
        $extension = $file->getClientOriginalExtension() ?: $file->guessExtension();

        return time().'_'.Str::uuid().($extension ? '.'.$extension : '');
    }

    private function buildKey(string $folder, string $name): string
    {
        return trim($folder, '/').'/'.ltrim($name, '/');
    }

    /**
     * Build a new unique key in the same folder as the given key.
     */
    public function siblingKey(string $key): string
    {
        $extension = pathinfo($key, PATHINFO_EXTENSION);
        $directory = pathinfo($key, PATHINFO_DIRNAME);
        $name = time().'_'.Str::uuid().($extension ? '.'.$extension : '');

        return ($directory && $directory !== '.' ? $directory.'/' : '').$name;
    }
}
