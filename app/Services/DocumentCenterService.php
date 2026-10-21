<?php

namespace App\Services;

use App\Constants\Constants;
use App\Models\DocumentCenter;
use App\Repositories\DocumentCenterRepository;
use Illuminate\Http\Request;

class DocumentCenterService
{
    public function __construct(
        protected DocumentCenterRepository $documentCenterRepository,
        protected FileStorageService $fileStorage
    ) {}

    /**
     * Relocate a document's file to match the requested visibility and persist the
     * change (filePath + isPublic). Returns the outcome:
     *   - 'moved'     : the file was relocated between buckets and the row updated
     *   - 'unchanged' : the document is already at the requested visibility
     *   - 'skipped'   : not an S3 object (empty path or legacy local-disk file) — cannot relocate
     *
     * Crash-safe: if a previous run already copied the object to the target bucket
     * without persisting the row, the existing object is adopted and any leftover in
     * the source bucket is cleaned up, instead of moving again.
     */
    public function setVisibility(DocumentCenter $document, bool $toPublic): string
    {
        if ((bool) $document->isPublic === $toPublic) {
            return 'unchanged';
        }

        $path = (string) $document->filePath;

        if ($path === '' || str_starts_with($path, 'document-center/')) {
            return 'skipped';
        }

        $key = $this->fileStorage->keyFromUrl($path) ?? $path;

        $targetHasIt = $toPublic
            ? $this->fileStorage->existsPublic($key)
            : $this->fileStorage->existsPrivate($key);

        if ($targetHasIt) {
            // The object already exists in the target bucket (interrupted prior run).
            // Adopt it and remove any leftover copy in the source bucket.
            $newRef = $toPublic ? $this->fileStorage->publicUrl($key) : $key;

            if ($toPublic && $this->fileStorage->existsPrivate($key)) {
                $this->fileStorage->deletePrivate($key);
            } elseif (! $toPublic && $this->fileStorage->existsPublic($key)) {
                $this->fileStorage->deletePublicByUrl($this->fileStorage->publicUrl($key));
            }
        } else {
            $newRef = $this->fileStorage->moveDocumentVisibility($path, (bool) $document->isPublic, $toPublic);
        }

        $document->update(['filePath' => $newRef, 'isPublic' => $toPublic]);

        return 'moved';
    }

    public function getAll(Request $request)
    {
        return $this->documentCenterRepository->getAll($request);
    }

    public function create(array $data)
    {
        $data['referenceNo'] = $this->generateReferenceNo($data['module']);

        return $this->documentCenterRepository->create($data);
    }

    public function generateReferenceNo(string $module): string
    {
        $prefix = Constants::DOCUMENT_REFERENCE_PREFIX[$module] ?? strtoupper(substr($module, 0, 2));

        $lastDocument = DocumentCenter::where('module', $module)
            ->whereNotNull('referenceNo')
            ->orderBy('id', 'desc')
            ->first();

        $nextNumber = 1;
        if ($lastDocument && $lastDocument->referenceNo) {
            if (preg_match('/(\d+)$/', $lastDocument->referenceNo, $matches)) {
                $nextNumber = (int) $matches[1] + 1;
            }
        }

        return $prefix.'-'.str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
    }

    public function getById(int $id)
    {
        return $this->documentCenterRepository->findById($id);
    }

    public function update(int $id, array $data)
    {
        return $this->documentCenterRepository->update($id, $data);
    }

    public function delete(int $id): bool
    {
        return $this->documentCenterRepository->delete($id);
    }
}
