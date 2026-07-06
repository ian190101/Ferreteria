<?php

namespace App\Modules\Sales\Models;

use App\Modules\Branches\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentSequence extends Model
{
    protected $fillable = [
        'branch_id',
        'document_type',
        'name',
        'prefix',
        'next_number',
        'padding',
        'is_active',
    ];

    protected $casts = [
        'next_number' => 'integer',
        'padding' => 'integer',
        'is_active' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function preview(?int $number = null): string
    {
        return $this->prefix.str_pad((string) ($number ?? $this->next_number), $this->padding, '0', STR_PAD_LEFT);
    }
}
