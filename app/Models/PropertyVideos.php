<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PropertyVideos extends Model
{
    protected $table = 'property_videos';

    protected $fillable = [
        'propertyId',
        'videoUrl',
        'platform',
        'title',
        'isDefault',
    ];

    public function property()
    {
        return $this->belongsTo(Property::class, 'propertyId');
    }
}
