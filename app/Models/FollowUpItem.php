<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FollowUpItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_po_line_id',
        'supplier_po_id',
        'quotation_id',
        'buyer_po_id',
        'quotation_item_id',
        'assigned_to',
        'status',
        'reminder_interval_value',
        'reminder_interval_unit',
        'next_follow_up_at',
        'last_comment_at',
        'acknowledgement_received_at',
        'acknowledgement_file_path',
        'acknowledgement_original_file_name',
        'acknowledgement_notes',
        'acknowledged_by',
        'closed_at',
        'closed_notes',
    ];

    protected function casts(): array
    {
        return [
            'reminder_interval_value' => 'integer',
            'next_follow_up_at' => 'datetime',
            'last_comment_at' => 'datetime',
            'acknowledgement_received_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function supplierPoLine(): BelongsTo
    {
        return $this->belongsTo(SupplierPoLine::class);
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

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(FollowUpComment::class)->latest();
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(FollowUpAuditLog::class)->oldest('occurred_at')->oldest('id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(FollowUpAttachment::class);
    }

    public function shippingDocuments(): HasMany
    {
        return $this->hasMany(ShippingDocument::class);
    }

    public function packingList(): HasOne
    {
        return $this->hasOne(PackingList::class);
    }

    public function logisticsCase(): HasOne
    {
        return $this->hasOne(LogisticsCase::class);
    }

    public function deliveryOrder(): HasOne
    {
        return $this->hasOne(DeliveryOrder::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
