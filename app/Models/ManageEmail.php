<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManageEmail extends Model
{
    protected $table = 'manage_emails';

    protected $attributes = [
        'status' => 'active',
    ];

    protected $fillable = [
        'title',
        'slug',
        'subject',
        'emailContent',
        'macro',
        'status',
        'createdBy',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'createdBy');
    }
}
