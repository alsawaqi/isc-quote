<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ItemFulfilment extends Model
{
    use HasFactory;

    protected $fillable = [
        'quotation_id',
        'quotation_item_id',
        'buyer_po_id',
        'buyer_po_item_id',
        'supplier_po_id',
        'supplier_po_line_id',
        'follow_up_item_id',
        'ordered_quantity',
        'ordered_amount',
        'buyer_currency',
        'supplier_quantity',
        'supplier_amount',
        'supplier_currency',
        'received_quantity',
        'delivered_quantity',
        'invoiced_amount',
        'paid_amount',
        'status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'ordered_quantity' => 'decimal:3',
            'ordered_amount' => 'decimal:3',
            'supplier_quantity' => 'decimal:3',
            'supplier_amount' => 'decimal:3',
            'received_quantity' => 'decimal:3',
            'delivered_quantity' => 'decimal:3',
            'invoiced_amount' => 'decimal:3',
            'paid_amount' => 'decimal:3',
            'metadata' => 'array',
        ];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function quotationItem(): BelongsTo
    {
        return $this->belongsTo(QuotationItem::class);
    }

    public function buyerPo(): BelongsTo
    {
        return $this->belongsTo(BuyerPo::class);
    }

    public function buyerPoItem(): BelongsTo
    {
        return $this->belongsTo(BuyerPoItem::class);
    }

    public function supplierPo(): BelongsTo
    {
        return $this->belongsTo(SupplierPo::class);
    }

    public function supplierPoLine(): BelongsTo
    {
        return $this->belongsTo(SupplierPoLine::class);
    }

    public function followUpItem(): BelongsTo
    {
        return $this->belongsTo(FollowUpItem::class);
    }

    public function logisticsCases(): HasMany
    {
        return $this->hasMany(LogisticsCase::class);
    }

    public function packingListItems(): HasMany
    {
        return $this->hasMany(PackingListItem::class);
    }

    public function deliveryOrderItems(): HasMany
    {
        return $this->hasMany(DeliveryOrderItem::class);
    }

    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
