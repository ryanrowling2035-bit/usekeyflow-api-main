<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DocumentCenter extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'referenceNo',
        'module',
        'moduleId',
        'title',
        'filePath',
        'type',
        'size',
        'docType',
        'tags',
        'isPublic',
        'createdBy',
    ];

    protected $casts = [
        'tags' => 'array',
        'isPublic' => 'boolean',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'createdBy');
    }
}
