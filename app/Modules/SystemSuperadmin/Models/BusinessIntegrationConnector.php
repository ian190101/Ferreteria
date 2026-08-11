<?php

namespace App\Modules\SystemSuperadmin\Models;

use App\Modules\Branches\Models\Branch;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class BusinessIntegrationConnector extends AuditableModel
{
    protected $fillable = [
        'branch_id',
        'code',
        'name',
        'provider',
        'channel',
        'direction',
        'auth_type',
        'base_url',
        'webhook_url',
        'rate_limit_per_minute',
        'timeout_seconds',
        'capabilities',
        'public_config',
        'secret_config',
        'metadata',
        'last_checked_at',
        'last_status',
        'is_active',
    ];

    protected $hidden = [
        'secret_config',
    ];

    protected $appends = [
        'secret_summary',
    ];

    protected $casts = [
        'branch_id' => 'integer',
        'rate_limit_per_minute' => 'integer',
        'timeout_seconds' => 'integer',
        'capabilities' => 'array',
        'public_config' => 'array',
        'metadata' => 'array',
        'last_checked_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    protected function secretConfig(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? Crypt::decryptString($value) : null,
            set: fn ($value) => $value === null || $value === ''
                ? null
                : Crypt::encryptString(is_array($value) ? json_encode($value) : (string) $value),
        );
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function decodedSecretConfig(): array
    {
        $secret = $this->secret_config;

        if (! is_string($secret) || trim($secret) === '') {
            return [];
        }

        $decoded = json_decode($secret, true);

        return is_array($decoded) ? $decoded : ['value' => $secret];
    }

    public function getSecretSummaryAttribute(): string
    {
        $keys = array_keys($this->decodedSecretConfig());

        if ($keys === []) {
            return 'Sin secreto configurado';
        }

        return 'Configurado: '.implode(', ', array_slice($keys, 0, 4));
    }
}
