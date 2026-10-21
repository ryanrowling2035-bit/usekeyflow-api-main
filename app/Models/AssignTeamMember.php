<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssignTeamMember extends Model
{
    protected $table = 'assign_team_members';

    protected $fillable = [
        'teamId',
        'userId',
        'createdBy',
    ];

    public function team()
    {
        return $this->belongsTo(Team::class, 'teamId');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'userId');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'createdBy');
    }
}
