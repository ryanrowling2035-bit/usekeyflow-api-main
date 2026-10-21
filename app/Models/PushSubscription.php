<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PushSubscription extends Model
{
    protected $fillable = [
        'userId',
        'endpoint',
        'publicKey',
        'authToken',
        'contentEncoding',
        'userAgent',
        'lastUsedAt',
    ];

    protected $casts = [
        'lastUsedAt' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'userId');
    }
}
