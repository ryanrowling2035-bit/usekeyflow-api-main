<?php

namespace App\Http\Resources\FavoriteResources;

use App\Constants\Constants;
use App\Http\Resources\PropertyResource\GetAllPropertyResource;
use Illuminate\Http\Resources\Json\JsonResource;

class FavoriteResource extends JsonResource
{
    public function toArray($request)
    {
        $moduleData = null;

        if (strcasecmp((string) $this->module, Constants::MODULE_PROPERTY) === 0) {
            $moduleData = $this->whenLoaded('property', function () use ($request) {
                return $this->property ? (new GetAllPropertyResource($this->property))->toArray($request) : null;
            });
        } elseif (strcasecmp((string) $this->module, Constants::MODULE_ENQUIRY) === 0) {
            $moduleData = $this->whenLoaded('enquiry', function () {
                return $this->enquiry?->toArray();
            });
        }

        return [
            'id' => $this->id,
            'module' => $this->module,
            'moduleId' => $this->moduleId,
            'moduleData' => $moduleData,
        ];
    }
}
