<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Boost extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'isPropertyFinder',
        'isBayut',
        'propertyId',
        'duration',
        'boostType',
        'credits',
        'status',
        'notes',
        'rejectionReason',
        'startDate',
        'endDate',
        'createdBy',
        'approvedBy',
    ];

    public function property()
    {
        return $this->belongsTo(Property::class, 'propertyId');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'createdBy');
    }

    // "Boosted by" — who approved the boost / pushed it live.
    public function approver()
    {
        return $this->belongsTo(User::class, 'approvedBy');
    }
}
