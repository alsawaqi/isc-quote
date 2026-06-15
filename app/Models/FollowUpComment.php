<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FollowUpComment extends Model
{
    use HasFactory;

    protected $fillable = [
        'follow_up_item_id',
        'user_id',
        'stage',
        'comment',
        'communication_type',
        'contacted_person',
        'next_action',
    ];

    public function followUpItem(): BelongsTo
    {
        return $this->belongsTo(FollowUpItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(FollowUpAttachment::class);
    }
}
