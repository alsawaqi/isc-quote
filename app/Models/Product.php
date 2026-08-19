<?php

namespace App\Models;

use App\Casts\SanitizedRichText;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'manufacturer_id',
        'product_code',
        'name',
        'title',
        'buyer_description',
        'manufacturer_description',
        'last_uom',
        'last_unit_price',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'last_unit_price' => 'decimal:3',
            'buyer_description' => SanitizedRichText::class,
            'manufacturer_description' => SanitizedRichText::class,
        ];
    }

    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(Manufacturer::class);
    }

    public function quotationItems(): HasMany
    {
        return $this->hasMany(QuotationItem::class);
    }
}
