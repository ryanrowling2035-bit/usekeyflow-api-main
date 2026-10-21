<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyEnquiryEmail extends Model
{
    protected $table = 'daily_enquiry_emails';

    protected $fillable = [
        'agent_id',
        'enquiry_id',
        'property_id',
    ];
}
