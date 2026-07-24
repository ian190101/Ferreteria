<?php

use App\Modules\SystemSuperadmin\Services\BusinessProfileConfiguration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $profile = DB::table('business_profiles')
            ->where('status', 'active')
            ->latest('applied_at')
            ->first();

        if (! $profile) {
            return;
        }

        $configuration = BusinessProfileConfiguration::normalized(json_decode($profile->configuration, true) ?: []);
        $businessType = (string) ($profile->business_type ?? '');

        if (! in_array($businessType, ['hardware_store', 'mixed'], true)) {
            return;
        }

        $configuration['modules']['billing'] = true;
        $configuration['billing'] = [
            ...$configuration['billing'],
            'enabled' => true,
            'invoice_flow' => 'quote_sale_note_invoice',
            'issue_from' => 'sale_note',
            'issue_timing' => 'automatic_after_quote_conversion',
            'mode' => $configuration['billing']['mode'] ?? 'computerized_online',
            'document_sector' => $configuration['billing']['document_sector'] ?? 'compra_venta',
            'require_product_mapping' => true,
            'block_sale_if_invoice_fails' => true,
        ];

        DB::table('business_profiles')
            ->where('id', $profile->id)
            ->update([
                'configuration' => json_encode($configuration),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // No revertimos el perfil activo porque puede haber sido ajustado manualmente por sistemasuperadmin.
    }
};
