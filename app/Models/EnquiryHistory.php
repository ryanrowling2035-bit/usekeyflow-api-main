<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnquiryHistory extends Model
{
    protected $fillable = [
        'enquiryId',
        'userId',
        'field',
        'originalValue',
        'newValue',
        'comments',
    ];

    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(Enquiry::class, 'enquiryId');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'userId');
    }
}
