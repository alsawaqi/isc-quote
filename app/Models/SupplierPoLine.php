<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SupplierPoLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_po_id',
        'quotation_id',
        'buyer_po_id',
        'quotation_item_id',
        'product_id',
        'manufacturer_id',
        'line_number',
        'product_name',
        'title',
        'item_description',
        'quantity',
        'uom',
        'unit_cost',
        'total_cost',
    ];

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'quantity' => 'decimal:3',
            'unit_cost' => 'decimal:3',
            'total_cost' => 'decimal:3',
        ];
    }

    public function supplierPo(): BelongsTo
    {
        return $this->belongsTo(SupplierPo::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function buyerPo(): BelongsTo
    {
        return $this->belongsTo(BuyerPo::class);
    }

    public function quotationItem(): BelongsTo
    {
        return $this->belongsTo(QuotationItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(Manufacturer::class);
    }

    public function followUpItem(): HasOne
    {
        return $this->hasOne(FollowUpItem::class);
    }
}
