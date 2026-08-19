<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierPoRevision extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_po_id',
        'revision_number',
        'po_reference',
        'snapshot',
        'docx_path',
        'pdf_path',
        'created_by',
        'finalized_at',
    ];

    protected function casts(): array
    {
        return [
            'revision_number' => 'integer',
            'snapshot' => 'array',
            'finalized_at' => 'datetime',
        ];
    }

    public function supplierPo(): BelongsTo
    {
        return $this->belongsTo(SupplierPo::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
