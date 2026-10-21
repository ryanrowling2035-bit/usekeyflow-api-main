<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PropertyMediaFile extends Model
{
    protected $fillable = [
        'propertyId',
        'fileName',
        'filePath',
        'originalPath',
        'compressedPath',
        'thumbnailPath',
        'mediaType',
        'ordering',
        'createdBy',
        'category',
        'isWebsite',
        'isPortals',
        'isFlyer',
        'tags',
    ];

    protected $casts = [
        'tags' => 'array',
    ];

    public function properties()
    {
        return $this->belongsTo(Property::class, 'propertyId');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'createdBy');
    }
}
