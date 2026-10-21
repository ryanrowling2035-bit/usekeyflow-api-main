<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PropertyTower extends Model
{
    use HasFactory;

    protected $fillable = [
        'communityId',
        'subCommunityId',
        'propertyTowerId',
        'name',
        'type',
        'latitude',
        'longitude',
    ];

    public function subCommunity()
    {
        return $this->belongsTo(PropertySubCommunity::class, 'subCommunityId', 'propertySubCommunityId');
    }
}
