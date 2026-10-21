<?php

namespace App\Http\Controllers\Api;

use App\Constants\Constants;
use App\Http\Controllers\Controller;
use App\Jobs\NotifyDocumentStatusJob;
use App\Models\Closing;
use App\Models\DocumentCenter;
use App\Models\Enquiry;
use App\Models\Offer;
use App\Models\Property;
use App\Services\DocumentCenterService;
use App\Services\FileStorageService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DocumentCenterController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected DocumentCenterService $documentCenterService,
        protected FileStorageService $fileStorage
    ) {}

    /**
     * Resolve a stored document to a viewable URL based on its visibility.
     * Public documents live in the public bucket and are served via their permanent
     * URL; private documents live in the private bucket and are served via a
     * short-lived signed URL; legacy documents on the local "document-center/" disk
     * keep their public URL.
     */
    private function resolveDocumentUrl(DocumentCenter $document): ?string
    {
        $filePath = $document->filePath;

        if (empty($filePath)) {
            return null;
        }

        if (str_starts_with($filePath, 'document-center/')) {
            return getImageUrl($filePath);
        }

        // Public documents are stored as a full public URL — serve it as-is.
        if ($document->isPublic) {
            return getImageUrl($filePath);
        }

        // Private documents are stored as an object key — serve via a signed URL.
        return $this->fileStorage->temporaryUrl($filePath);
    }

    public function index(Request $request)
    {
        try {
            $documents = $this->documentCenterService->getAll($request);

            // Fetch property details for property-linked documents
            $propertyIds = $documents->getCollection()
                ->where('module', Constants::MODULE_PROPERTY)
                ->pluck('moduleId')
                ->unique()
                ->values();
            $properties = [];
            if ($propertyIds->isNotEmpty()) {
                $properties = Property::whereIn('id', $propertyIds)
                    ->get(['id', 'title', 'referenceNumber', 'category'])
                    ->keyBy('id')
                    ->toArray();
            }

            $documents->getCollection()->transform(function ($document) use ($properties) {
                $document->filePath = $this->resolveDocumentUrl($document);
                $document->property = $document->module === Constants::MODULE_PROPERTY
                    ? ($properties[$document->moduleId] ?? null)
                    : null;

                $docTypeMap = Constants::DOCUMENT_TYPES[$document->module] ?? [];
                $document->docTypeLabel = $docTypeMap[$document->docType] ?? $document->docType;

                return $document;
            });

            return $this->success($documents, 'Documents retrieved successfully');
        } catch (\Exception $e) {
            return $this->exceptionResponse('DocumentCenterController : index', $e);
        }
    }

    public function show(int $id)
    {
        try {
            $document = $this->documentCenterService->getById($id);

            if (! $document) {
                return $this->error('Document not found.', 422);
            }

            $document->filePath = $this->resolveDocumentUrl($document);

            if ($document->module === Constants::MODULE_PROPERTY) {
                $document->property = Property::where('id', $document->moduleId)
                    ->select(['id', 'title', 'referenceNumber', 'category'])
                    ->first();
            }

            $docTypeMap = Constants::DOCUMENT_TYPES[$document->module] ?? [];
            $document->docTypeLabel = $docTypeMap[$document->docType] ?? $document->docType;

            return $this->success($document, 'Document retrieved successfully');
        } catch (\Exception $e) {
            return $this->exceptionResponse('DocumentCenterController : show', $e);
        }
    }

    public function getByModule(string $module, string $moduleId)
    {
        try {
            $documents = DocumentCenter::where('module', $module)
                ->where('moduleId', $moduleId)
                ->with('creator:id,name')
                ->orderBy('id', 'desc')
                ->get();

            $documents->transform(function ($document) use ($module) {
                $document->filePath = $this->resolveDocumentUrl($document);

                $docTypeMap = Constants::DOCUMENT_TYPES[$module] ?? [];
                $document->docTypeLabel = $docTypeMap[$document->docType] ?? $document->docType;

                return $document;
            });

            return $this->success($documents, 'Documents retrieved successfully');
        } catch (\Exception $e) {
            return $this->exceptionResponse('DocumentCenterController : getByModule', $e);
        }
    }

    public function store(Request $request)
    {
        $validator = $this->validate($request, 'store');

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $data = $validator->validated();
            $data['createdBy'] = user()->id;

            $documents = [];

            if ($request->hasFile('uploadDocuments')) {
                $files = $request->file('uploadDocuments');

                // Visibility drives the bucket: public => public bucket (permanent URL stored),
                // private (default) => private bucket (only the object key stored).
                $isPublic = (bool) ($data['isPublic'] ?? false);

                // Group a record's documents under its module + reference number (id fallback).
                $ref = $this->moduleReference($data['module'], (int) $data['moduleId']);
                $folder = $this->fileStorage->recordPrefix('documents/'.$data['module'], $ref, $data['moduleId']);

                foreach ($files as $file) {
                    $originalName = time().'_'.$file->getClientOriginalName();
                    $docData = $data;
                    $docData['filePath'] = $isPublic
                        ? $this->fileStorage->uploadPublicFile($file, $folder, $originalName)
                        : $this->fileStorage->uploadPrivateFile($file, $folder, $originalName);
                    $docData['type'] = $file->getClientMimeType();
                    $docData['size'] = $file->getSize();

                    if (empty($docData['title'])) {
                        $docData['title'] = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                    }

                    $documents[] = $this->documentCenterService->create($docData);
                }
            }

            foreach ($documents as $doc) {
                $params = [
                    'action' => 'created',
                    'module' => Constants::MODULE_DOCUMENT,
                    'moduleId' => $doc->id,
                    'description' => "Created document: {$doc->title}",
                ];
                logUserActivity($params);
            }

            // Notify admins + the module's agent on upload, and re-check missing mandatory docs — queued.
            if (! empty($documents) && array_key_exists($data['module'] ?? '', Constants::MANDATORY_DOCUMENTS_BY_MODULE)) {
                $uploadedTypes = [];
                foreach ($documents as $doc) {
                    if (! empty($doc->docType)) {
                        $uploadedTypes[] = $doc->docType;
                    }
                    foreach ((array) $doc->tags as $tag) {
                        $uploadedTypes[] = $tag;
                    }
                }

                NotifyDocumentStatusJob::dispatch(
                    $data['module'],
                    (int) $data['moduleId'],
                    'uploaded',
                    array_values(array_unique($uploadedTypes)),
                    user()->id
                );
            }

            return $this->success($documents, 'Documents created successfully');
        } catch (\Exception $e) {
            return $this->exceptionResponse('DocumentCenterController : store', $e);
        }
    }

    public function update(Request $request, int $id)
    {

        // dd($request->all());
        $validator = $this->validate($request, 'update');

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $data = $validator->validated();

            $existing = DocumentCenter::find($id);

            if (! $existing) {
                return $this->error('Document not found.', 422);
            }

            // Target visibility: the incoming flag when present, otherwise the current one.
            $targetPublic = array_key_exists('isPublic', $data)
                ? (bool) $data['isPublic']
                : (bool) $existing->isPublic;

            if ($request->hasFile('uploadDocuments')) {
                $file = $request->file('uploadDocuments')[0];
                $originalName = time().'_'.$file->getClientOriginalName();

                // Resolve the owning record (module/moduleId may be absent from this request).
                $module = $data['module'] ?? $existing->module;
                $moduleId = $data['moduleId'] ?? $existing->moduleId;
                $ref = $this->moduleReference($module, (int) $moduleId);
                $folder = $this->fileStorage->recordPrefix('documents/'.$module, $ref, $moduleId);

                $data['filePath'] = $targetPublic
                    ? $this->fileStorage->uploadPublicFile($file, $folder, $originalName)
                    : $this->fileStorage->uploadPrivateFile($file, $folder, $originalName);
                $data['type'] = $file->getClientMimeType();
                $data['size'] = $file->getSize();
            } elseif (
                (bool) $existing->isPublic !== $targetPublic
                && ! empty($existing->filePath)
                && ! str_starts_with($existing->filePath, 'document-center/')
            ) {
                // Visibility flipped without a re-upload — relocate the object to the matching bucket.
                $data['filePath'] = $this->fileStorage->moveDocumentVisibility(
                    $existing->filePath,
                    (bool) $existing->isPublic,
                    $targetPublic
                );
            }

            $document = $this->documentCenterService->update($id, $data);

            if (! $document) {
                return $this->error('Document not found.', 422);
            }

            $params = [
                'action' => 'updated',
                'module' => Constants::MODULE_DOCUMENT,
                'moduleId' => $document->id,
                'description' => "Updated document: {$document->title}",
            ];
            logUserActivity($params);

            return $this->success($document, 'Document updated successfully');
        } catch (\Exception $e) {
            return $this->exceptionResponse('DocumentCenterController : update', $e);
        }
    }

    public function destroy(int $id)
    {
        try {
            $document = $this->documentCenterService->getById($id);

            if (! $document) {
                return $this->error('Document not found.', 422);
            }

            // Delete file from storage — from whichever bucket its visibility placed it in.
            if ($document->filePath && str_starts_with($document->filePath, 'document-center/')) {
                $this->fileStorage->deletePublicByUrl('/storage/'.$document->filePath);
            } elseif ($document->isPublic) {
                $this->fileStorage->deletePublicByUrl($document->filePath);
            } else {
                $this->fileStorage->deletePrivate($document->filePath);
            }

            $docTitle = $document->title;
            $docModule = $document->module;
            $docModuleId = $document->moduleId;
            $this->documentCenterService->delete($id);

            $params = [
                'action' => 'deleted',
                'module' => Constants::MODULE_DOCUMENT,
                'moduleId' => $id,
                'description' => "Deleted document: {$docTitle}",
            ];
            logUserActivity($params);

            // Re-check missing mandatory docs after removal — queued.
            if ($docModuleId && array_key_exists($docModule ?? '', Constants::MANDATORY_DOCUMENTS_BY_MODULE)) {
                NotifyDocumentStatusJob::dispatch($docModule, (int) $docModuleId, 'removed', [], user()->id);
            }

            return $this->success([], 'Document deleted successfully');
        } catch (\Exception $e) {
            return $this->exceptionResponse('DocumentCenterController : destroy', $e);
        }
    }

    /**
     * Issue a fresh short-lived signed URL for a confidential document.
     */
    public function download(int $id)
    {
        try {
            $document = $this->documentCenterService->getById($id);

            if (! $document) {
                return $this->error('Document not found.', 422);
            }

            $url = $this->resolveDocumentUrl($document);

            if (! $url) {
                return $this->error('Document file not found.', 422);
            }

            logUserActivity([
                'action' => 'downloaded',
                'module' => Constants::MODULE_DOCUMENT,
                'moduleId' => $document->id,
                'description' => "Generated download link for document: {$document->title}",
            ]);

            return $this->success([
                'downloadUrl' => $url,
                'fileName' => $document->title,
            ], 'Download link generated successfully');
        } catch (\Exception $e) {
            return $this->exceptionResponse('DocumentCenterController : download', $e);
        }
    }

    /**
     * Bulk-change document visibility: relocate the given documents between the private
     * and public buckets to match the requested action, preserving each object key.
     *
     * Payload: { "documents": [1,2,3], "action": "public" | "private" }
     *
     * Runs synchronously — intended for interactive multi-select. To move hundreds of
     * documents at once (e.g. all Floorplan/Brochure rows) use the chunked artisan
     * command documents:make-public-by-tag instead, so the request does not time out.
     */
    public function bulkMove(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'documents' => 'required|array|min:1|max:100',
            'documents.*' => 'integer',
            'action' => 'required|in:public,private',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $data = $validator->validated();
            $ids = array_values(array_unique($data['documents']));
            $toPublic = $data['action'] === 'public';

            $documents = DocumentCenter::whereIn('id', $ids)->get()->keyBy('id');

            $results = ['moved' => [], 'unchanged' => [], 'skipped' => [], 'failed' => [], 'notFound' => []];

            foreach ($ids as $id) {
                $document = $documents->get($id);

                if (! $document) {
                    $results['notFound'][] = $id;

                    continue;
                }

                try {
                    $status = $this->documentCenterService->setVisibility($document, $toPublic);
                    $results[$status][] = $id;

                    if ($status === 'moved') {
                        logUserActivity([
                            'action' => 'updated',
                            'module' => Constants::MODULE_DOCUMENT,
                            'moduleId' => $id,
                            'description' => "Set document {$document->title} to ".($toPublic ? 'public' : 'private'),
                        ]);
                    }
                } catch (\Throwable $e) {
                    $results['failed'][] = $id;
                }
            }

            return $this->success($results, 'Bulk visibility update completed');
        } catch (\Exception $e) {
            return $this->exceptionResponse('DocumentCenterController : bulkMove', $e);
        }
    }

    /**
     * Resolve the human reference number of the record a document belongs to, so its
     * uploads can be grouped under documents/{module}/{reference}/. Returns null for
     * modules without a reference (e.g. contact) — the id fallback then applies.
     */
    private function moduleReference(?string $module, int $moduleId): ?string
    {
        return match ($module) {
            Constants::MODULE_PROPERTY => Property::where('id', $moduleId)->value('referenceNumber'),
            Constants::MODULE_ENQUIRY => Enquiry::where('id', $moduleId)->value('referenceNo'),
            Constants::MODULE_OFFER => Offer::where('id', $moduleId)->value('referenceNo'),
            Constants::MODULE_CLOSING => Closing::where('id', $moduleId)->value('referenceNo'),
            default => null,
        };
    }

    private function validate(Request $request, string $action)
    {
        $validModules = implode(',', array_keys(Constants::CRM_MODULES));
        $validTags = collect(Constants::DOCUMENT_TAGS)->flatMap(fn ($tags) => array_keys($tags))->implode(',');

        $rules = [
            'module' => "string|in:{$validModules}",
            'moduleId' => 'integer',
            'title' => 'nullable|string|max:255',
            'uploadDocuments' => 'nullable|array',
            'uploadDocuments.*' => 'file|max:51200',
            'type' => 'nullable|string|max:255',
            'size' => 'nullable|string|max:255',
            'docType' => 'nullable|string|max:255',
            'tags' => 'nullable|array',
            'tags.*' => "string|in:{$validTags}",
            'isPublic' => 'nullable|boolean',
        ];

        if ($action === 'store') {
            $rules['module'] = "required|string|in:{$validModules}";
            $rules['moduleId'] = 'required|integer';
            $rules['uploadDocuments'] = 'required|array';
            $rules['uploadDocuments.*'] = 'file|max:51200';
        } else {
            foreach ($rules as $field => $rule) {
                if (! $request->has($field)) {
                    unset($rules[$field]);
                }
            }
        }

        return Validator::make($request->all() + $request->allFiles(), $rules);
    }
}
