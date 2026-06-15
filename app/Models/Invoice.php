<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'follow_up_item_id',
        'delivery_order_id',
        'invoice_reference',
        'invoice_date',
        'sent_at',
        'payment_term_days',
        'due_date',
        'currency',
        'subtotal',
        'vat_rate',
        'vat_amount',
        'total_amount',
        'vat_exception_reason',
        'bank_details',
        'remarks',
        'status',
        'closed_at',
        'docx_path',
        'pdf_path',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'sent_at' => 'datetime',
            'payment_term_days' => 'integer',
            'due_date' => 'date',
            'subtotal' => 'decimal:3',
            'vat_rate' => 'decimal:3',
            'vat_amount' => 'decimal:3',
            'total_amount' => 'decimal:3',
            'closed_at' => 'datetime',
        ];
    }

    public function followUpItem(): BelongsTo
    {
        return $this->belongsTo(FollowUpItem::class);
    }

    public function deliveryOrder(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrder::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('line_number');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->latest('payment_date')->latest('id');
    }
}
