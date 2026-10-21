<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\PersonalAccessToken;

class PersonalAccessTokenLog extends Model
{
    public $timestamps = false;

    protected $table = 'personal_access_token_logs';

    protected $fillable = [
        'tokenId',
        'userId',
        'method',
        'endpoint',
        'statusCode',
        'ipAddress',
        'userAgent',
        'payload',
        'response',
        'requested_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'response' => 'array',
        'requested_at' => 'datetime',
    ];

    public function token()
    {
        return $this->belongsTo(PersonalAccessToken::class, 'tokenId');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'userId');
    }
}
