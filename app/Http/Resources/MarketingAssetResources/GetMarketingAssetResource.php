<?php

namespace App\Http\Resources\MarketingAssetResources;

use App\Constants\Constants;
use App\Services\FileStorageService;
use Illuminate\Http\Resources\Json\JsonResource;

class GetMarketingAssetResource extends JsonResource
{
    public function toArray($request)
    {
        $fileStorage = app(FileStorageService::class);

        return [
            'id' => $this->id,
            'assetName' => $this->assetName,
            'category' => $this->category,
            'categoryLabel' => $this->category ? (Constants::MARKETING_ASSET_CATEGORY[$this->category] ?? $this->category) : null,
            'assetType' => $this->assetType,
            'description' => $this->description,
            'uploadedBy' => $this->uploader ? [
                'id' => $this->uploader->id,
                'name' => $this->uploader->name,
            ] : null,
            'documents' => $this->filePath ? collect($this->filePath)->map(function ($path, $index) use ($fileStorage) {
                $fileNames = $this->fileName ?? [];
                $size = $fileStorage->sizePublicByUrl($path);

                return [
                    'fileName' => $fileNames[$index] ?? basename($path),
                    'filePath' => getImageUrl($path),
                    'size' => $size,
                    'sizeLabel' => formatBytes($size),
                ];
            })->values() : [],
            'status' => $this->status,
            'statusLabel' => $this->status ? (Constants::MARKETING_ASSET_STATUS[$this->status] ?? $this->status) : null,
            'created_at' => convertToTimezone($this->created_at),
            'updated_at' => convertToTimezone($this->updated_at),
        ];
    }
}
