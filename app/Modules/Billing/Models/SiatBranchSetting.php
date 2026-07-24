<?php

namespace App\Modules\Billing\Models;

use App\Modules\Branches\Models\Branch;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class SiatBranchSetting extends AuditableModel
{
    public const ENVIRONMENT_PRODUCTION = 1;

    public const ENVIRONMENT_PILOT = 2;

    public const MODALITY_ELECTRONIC = 1;

    public const MODALITY_COMPUTERIZED = 2;

    protected $fillable = [
        'branch_id',
        'nit',
        'business_name',
        'municipality',
        'phone',
        'system_code',
        'environment_code',
        'modality_code',
        'emission_type_code',
        'invoice_type_code',
        'document_sector_code',
        'economic_activity_code',
        'sin_product_code',
        'siat_branch_code',
        'point_of_sale_code',
        'token_encrypted',
        'certificate_path',
        'certificate_password_encrypted',
        'is_active',
        'options',
    ];

    protected $casts = [
        'environment_code' => 'integer',
        'modality_code' => 'integer',
        'emission_type_code' => 'integer',
        'invoice_type_code' => 'integer',
        'document_sector_code' => 'integer',
        'economic_activity_code' => 'integer',
        'sin_product_code' => 'integer',
        'siat_branch_code' => 'integer',
        'point_of_sale_code' => 'integer',
        'is_active' => 'boolean',
        'options' => 'array',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function cuis(): HasMany
    {
        return $this->hasMany(SiatCuis::class, 'branch_id', 'branch_id');
    }

    public function cufd(): HasMany
    {
        return $this->hasMany(SiatCufd::class, 'branch_id', 'branch_id');
    }

    protected function token(): Attribute
    {
        return Attribute::make(
            get: fn () => filled($this->token_encrypted) ? Crypt::decryptString($this->token_encrypted) : null,
            set: fn ($value) => ['token_encrypted' => filled($value) ? Crypt::encryptString($value) : null],
        );
    }

    protected function certificatePassword(): Attribute
    {
        return Attribute::make(
            get: fn () => filled($this->certificate_password_encrypted) ? Crypt::decryptString($this->certificate_password_encrypted) : null,
            set: fn ($value) => ['certificate_password_encrypted' => filled($value) ? Crypt::encryptString($value) : null],
        );
    }
}
