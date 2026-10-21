<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PropertyCommunity extends Model
{
    use HasFactory;

    protected $fillable = [
        'cityId',
        'communityId',
        'name',
        'communityCode',
        'type',
        'latitude',
        'longitude',
    ];

    public function location()
    {
        return $this->belongsTo(PropertyLocation::class, 'cityId');
    }

    public function subCommunities()
    {
        return $this->hasMany(PropertySubCommunity::class, 'communityId', 'communityId');
    }
}
