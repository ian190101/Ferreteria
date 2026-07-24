<?php

use App\Modules\Sales\Services\DocumentItemMergePolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('system_settings')->updateOrInsert(
            ['key' => DocumentItemMergePolicy::SETTING_KEY],
            [
                'group' => 'ventas',
                'value' => json_encode(DocumentItemMergePolicy::defaults(), JSON_UNESCAPED_UNICODE),
                'description' => 'Regla de fusion automatica de items en ventas y compras',
                'is_public' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('system_settings')->where('key', DocumentItemMergePolicy::SETTING_KEY)->delete();
    }
};
