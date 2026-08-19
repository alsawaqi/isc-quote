<?php

namespace App\Models;

use App\Casts\SanitizedRichText;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BuyerPoItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'buyer_po_id',
        'quotation_id',
        'quotation_item_id',
        'line_number',
        'buyer_item_code',
        'item_description',
        'quantity',
        'uom',
        'unit_price',
        'total_amount',
        'currency',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:3',
            'total_amount' => 'decimal:3',
            'item_description' => SanitizedRichText::class,
        ];
    }

    public function buyerPo(): BelongsTo
    {
        return $this->belongsTo(BuyerPo::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function quotationItem(): BelongsTo
    {
        return $this->belongsTo(QuotationItem::class);
    }

    public function supplierPoLines(): HasMany
    {
        return $this->hasMany(SupplierPoLine::class);
    }

    public function itemFulfilments(): HasMany
    {
        return $this->hasMany(ItemFulfilment::class);
    }
}
