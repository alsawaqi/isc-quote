<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShippingDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'follow_up_item_id',
        'document_type',
        'label',
        'status',
        'document_number',
        'document_date',
        'file_path',
        'original_file_name',
        'uploaded_by',
        'uploaded_at',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'document_date' => 'date',
            'uploaded_at' => 'datetime',
        ];
    }

    public function followUpItem(): BelongsTo
    {
        return $this->belongsTo(FollowUpItem::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
