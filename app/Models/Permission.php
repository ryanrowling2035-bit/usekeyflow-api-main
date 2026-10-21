<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $fillable = [
        'name',
        'module',
        'action',
    ];

    public function roles()
    {
        return $this->belongsToMany(
            Role::class,
            'role_permissions',
            'permissionId',
            'roleId'
        );
    }
}
