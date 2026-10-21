<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserActivity extends Model
{
    protected $fillable = [
        'userId',
        'action',
        'module',
        'moduleId',
        'description',
        'oldValues',
        'newValues',
        'ipAddress',
    ];

    protected $casts = [
        'oldValues' => 'array',
        'newValues' => 'array',
        'description' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'userId');
    }
}
