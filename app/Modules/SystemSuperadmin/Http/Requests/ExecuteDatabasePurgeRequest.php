<?php

namespace App\Modules\SystemSuperadmin\Http\Requests;

use App\Support\SystemRoles;
use Illuminate\Foundation\Http\FormRequest;

class ExecuteDatabasePurgeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole(SystemRoles::SYSTEM_SUPERADMIN) ?? false;
    }

    public function rules(): array
    {
        return [
            'scope' => ['required', 'in:selected,all_operational,full_reset'],
            'modules' => ['nullable', 'array'],
            'modules.*' => ['string'],
            'tables' => ['nullable', 'array'],
            'tables.*' => ['string'],
            'only_soft_deleted' => ['nullable', 'boolean'],
            'include_dependencies' => ['nullable', 'boolean'],
            'reset_auto_increment' => ['nullable', 'boolean'],
            'create_backup' => ['nullable', 'boolean'],
            'reset_sequences' => ['nullable', 'boolean'],
            'sequence_ids' => ['nullable', 'array'],
            'sequence_ids.*' => ['integer', 'min:1'],
            'confirmation' => ['required', 'string'],
            'preview_token' => ['required', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $scope = $this->input('scope');
            $confirmation = strtoupper(trim((string) $this->input('confirmation')));

            if ($scope === 'all_operational') {
                if (! in_array($confirmation, ['BORRAR OPERATIVO', 'BORRAR TODO', 'CONFIRMAR'], true)) {
                    $validator->errors()->add('confirmation', 'Para borrar toda la base de datos operativa debes ingresar "BORRAR OPERATIVO" o "CONFIRMAR".');
                }
            } elseif ($scope === 'full_reset') {
                if (! in_array($confirmation, ['RESET TOTAL', 'CONFIRMAR'], true)) {
                    $validator->errors()->add('confirmation', 'Para el reinicio total de fábrica debes ingresar "RESET TOTAL" o "CONFIRMAR".');
                }
            } else {
                if (! in_array($confirmation, ['BORRAR', 'CONFIRMAR'], true)) {
                    $validator->errors()->add('confirmation', 'Para purgar los datos seleccionados debes ingresar "BORRAR" o "CONFIRMAR".');
                }

                $modules = $this->input('modules', []);
                $tables = $this->input('tables', []);

                if (empty($modules) && empty($tables)) {
                    $validator->errors()->add('modules', 'Debes seleccionar al menos un módulo o tabla para purgar.');
                }
            }
        });
    }
}
