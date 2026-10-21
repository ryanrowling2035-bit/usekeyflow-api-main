<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PropertyMeta extends Model
{
    protected $table = 'property_meta';

    protected $fillable = [
        'propertyId',
        'metaKey',
        'metaValue',
    ];

    public function property()
    {
        return $this->belongsTo(Property::class, 'propertyId');
    }
}
