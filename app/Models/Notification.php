<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $fillable = [
        'userId',
        'type',
        'title',
        'message',
        'module',
        'moduleId',
        'actorId',
        'data',
        'isRead',
        'readAt',
    ];

    protected $casts = [
        'data' => 'array',
        'isRead' => 'boolean',
        'readAt' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'userId');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actorId');
    }

    public function property()
    {
        return $this->belongsTo(Property::class, 'moduleId');
    }

    public function enquiry()
    {
        return $this->belongsTo(Enquiry::class, 'moduleId');
    }

    public function scopeUnread($query)
    {
        return $query->where('isRead', false);
    }

    public function scopeForModule($query, string $module, int $moduleId)
    {
        return $query->where('module', $module)->where('moduleId', $moduleId);
    }

    public function markAsRead()
    {
        $this->update([
            'isRead' => true,
            'readAt' => now(),
        ]);
    }
}
