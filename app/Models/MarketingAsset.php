<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MarketingAsset extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'assetName',
        'category',
        'assetType',
        'description',
        'uploadedBy',
        'fileName',
        'filePath',
        'status',
    ];

    protected $casts = [
        'fileName' => 'array',
        'filePath' => 'array',
    ];

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploadedBy');
    }
}
