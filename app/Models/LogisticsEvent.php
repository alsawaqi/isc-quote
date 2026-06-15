<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogisticsEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'logistics_case_id',
        'user_id',
        'event_type',
        'title',
        'event_at',
        'notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'event_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function logisticsCase(): BelongsTo
    {
        return $this->belongsTo(LogisticsCase::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
