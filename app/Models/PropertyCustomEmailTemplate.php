<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PropertyCustomEmailTemplate extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'propertyId',
        'ownerEmail',
        'subject',
        'emailContent',
        'fileName',
        'filePath',
    ];

    public function property()
    {
        return $this->belongsTo(Property::class, 'propertyId');
    }
}
