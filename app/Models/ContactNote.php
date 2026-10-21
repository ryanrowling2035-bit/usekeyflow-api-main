<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContactNote extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'contactId',
        'taskId',
        'content',
        'type',
        'callStatus',
        'parent',
        'createdBy',
    ];

    /**
     * Parse content as JSON object for email type, return as-is for others.
     */
    public function getContentAttribute($value)
    {
        if ($this->type === 'email') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $value;
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class, 'contactId');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'createdBy');
    }

    public function parentNote()
    {
        return $this->belongsTo(ContactNote::class, 'parent');
    }

    public function replies()
    {
        return $this->hasMany(ContactNote::class, 'parent');
    }
}
