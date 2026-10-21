<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaveEnquiryFilter extends Model
{
    protected $table = 'save_enquiry_filters';

    protected $fillable = [
        'name',
        'filters',
        'module',
        'fields',
        'createdBy',
    ];

    protected $casts = [
        'filters' => 'array',
        'fields' => 'array',
    ];
}
