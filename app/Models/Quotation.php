<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quotation extends Model
{
    use HasFactory;

    protected $fillable = [
        'quotation_reference',
        'salesperson_id',
        'supplier_company_id',
        'supplier_contact_id',
        'buyer_company_id',
        'buyer_contact_id',
        'rfq_number',
        'pr_number',
        'closing_at',
        'quotation_validity_value',
        'quotation_validity_unit',
        'payment_term_days',
        'delivery_period_min',
        'delivery_period_max',
        'delivery_period_unit',
        'delivery_period_type',
        'accepted_invoice_currency',
        'incoterm_id',
        'delivery_responsibility',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'quotation_validity_value' => 'integer',
            'payment_term_days' => 'integer',
            'delivery_period_min' => 'integer',
            'delivery_period_max' => 'integer',
            'closing_at' => 'datetime',
        ];
    }

    public function salesperson(): BelongsTo
    {
        return $this->belongsTo(User::class, 'salesperson_id');
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

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class);
    }

    public function terms(): HasMany
    {
        return $this->hasMany(QuotationTerm::class)->orderBy('line_number');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(QuotationVersion::class)->latest('version_number');
    }

    public function buyerPos(): HasMany
    {
        return $this->hasMany(BuyerPo::class)->latest();
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(QuotationActivityLog::class)->latest();
    }
}
