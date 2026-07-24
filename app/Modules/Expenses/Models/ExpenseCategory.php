<?php

namespace App\Modules\Expenses\Models;

use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ExpenseCategory extends AuditableModel
{
    use SoftDeletes;

    public const SALARY_PAYROLL_CODE = 'salary-payroll';

    public const SALARY_PAYROLL_NAME = 'Pago de sueldos';

    protected $fillable = [
        'name',
        'code',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
