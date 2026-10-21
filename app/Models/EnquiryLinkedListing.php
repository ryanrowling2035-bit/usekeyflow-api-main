<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnquiryLinkedListing extends Model
{
    protected $table = 'enquiry_linked_listings';

    protected $fillable = [
        'enquiryId',
        'propertyId',
        'createdBy',
    ];

    public function enquiry()
    {
        return $this->belongsTo(Enquiry::class, 'enquiryId');
    }

    public function property()
    {
        return $this->belongsTo(Property::class, 'propertyId');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'createdBy');
    }
}
