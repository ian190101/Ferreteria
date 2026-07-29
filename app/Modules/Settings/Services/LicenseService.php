<?php

namespace App\Modules\Settings\Services;

use App\Modules\Settings\Models\ApplicationLicense;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LicenseService
{
    public function createOrUpdate(array $data): ApplicationLicense
    {
        $license = ApplicationLicense::query()->updateOrCreate(
            ['license_key' => $data['license_key'] ?? $this->generateKey($data['nit'] ?? null)],
            [
                'holder_name' => $data['holder_name'],
                'nit' => $data['nit'] ?? null,
                'domain' => $data['domain'] ?? null,
                'support_until' => $data['support_until'] ?? null,
                'allowed_modules' => $data['allowed_modules'] ?? [],
                'activation_mode' => $data['activation_mode'] ?? 'offline',
                'status' => $data['status'] ?? ApplicationLicense::STATUS_ACTIVE,
                'notes' => $data['notes'] ?? null,
            ],
        );

        $this->assertValid($license);

        return $license;
    }

    public function assertValid(?ApplicationLicense $license = null): void
    {
        $license ??= ApplicationLicense::query()->where('status', ApplicationLicense::STATUS_ACTIVE)->latest()->first();

        if (! $license) {
            throw ValidationException::withMessages([
                'license' => 'No hay una licencia activa para esta instalacion.',
            ]);
        }

        if ($license->support_until && $license->support_until->isPast()) {
            throw ValidationException::withMessages([
                'license' => 'La licencia existe, pero la fecha de soporte ya vencio.',
            ]);
        }
    }

    private function generateKey(?string $nit): string
    {
        return 'MRB-'.Str::upper(Str::random(8)).'-'.($nit ?: now()->format('Ymd'));
    }
}
