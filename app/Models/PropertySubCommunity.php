<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PropertySubCommunity extends Model
{
    use HasFactory;

    protected $fillable = [
        'communityId',
        'propertySubCommunityId',
        'name',
        'type',
        'latitude',
        'longitude',
    ];

    public function community()
    {
        return $this->belongsTo(PropertyCommunity::class, 'communityId');
    }

    public function towers()
    {
        return $this->hasMany(PropertyTower::class, 'subCommunityId', 'propertySubCommunityId');
    }
}
