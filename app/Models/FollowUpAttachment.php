<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FollowUpAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'follow_up_item_id',
        'follow_up_comment_id',
        'uploaded_by',
        'document_type',
        'file_path',
        'original_file_name',
    ];

    public function followUpItem(): BelongsTo
    {
        return $this->belongsTo(FollowUpItem::class);
    }

    public function followUpComment(): BelongsTo
    {
        return $this->belongsTo(FollowUpComment::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
