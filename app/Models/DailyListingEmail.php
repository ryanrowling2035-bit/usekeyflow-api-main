<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyListingEmail extends Model
{
    protected $table = 'daily_listing_emails';

    protected $fillable = [
        'agent_id',
        'property_id',
        'enquiry_id',
    ];
}
