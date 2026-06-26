<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuotationItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'quotation_id',
        'product_id',
        'manufacturer_id',
        'line_number',
        'product_name',
        'title',
        'buyer_description',
        'manufacturer_description',
        'quantity',
        'uom',
        'unit_price',
        'vat_rate',
        'total_price',
    ];

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:3',
            'vat_rate' => 'decimal:3',
            'total_price' => 'decimal:3',
        ];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(Manufacturer::class);
    }

    public function supplierPoLines(): HasMany
    {
        return $this->hasMany(SupplierPoLine::class);
    }
}
