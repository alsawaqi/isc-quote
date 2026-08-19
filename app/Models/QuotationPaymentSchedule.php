<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuotationPaymentSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'quotation_id',
        'line_number',
        'label',
        'payment_method',
        'payment_percentage',
        'due_timing',
        'due_event',
        'due_offset_days',
        'due_date',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'payment_percentage' => 'decimal:3',
            'due_offset_days' => 'integer',
            'due_date' => 'date',
        ];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function paymentPlanFollowUps(): HasMany
    {
        return $this->hasMany(PaymentPlanFollowUp::class);
    }
}
