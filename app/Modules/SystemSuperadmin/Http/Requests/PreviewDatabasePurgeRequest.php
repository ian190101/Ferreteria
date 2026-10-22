<?php

declare(strict_types=1);

namespace App\Modules\SystemSuperadmin\Http\Requests;

final class PreviewDatabasePurgeRequest extends ExecuteDatabasePurgeRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['confirmation'], $rules['preview_token']);

        return $rules;
    }

    public function withValidator($validator): void {}
}
