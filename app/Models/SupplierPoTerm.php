<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierPoTerm extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_po_id',
        'line_number',
        'key',
        'title',
        'description',
        'is_required_default',
    ];

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'is_required_default' => 'boolean',
        ];
    }

    public function supplierPo(): BelongsTo
    {
        return $this->belongsTo(SupplierPo::class);
    }
}
