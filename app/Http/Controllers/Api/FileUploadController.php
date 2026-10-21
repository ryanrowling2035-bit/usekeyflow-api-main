<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FileStorageService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Generic upload endpoints demonstrating the S3 integration.
 *
 * - Images go to the public bucket and the response carries the public URL.
 * - Documents go to the private bucket; the response carries only the object
 *   key, and a separate endpoint issues short-lived signed URLs on demand.
 */
class FileUploadController extends Controller
{
    use ApiResponse;

    public function __construct(
        private FileStorageService $fileStorage
    ) {}

    /**
     * Upload a public image (listing/property/profile/marketing).
     */
    public function uploadImage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'image' => 'required|file|mimes:jpg,jpeg,png,webp|mimetypes:image/jpeg,image/png,image/webp|max:5120',
            'folder' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $folder = $this->sanitizeFolder($request->input('folder'), 'images');
            $file = $request->file('image');

            $url = $this->fileStorage->uploadPublicFile($file, $folder);

            return $this->success([
                'url' => $url,
                'name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
            ], 'Image uploaded successfully');
        } catch (\Exception $e) {
            return $this->exceptionResponse('FileUploadController : uploadImage', $e);
        }
    }

    /**
     * Upload a confidential document to the private bucket.
     */
    public function uploadDocument(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'document' => 'required|file|mimes:pdf,doc,docx|mimetypes:application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document|max:20480',
            'folder' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $folder = $this->sanitizeFolder($request->input('folder'), 'documents');
            $file = $request->file('document');

            $key = $this->fileStorage->uploadPrivateFile($file, $folder);

            return $this->success([
                'key' => $key,
                'name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
            ], 'Document uploaded successfully');
        } catch (\Exception $e) {
            return $this->exceptionResponse('FileUploadController : uploadDocument', $e);
        }
    }

    /**
     * Issue a fresh short-lived signed URL for a private document key.
     */
    public function documentSignedUrl(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'key' => 'required|string|max:1024',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $url = $this->fileStorage->temporaryUrl($request->input('key'));

            if (! $url) {
                return $this->error('The requested document was not found.', 422);
            }

            return $this->success(['url' => $url], 'Signed URL generated successfully');
        } catch (\Exception $e) {
            return $this->exceptionResponse('FileUploadController : documentSignedUrl', $e);
        }
    }

    /**
     * Constrain the caller-supplied folder to a safe allowlisted set of segments.
     */
    private function sanitizeFolder(?string $folder, string $default): string
    {
        if (empty($folder)) {
            return $default;
        }

        $segments = array_filter(
            explode('/', $folder),
            fn ($segment) => preg_match('/^[A-Za-z0-9_-]+$/', $segment) === 1
        );

        return $segments === [] ? $default : implode('/', $segments);
    }
}
