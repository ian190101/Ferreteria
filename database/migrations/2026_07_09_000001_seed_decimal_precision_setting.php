<?php

use App\Support\DecimalPrecision;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('system_settings')->updateOrInsert(
            ['key' => DecimalPrecision::SETTING_KEY],
            [
                'group' => 'formatos',
                'value' => json_encode(DecimalPrecision::defaults(), JSON_UNESCAPED_UNICODE),
                'description' => 'Gestion de decimales globales y por modulo',
                'is_public' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('system_settings')->where('key', DecimalPrecision::SETTING_KEY)->delete();
    }
};
