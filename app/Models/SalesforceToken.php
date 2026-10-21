<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesforceToken extends Model
{
    protected $fillable = [
        'access_token',
        'instance_url',
    ];
}
