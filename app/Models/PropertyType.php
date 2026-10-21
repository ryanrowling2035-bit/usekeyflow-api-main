<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PropertyType extends Model
{
    use HasFactory;

    protected $table = 'property_types';

    protected $fillable = [
        'name',
        'categoryType',
        'typeSlug',
        'publisher',
        'tags',
        'createdBy',
    ];

    protected $casts = [
        'tags' => 'array',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'createdBy');
    }
}
