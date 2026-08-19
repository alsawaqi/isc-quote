<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationDiscount extends Model
{
    use HasFactory;

    protected $fillable = [
        'quotation_id',
        'line_number',
        'label',
        'discount_type',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'amount' => 'decimal:3',
        ];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }
}
