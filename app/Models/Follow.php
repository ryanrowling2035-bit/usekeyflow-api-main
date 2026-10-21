<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Follow extends Model
{
    protected $fillable = [
        'userId',
        'moduleId',
        'module',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'userId');
    }

    public function property()
    {
        return $this->belongsTo(Property::class, 'moduleId');
    }

    public function enquiry()
    {
        return $this->belongsTo(Enquiry::class, 'moduleId');
    }
}
