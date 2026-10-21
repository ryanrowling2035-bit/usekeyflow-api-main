<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesforceIdMap extends Model
{
    protected $table = 'salesforce_id_map';

    protected $fillable = [
        'objectType',
        'salesforceId',
        'module',
        'moduleId',
    ];
}
