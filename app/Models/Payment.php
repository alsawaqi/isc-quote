<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'follow_up_item_id',
        'amount',
        'currency',
        'payment_date',
        'payment_reference',
        'remarks',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:3',
            'payment_date' => 'date',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function followUpItem(): BelongsTo
    {
        return $this->belongsTo(FollowUpItem::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
