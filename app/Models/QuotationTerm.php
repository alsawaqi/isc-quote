<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationTerm extends Model
{
    use HasFactory;

    protected $fillable = [
        'quotation_id',
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

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }
}
