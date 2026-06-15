<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'follow_up_item_id',
        'delivery_order_reference',
        'delivery_order_date',
        'delivery_place',
        'terms',
        'status',
        'docx_path',
        'pdf_path',
        'signed_file_path',
        'signed_original_file_name',
        'signed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'delivery_order_date' => 'date',
            'signed_at' => 'datetime',
        ];
    }

    public function followUpItem(): BelongsTo
    {
        return $this->belongsTo(FollowUpItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DeliveryOrderItem::class)->orderBy('line_number');
    }
}
