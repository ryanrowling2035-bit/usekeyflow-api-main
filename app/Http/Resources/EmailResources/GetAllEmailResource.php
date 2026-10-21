<?php

namespace App\Http\Resources\EmailResources;

use App\Constants\Constants;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GetAllEmailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'subject' => $this->subject,
            'macro' => $this->macro,
            'status' => $this->status,
            'created_at' => convertToTimezone($this->created_at),
        ];
    }

    public static function grouped($templates): array
    {
        $keyed = $templates->keyBy('slug');

        $grouped = [];
        foreach (Constants::EMAIL_SLUGS as $group => $slugs) {
            $grouped[$group] = static::collection(
                collect(array_values($slugs))
                    ->map(fn ($slug) => $keyed->get($slug))
                    ->filter()
                    ->values()
            );
        }

        return $grouped;
    }
}
