<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BuyerPo extends Model
{
    use HasFactory;

    protected $fillable = [
        'quotation_id',
        'quotation_version_id',
        'buyer_company_id',
        'buyer_contact_id',
        'po_number',
        'po_date',
        'po_value',
        'currency',
        'po_file_path',
        'original_file_name',
        'created_by',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'po_date' => 'date',
            'po_value' => 'decimal:3',
        ];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function quotationVersion(): BelongsTo
    {
        return $this->belongsTo(QuotationVersion::class);
    }

    public function buyerCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'buyer_company_id');
    }

    public function buyerContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'buyer_contact_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function supplierPoLines(): HasMany
    {
        return $this->hasMany(SupplierPoLine::class);
    }
}
