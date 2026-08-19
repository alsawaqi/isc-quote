<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Contact extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'designation_id',
        'name',
        'job_title',
        'mobile',
        'telephone',
        'extension',
        'email',
        'fax',
        'is_primary',
        'serves_buyer',
        'serves_supplier',
        'all_locations',
        'is_primary_buyer',
        'is_primary_supplier',
        'status',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'serves_buyer' => 'boolean',
        'serves_supplier' => 'boolean',
        'all_locations' => 'boolean',
        'is_primary_buyer' => 'boolean',
        'is_primary_supplier' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    public function supplierProfile(): HasOne
    {
        return $this->hasOne(Supplier::class, 'primary_contact_id');
    }

    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(CompanyLocation::class, 'contact_company_location')->withTimestamps();
    }
}
