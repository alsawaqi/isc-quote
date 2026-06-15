<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationActivityLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'quotation_id',
        'quotation_version_id',
        'user_id',
        'action',
        'summary',
        'properties',
    ];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
        ];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(QuotationVersion::class, 'quotation_version_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
