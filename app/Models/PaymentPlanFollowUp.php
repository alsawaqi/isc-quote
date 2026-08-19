<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PaymentPlanFollowUp extends Model
{
    use HasFactory;

    protected $fillable = [
        'follow_up_item_id',
        'quotation_payment_schedule_id',
        'invoice_id',
        'status',
        'due_date',
        'expected_percentage',
        'expected_amount',
        'currency',
        'paid_at',
        'paid_amount',
        'payment_reference',
        'remarks',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'expected_percentage' => 'decimal:3',
            'expected_amount' => 'decimal:3',
            'paid_at' => 'datetime',
            'paid_amount' => 'decimal:3',
        ];
    }

    public function followUpItem(): BelongsTo
    {
        return $this->belongsTo(FollowUpItem::class);
    }

    public function quotationPaymentSchedule(): BelongsTo
    {
        return $this->belongsTo(QuotationPaymentSchedule::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(PaymentPlanFollowUpAttachment::class)->latest();
    }

    public function invoicePayment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }
}
