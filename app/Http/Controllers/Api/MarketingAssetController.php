<?php

namespace App\Http\Controllers\Api;

use App\Constants\Constants;
use App\Http\Controllers\Controller;
use App\Http\Resources\MarketingAssetResources\GetAllMarketingAssetResource;
use App\Http\Resources\MarketingAssetResources\GetMarketingAssetResource;
use App\Models\MarketingAssetCategory;
use App\Services\FileStorageService;
use App\Services\MarketingAssetService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MarketingAssetController extends Controller
{
    use ApiResponse;

    /**
     * Uploadable file types, checked on the declared extension.
     *
     * Deliberately not `mimes:`, which validates the *sniffed* type and rejects
     * real spreadsheets: a single-column CSV sniffs as text/plain, a legacy .xls
     * as application/x-ole-storage (no guessable extension at all) and .ods as
     * application/octet-stream — all three fail `mimes:` however it is spelled.
     * The list carries no executable, HTML or SVG type, so it is the allowlist
     * doing the work here, and the route is already closed to agents.
     */
    private const UPLOAD_EXTENSIONS = 'pdf,doc,docx,ppt,pptx,jpg,jpeg,png,mp4,csv,xls,xlsx,xlsm,xltx,ods';

    public function __construct(
        private MarketingAssetService $marketingAssetService,
        private FileStorageService $fileStorage,
    ) {}

    public function index(Request $request)
    {
        try {
            $assets = $this->marketingAssetService->list($request);

            return $this->success(
                GetAllMarketingAssetResource::collection($assets),
                'Marketing assets retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('MarketingAssetController : index', $e);
        }
    }

    public function store(Request $request)
    {
        if (isAgent()) {
            return $this->error('Agents are not allowed to upload marketing assets.', 403);
        }

        $validator = Validator::make($request->all(), [
            'assetName' => 'required|string|max:255',
            'category' => $this->categoryRules(),
            'description' => 'nullable|string|max:1000',
            // Auto-detected on the frontend and sent comma-separated (e.g. "PDF,Image").
            'assetType' => 'nullable|string|max:100',
            'uploadFile' => 'required|array|min:1',
            'uploadFile.*' => 'file|max:51200|extensions:'.self::UPLOAD_EXTENSIONS,
            'status' => 'nullable|string|in:'.implode(',', array_keys(Constants::MARKETING_ASSET_STATUS)),
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        $data = $validator->validated();

        try {
            $fileNames = [];
            $filePaths = [];
            $assetSlug = str_replace(' ', '_', trim($data['assetName']));

            foreach ($request->file('uploadFile') as $file) {
                $originalName = str_replace(' ', '_', trim($file->getClientOriginalName()));
                $storedName = $assetSlug.'_'.time().'_'.$originalName;

                $fileNames[] = $originalName;
                $filePaths[] = $this->fileStorage->uploadPublicFile($file, 'marketing-assets', $storedName);
            }

            $assetData = [
                'assetName' => $data['assetName'],
                'category' => $data['category'] ?? null,
                'description' => $data['description'] ?? null,
                // Prefer the frontend's comma-separated detection; fall back to
                // detecting the distinct types from the uploaded files.
                'assetType' => ! empty($data['assetType'])
                    ? $data['assetType']
                    : $this->detectAssetTypes($request->file('uploadFile')),
                'fileName' => $fileNames,
                'filePath' => $filePaths,
                'uploadedBy' => auth()->id(),
                'status' => $data['status'] ?? 'active',
            ];

            $asset = $this->marketingAssetService->store($assetData);

            logUserActivity([
                'action' => 'created',
                'module' => Constants::MODULE_MARKETING_ASSET,
                'moduleId' => $asset->id,
                'description' => "Created marketing asset: {$asset->assetName}",
            ]);

            return $this->success(
                new GetMarketingAssetResource($asset),
                'Marketing asset created successfully'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('MarketingAssetController : store', $e);
        }
    }

    public function show(string $id)
    {
        try {
            $asset = $this->marketingAssetService->getById($id);

            if (! $asset) {
                return $this->error('The requested marketing asset was not found.', 422);
            }

            return $this->success(
                new GetMarketingAssetResource($asset),
                'Marketing asset retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('MarketingAssetController : show', $e);
        }
    }

    public function update(Request $request, string $id)
    {
        if (isAgent()) {
            return $this->error('Agents are not allowed to edit marketing assets.', 403);
        }

        $validator = Validator::make($request->all(), [
            'assetName' => 'sometimes|required|string|max:255',
            'category' => $this->categoryRules(true),
            'description' => 'sometimes|nullable|string|max:1000',
            // Frontend re-detects on edit and sends comma-separated (e.g. "PDF,Image").
            'assetType' => 'sometimes|nullable|string|max:100',
            'uploadFile' => 'sometimes|array',
            'uploadFile.*' => 'file|max:51200|extensions:'.self::UPLOAD_EXTENSIONS,
            'deletedFileIds' => 'sometimes|array',
            'deletedFileIds.*' => 'string',
            'status' => 'sometimes|nullable|string|in:'.implode(',', array_keys(Constants::MARKETING_ASSET_STATUS)),
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        $data = $validator->validated();
        $deletedFileIds = $data['deletedFileIds'] ?? [];
        unset($data['uploadFile'], $data['deletedFileIds']);

        try {
            $asset = $this->marketingAssetService->update($id, $data);

            $fileNames = $asset->fileName ?? [];
            $filePaths = $asset->filePath ?? [];

            // Drop the files the client removed in the edit form.
            if (! empty($deletedFileIds)) {
                $remainingNames = [];
                $remainingPaths = [];

                // Two uploads of the same file share a fileName, and deleting by
                // name would then take both. Only an unambiguous name is offered
                // as a match; a repeated one has to be identified by its path.
                $nameCounts = array_count_values(array_map('strval', $fileNames));

                foreach ($filePaths as $index => $path) {
                    $name = $fileNames[$index] ?? null;
                    $uniqueName = ($name !== null && ($nameCounts[$name] ?? 0) === 1) ? $name : null;

                    if ($this->isFileDeleted($path, $uniqueName, $deletedFileIds)) {
                        $this->fileStorage->deletePublicByUrl($path);
                    } else {
                        $remainingNames[] = $fileNames[$index] ?? basename($path);
                        $remainingPaths[] = $path;
                    }
                }

                $fileNames = $remainingNames;
                $filePaths = $remainingPaths;
            }

            // Add new uploaded files
            if ($request->hasFile('uploadFile')) {
                $assetSlug = str_replace(' ', '_', trim($asset->assetName));
                foreach ($request->file('uploadFile') as $file) {
                    $originalName = str_replace(' ', '_', trim($file->getClientOriginalName()));
                    $storedName = $assetSlug.'_'.time().'_'.$originalName;

                    $fileNames[] = $originalName;
                    $filePaths[] = $this->fileStorage->uploadPublicFile($file, 'marketing-assets', $storedName);
                }
            }

            $asset->update([
                'fileName' => array_values($fileNames),
                'filePath' => array_values($filePaths),
            ]);

            logUserActivity([
                'action' => 'updated',
                'module' => Constants::MODULE_MARKETING_ASSET,
                'moduleId' => $asset->id,
                'description' => "Updated marketing asset: {$asset->assetName}",
            ]);

            return $this->success(
                new GetMarketingAssetResource($asset->fresh()),
                'Marketing asset updated successfully'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('MarketingAssetController : update', $e);
        }
    }

    public function destroy(string $id)
    {
        if (isAgent()) {
            return $this->error('Agents are not allowed to delete marketing assets.', 403);
        }

        try {
            $asset = $this->marketingAssetService->getById($id);

            if (! $asset) {
                return $this->error('The requested marketing asset was not found.', 422);
            }

            $assetName = $asset->assetName;

            // Delete all files from storage
            foreach ($asset->filePath ?? [] as $path) {
                $this->fileStorage->deletePublicByUrl($path);
            }

            $this->marketingAssetService->delete($id);

            logUserActivity([
                'action' => 'deleted',
                'module' => Constants::MODULE_MARKETING_ASSET,
                'moduleId' => $id,
                'description' => "Deleted marketing asset: {$assetName}",
            ]);

            return $this->success([], 'Marketing asset deleted successfully');
        } catch (\Exception $e) {
            return $this->exceptionResponse('MarketingAssetController : destroy', $e);
        }
    }

    public function download(string $id)
    {
        try {
            $asset = $this->marketingAssetService->getById($id);

            if (! $asset) {
                return $this->error('The requested marketing asset was not found.', 422);
            }

            $filePaths = $asset->filePath ?? [];
            $fileNames = $asset->fileName ?? [];

            if (empty($filePaths)) {
                return $this->error('No files found for this marketing asset.', 422);
            }

            // Single file — return direct link
            if (count($filePaths) === 1) {
                logUserActivity([
                    'action' => 'downloaded',
                    'module' => Constants::MODULE_MARKETING_ASSET,
                    'moduleId' => $id,
                    'description' => "Downloaded marketing asset file: {$fileNames[0]}",
                ]);

                return $this->success([
                    'downloadUrl' => getImageUrl($filePaths[0]),
                    'fileName' => $fileNames[0],
                ], 'Download link generated successfully');
            }

            // Multiple files — build the zip in a temp file, then upload it to S3.
            $zipFileName = str_replace(' ', '_', trim($asset->assetName)).'_'.time().'.zip';
            $tmpZip = tempnam(sys_get_temp_dir(), 'mkt_').'.zip';

            $zip = new \ZipArchive;
            if ($zip->open($tmpZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                return $this->error('Failed to create zip file.', 422);
            }

            foreach ($filePaths as $index => $path) {
                $bytes = $this->fileStorage->readBytes($path);
                if ($bytes !== null) {
                    $zip->addFromString($fileNames[$index] ?? basename($path), $bytes);
                }
            }

            $zip->close();

            $downloadUrl = $this->fileStorage->putPublic(
                'marketing-assets/'.$zipFileName,
                file_get_contents($tmpZip)
            );

            @unlink($tmpZip);

            logUserActivity([
                'action' => 'downloaded',
                'module' => Constants::MODULE_MARKETING_ASSET,
                'moduleId' => $id,
                'description' => "Downloaded marketing asset zip: {$asset->assetName}",
            ]);

            return $this->success([
                'downloadUrl' => $downloadUrl,
                'fileName' => $zipFileName,
            ], 'Download link generated successfully');
        } catch (\Exception $e) {
            return $this->exceptionResponse('MarketingAssetController : download', $e);
        }
    }

    /**
     * Fall-back asset-type detection when the frontend doesn't send one: map each
     * uploaded file to a display type and return the distinct set comma-separated
     * (e.g. a PDF + a JPG => "PDF,Image").
     *
     * @param  array<int, \Illuminate\Http\UploadedFile>|null  $files
     */
    /**
     * Is this stored file one the client asked to remove?
     *
     * There is no per-file id to send: the resource exposes only fileName and
     * filePath, so "deletedFileIds" arrives as whichever of the two the edit
     * form had to hand, spelled however that form spells it. It posts the S3
     * URL with the scheme stripped
     * ("el-crm-public-....s3.ap-south-1.amazonaws.com/marketing-assets/x.xls")
     * while the column holds it with "https://" in front, so comparing the two
     * literally matched nothing, deleted nothing, and still returned 200.
     *
     * Both sides are reduced to a comparable form, and the object key and bare
     * file name are offered as well, so a client sending any recognisable form
     * of the file is understood.
     */
    private function isFileDeleted(string $path, ?string $name, array $deletedFileIds): bool
    {
        $candidates = array_map(
            fn ($candidate) => $this->normaliseFileId($candidate),
            array_filter([
                $path,
                getImageUrl($path),
                parse_url($path, PHP_URL_PATH),
                basename($path),
                $name,
            ])
        );

        foreach ($deletedFileIds as $id) {
            $id = $this->normaliseFileId($id);
            if ($id !== '' && in_array($id, $candidates, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Comparable form of a file identifier: no scheme, no leading slash, decoded.
     *
     * Case is kept deliberately — S3 object keys are case-sensitive, so folding
     * it could match a different file.
     */
    private function normaliseFileId(?string $value): string
    {
        $value = preg_replace('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', '', trim((string) $value));

        return rawurldecode(ltrim((string) $value, '/'));
    }

    private function detectAssetTypes(?array $files): string
    {
        // Labels must match the frontend's lib/marketing-asset-type.js so the
        // fallback produces the same value the client would have sent.
        $map = [
            'pdf' => 'PDF',
            'doc' => 'DOC', 'docx' => 'DOC',
            'ppt' => 'PPT', 'pptx' => 'PPT',
            'jpg' => 'Image', 'jpeg' => 'Image', 'png' => 'Image', 'webp' => 'Image', 'gif' => 'Image', 'svg' => 'Image',
            'mp4' => 'Video', 'mov' => 'Video', 'webm' => 'Video', 'avi' => 'Video',
            'csv' => 'Excel', 'xls' => 'Excel', 'xlsx' => 'Excel',
            'xlsm' => 'Excel', 'xltx' => 'Excel', 'ods' => 'Excel',
        ];

        $types = [];
        foreach ((array) $files as $file) {
            $ext = strtolower($file->getClientOriginalExtension());
            $types[] = $map[$ext] ?? 'Other';
        }

        return implode(',', array_values(array_unique($types)));
    }

    /**
     * Category validation that accepts a DB category id OR a legacy constant key
     * (transition-safe: old clients still send constant keys while new ones send
     * ids from /marketing/categories/select). Null is allowed.
     *
     * @return array<int, mixed>
     */
    private function categoryRules(bool $sometimes = false): array
    {
        $rule = function ($attribute, $value, $fail) {
            if ($value === null || $value === '') {
                return;
            }

            $isLegacyKey = array_key_exists($value, Constants::MARKETING_ASSET_CATEGORY);
            $isDbCategory = MarketingAssetCategory::whereKey($value)->exists();

            if (! $isLegacyKey && ! $isDbCategory) {
                $fail('The selected category is invalid.');
            }
        };

        return $sometimes ? ['sometimes', 'nullable', $rule] : ['nullable', $rule];
    }
}
