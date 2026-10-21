<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserMeta extends Model
{
    protected $table = 'user_meta';

    protected $fillable = [
        'userId',
        'metaKey',
        'metaValue',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'userId');
    }
}
