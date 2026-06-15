<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackingListItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'packing_list_id',
        'quotation_item_id',
        'buyer_po_id',
        'line_number',
        'item_description',
        'quantity',
        'uom',
        'package_size',
        'gross_weight',
        'net_weight',
    ];

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'quantity' => 'decimal:3',
        ];
    }

    public function packingList(): BelongsTo
    {
        return $this->belongsTo(PackingList::class);
    }

    public function quotationItem(): BelongsTo
    {
        return $this->belongsTo(QuotationItem::class);
    }

    public function buyerPo(): BelongsTo
    {
        return $this->belongsTo(BuyerPo::class);
    }
}
