<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PackingList extends Model
{
    use HasFactory;

    protected $fillable = [
        'follow_up_item_id',
        'packing_list_reference',
        'packing_list_date',
        'package_size',
        'gross_weight',
        'net_weight',
        'remarks',
        'docx_path',
        'pdf_path',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'packing_list_date' => 'date',
        ];
    }

    public function followUpItem(): BelongsTo
    {
        return $this->belongsTo(FollowUpItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PackingListItem::class)->orderBy('line_number');
    }
}
