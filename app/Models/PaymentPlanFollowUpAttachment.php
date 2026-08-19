<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentPlanFollowUpAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_plan_follow_up_id',
        'uploaded_by',
        'file_path',
        'original_file_name',
        'mime_type',
        'file_size',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
        ];
    }

    public function paymentPlanFollowUp(): BelongsTo
    {
        return $this->belongsTo(PaymentPlanFollowUp::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
