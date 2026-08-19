<?php

namespace App\Models;

use App\Casts\SanitizedRichText;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SupplierPoLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_po_id',
        'quotation_id',
        'buyer_po_id',
        'buyer_po_item_id',
        'quotation_item_id',
        'product_id',
        'manufacturer_id',
        'company_location_id',
        'line_number',
        'product_code',
        'product_name',
        'title',
        'item_description',
        'quantity',
        'uom',
        'delivery_date',
        'incoterm_id',
        'unit_cost',
        'total_cost',
    ];

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'company_location_id' => 'integer',
            'quantity' => 'decimal:3',
            'delivery_date' => 'date',
            'unit_cost' => 'decimal:3',
            'total_cost' => 'decimal:3',
            'item_description' => SanitizedRichText::class,
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

    public function buyerPoItem(): BelongsTo
    {
        return $this->belongsTo(BuyerPoItem::class);
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

    public function companyLocation(): BelongsTo
    {
        return $this->belongsTo(CompanyLocation::class);
    }

    public function incoterm(): BelongsTo
    {
        return $this->belongsTo(Incoterm::class);
    }

    public function origins(): HasMany
    {
        return $this->hasMany(SupplierPoLineOrigin::class)->orderBy('line_number');
    }

    public function followUpItem(): HasOne
    {
        return $this->hasOne(FollowUpItem::class);
    }

    public function itemFulfilment(): HasOne
    {
        return $this->hasOne(ItemFulfilment::class);
    }
}
