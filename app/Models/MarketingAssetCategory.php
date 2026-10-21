<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MarketingAssetCategory extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'status',
        'createdBy',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'createdBy');
    }

    /**
     * Assets assigned to this category. The asset's `category` column stores this
     * category's id (once the seed/link migration has re-pointed legacy rows).
     */
    public function assets()
    {
        return $this->hasMany(MarketingAsset::class, 'category', 'id');
    }
}
