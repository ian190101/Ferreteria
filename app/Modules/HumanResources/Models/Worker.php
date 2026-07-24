<?php

namespace App\Modules\HumanResources\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Worker extends AuditableModel
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'branch_id',
        'name',
        'document_number',
        'phone',
        'position',
        'hired_at',
        'salary_amount',
        'salary_frequency',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'hired_at' => 'date',
        'salary_amount' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function salaryPayments(): HasMany
    {
        return $this->hasMany(SalaryPayment::class);
    }
}
