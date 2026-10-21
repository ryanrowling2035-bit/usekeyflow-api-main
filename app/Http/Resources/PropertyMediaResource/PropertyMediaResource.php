<?php

namespace App\Http\Resources\PropertyMediaResource;

use Illuminate\Http\Resources\Json\JsonResource;

class PropertyMediaResource extends JsonResource
{
    public function toArray($request)
    {
        $filePath = in_array($this->mediaType, ['uploadPhotos', 'uploadVideos'])
            ? ($this->compressedPath ?? $this->filePath)
            : $this->filePath;

        return [
            'id' => $this->id,
            'propertyId' => $this->propertyId,
            'fileName' => $this->fileName,
            'compressedPath' => getImageUrl($filePath),
            'originalPath' => getImageUrl($this->originalPath ?? $this->filePath),
            'thumbnailPath' => getImageUrl($this->thumbnailPath),
            'mediaType' => $this->mediaType,
            'category' => $this->category,
            'ordering' => $this->ordering,
            'isWebsite' => $this->isWebsite,
            'isPortals' => $this->isPortals,
            'isFlyer' => $this->isFlyer,
            'tags' => $this->tags,
            'createdBy' => $this->createdBy,
            'created_at' => convertToTimezone($this->created_at),
            'updated_at' => convertToTimezone($this->updated_at),
        ];
    }
}
