<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierPoLineOrigin extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_po_line_id',
        'country_id',
        'country_name',
        'amount',
        'location',
        'line_number',
    ];

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'amount' => 'decimal:3',
        ];
    }

    public function supplierPoLine(): BelongsTo
    {
        return $this->belongsTo(SupplierPoLine::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }
}
