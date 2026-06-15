<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierPo extends Model
{
    use HasFactory;

    protected $fillable = [
        'po_reference',
        'supplier_id',
        'supplier_company_id',
        'supplier_contact_id',
        'buyer_company_id',
        'buyer_contact_id',
        'incoterm_id',
        'supplier_quote_reference',
        'payment_term_days',
        'delivery_period_min',
        'delivery_period_max',
        'delivery_period_unit',
        'delivery_period_type',
        'accepted_invoice_currency',
        'additional_charges_label',
        'additional_charges',
        'subtotal',
        'total_amount',
        'docx_path',
        'pdf_path',
        'created_by',
        'finalized_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'payment_term_days' => 'integer',
            'delivery_period_min' => 'integer',
            'delivery_period_max' => 'integer',
            'additional_charges' => 'decimal:3',
            'subtotal' => 'decimal:3',
            'total_amount' => 'decimal:3',
            'finalized_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function supplierCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'supplier_company_id');
    }

    public function supplierContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'supplier_contact_id');
    }

    public function buyerCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'buyer_company_id');
    }

    public function buyerContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'buyer_contact_id');
    }

    public function incoterm(): BelongsTo
    {
        return $this->belongsTo(Incoterm::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierPoLine::class)->orderBy('line_number');
    }

    public function terms(): HasMany
    {
        return $this->hasMany(SupplierPoTerm::class)->orderBy('line_number');
    }

    public function followUpItems(): HasMany
    {
        return $this->hasMany(FollowUpItem::class);
    }
}
