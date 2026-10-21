<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PropertyApprovalHistory extends Model
{
    protected $fillable = [
        'listingId',
        'status',
        'comment',
        'auditBy',
        'auditDate',
    ];

    public function property()
    {
        return $this->belongsTo(Property::class, 'listingId', 'id');
    }

    public function auditor()
    {
        return $this->belongsTo(User::class, 'auditBy', 'id');
    }
}
