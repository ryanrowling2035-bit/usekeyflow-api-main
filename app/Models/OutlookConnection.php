<?php

namespace App\Models;

use App\Constants\Constants;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OutlookConnection extends Model
{
    use HasFactory;

    protected $fillable = [
        'userId',
        'accessToken',
        'refreshToken',
        'tokenExpiresAt',
        'outlookEmail',
        'status',
        'lastSyncAt',
        'lastEmailSyncAt',
        'lastCalendarSyncAt',
        'lastTaskSyncAt',
        'syncSettings',
    ];

    protected $hidden = [
        'accessToken',
        'refreshToken',
    ];

    protected function casts(): array
    {
        return [
            'tokenExpiresAt' => 'datetime',
            'accessToken' => 'encrypted',
            'refreshToken' => 'encrypted',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'userId');
    }

    /**
     * Check if the connection is active.
     */
    public function isConnected(): bool
    {
        return $this->status === Constants::OUTLOOK_SYNC_STATUS['connected'];
    }
}
