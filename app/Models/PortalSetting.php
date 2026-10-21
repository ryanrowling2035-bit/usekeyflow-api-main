<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PortalSetting extends Model
{
    use HasFactory;

    const CREATED_AT = 'createdAt';

    const UPDATED_AT = 'updatedAt';

    protected $fillable = [
        'portal',
        'branchName',
        'slug',
        'bayutSecretToken',
        'bayutSecretTokenPublic',
        'pfApiKey',
        'pfApiKeyPublic',
        'pfApiSecret',
        'pfApiSecretPublic',
        'status',
    ];

    protected $hidden = [
        'bayutSecretToken',
        'pfApiKey',
        'pfApiSecret',
    ];

    protected function casts(): array
    {
        return [
            'bayutSecretToken' => 'encrypted',
            'pfApiKey' => 'encrypted',
            'pfApiSecret' => 'encrypted',
        ];
    }

    /**
     * Stored portal webhook subscriptions for this account, matched on slug.
     */
    public function webhookSubscriptions()
    {
        return $this->hasMany(PortalWebhookSubscription::class, 'slug', 'slug');
    }
}
