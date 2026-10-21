<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PortalWebhookSubscription extends Model
{
    protected $fillable = [
        'portal',
        'slug',
        'eventId',
        'webhookId',
        'callbackUrl',
        'status',
        'statusCode',
        'error',
        'response',
    ];

    protected $casts = [
        'response' => 'array',
    ];

    /**
     * The portal account this subscription belongs to, matched on slug.
     */
    public function portalSetting()
    {
        return $this->belongsTo(PortalSetting::class, 'slug', 'slug');
    }
}
