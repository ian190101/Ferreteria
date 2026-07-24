<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\SiatBranchSetting;
use App\Modules\Billing\Models\SiatCufd;
use App\Modules\Billing\Models\SiatCuis;
use Illuminate\Validation\ValidationException;

class SiatConfigurationService
{
    public function settingForBranch(int $branchId): SiatBranchSetting
    {
        $setting = SiatBranchSetting::query()
            ->with('branch:id,name,address,phone')
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->first();

        if (! $setting) {
            throw ValidationException::withMessages([
                'billing' => 'La sucursal no tiene configuracion SIAT activa. Configura NIT, codigo de sistema, token, CUIS y CUFD antes de facturar.',
            ]);
        }

        return $setting;
    }

    public function activeCuis(int $branchId): ?SiatCuis
    {
        return SiatCuis::query()
            ->where('branch_id', $branchId)
            ->where('status', SiatCuis::STATUS_ACTIVE)
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->latest('id')
            ->first();
    }

    public function activeCufd(int $branchId): ?SiatCufd
    {
        return SiatCufd::query()
            ->where('branch_id', $branchId)
            ->where('status', SiatCufd::STATUS_ACTIVE)
            ->where(function ($query) {
                $query->whereNull('valid_until')->orWhere('valid_until', '>', now());
            })
            ->latest('id')
            ->first();
    }

    public function requireActiveCufd(int $branchId): SiatCufd
    {
        $cufd = $this->activeCufd($branchId);

        if (! $cufd) {
            throw ValidationException::withMessages([
                'billing' => 'No existe un CUFD vigente para esta sucursal. Solicita o sincroniza CUFD antes de emitir factura.',
            ]);
        }

        return $cufd;
    }
}
