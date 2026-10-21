<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Favorite extends Model
{
    protected $fillable = [
        'userId',
        'moduleId',
        'module',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'moduleId');
    }

    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(Enquiry::class, 'moduleId');
    }

    /**
     * Relations required by GetAllPropertyResource so FavoriteResource can render a full property payload.
     */
    public static function propertyEagerRelations(): array
    {
        return [
            'primaryMediaThumbnail',
            'creator',
            'city',
            'community',
            'subCommunity',
            'buildingTower',
            'meta',
            'internalNotes',
            'agent',
            'physicalOwner',
        ];
    }
}
