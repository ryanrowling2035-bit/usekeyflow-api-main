<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PropertyLocation extends Model
{
    protected $table = 'pf_location_cities';

    protected $fillable = ['locationId', 'name', 'type', 'latitude', 'longitude'];

    public function communities()
    {
        return $this->hasMany(PropertyCommunity::class, 'cityId', 'locationId');
    }
}
