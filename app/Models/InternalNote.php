<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class InternalNote extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'module',
        'moduleId',
        'content',
        'type',
        'parent',
        'createdBy',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'createdBy');
    }

    public function parent()
    {
        return $this->belongsTo(InternalNote::class, 'parent');
    }

    public function child()
    {
        return $this->hasMany(InternalNote::class, 'parent');
    }
}
