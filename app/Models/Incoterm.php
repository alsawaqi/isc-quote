<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Incoterm extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'reminder_days_before_delivery',
        'status',
    ];

    protected $casts = [
        'reminder_days_before_delivery' => 'integer',
    ];
}
