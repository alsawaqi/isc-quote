<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuotationVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'quotation_id',
        'version_number',
        'quotation_reference',
        'snapshot',
        'docx_path',
        'pdf_path',
        'created_by',
        'finalized_at',
    ];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'snapshot' => 'array',
            'finalized_at' => 'datetime',
        ];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function buyerPos(): HasMany
    {
        return $this->hasMany(BuyerPo::class);
    }
}
