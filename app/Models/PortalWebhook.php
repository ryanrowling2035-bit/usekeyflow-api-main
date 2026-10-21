<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PortalWebhook extends Model
{
    protected $fillable = [
        'portal',
        'eventId',
        'type',
        'listingId',
        'propertyId',
        'actionType',
        'status',
        'reason',
        'eventTimestamp',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function property()
    {
        return $this->belongsTo(Property::class, 'propertyId');
    }
}
