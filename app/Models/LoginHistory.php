<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoginHistory extends Model
{
    protected $fillable = [
        'userId',
        'ipAddress',
        'userAgent',
        'os',
        'device',
        'location',
        'loginType',
        'loginAt',
    ];

    protected function casts(): array
    {
        return [
            'loginAt' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'userId');
    }
}
