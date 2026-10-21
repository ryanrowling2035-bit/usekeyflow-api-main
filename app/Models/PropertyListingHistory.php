<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PropertyListingHistory extends Model
{
    protected $fillable = [
        'field',
        'userId',
        'propertyId',
        'originalValue',
        'newValue',
        'comments',
    ];

    public function properties()
    {
        return $this->belongsTo(Property::class, 'propertyId');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'userId');
    }
}
