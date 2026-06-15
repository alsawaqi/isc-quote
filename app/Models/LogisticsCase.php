<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LogisticsCase extends Model
{
    use HasFactory;

    protected $fillable = [
        'follow_up_item_id',
        'delivery_responsibility',
        'status',
        'eta_at',
        'agent_name',
        'agent_contact',
        'documents_sent_at',
        'arrived_at',
        'warehouse_received_at',
        'buyer_received_at',
        'received_quantity',
        'goods_condition',
        'received_location',
        'remarks',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'eta_at' => 'datetime',
            'documents_sent_at' => 'datetime',
            'arrived_at' => 'datetime',
            'warehouse_received_at' => 'datetime',
            'buyer_received_at' => 'datetime',
            'received_quantity' => 'decimal:3',
        ];
    }

    public function followUpItem(): BelongsTo
    {
        return $this->belongsTo(FollowUpItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(LogisticsEvent::class)->latest('event_at')->latest('id');
    }
}
