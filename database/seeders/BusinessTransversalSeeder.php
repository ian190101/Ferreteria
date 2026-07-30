<?php

namespace Database\Seeders;

use App\Modules\SystemSuperadmin\Models\BusinessCurrency;
use App\Modules\SystemSuperadmin\Models\ImportProfileTemplate;
use App\Modules\SystemSuperadmin\Models\NotificationRule;
use App\Modules\SystemSuperadmin\Models\PrinterProfile;
use Illuminate\Database\Seeder;

class BusinessTransversalSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->currencies() as $currency) {
            BusinessCurrency::query()->updateOrCreate(['code' => $currency['code']], $currency);
        }

        foreach ($this->imports() as $template) {
            ImportProfileTemplate::query()->updateOrCreate(['code' => $template['code']], $template);
        }

        foreach ($this->notifications() as $notification) {
            NotificationRule::query()->updateOrCreate(['code' => $notification['code']], $notification);
        }

        foreach ($this->printers() as $printer) {
            PrinterProfile::query()->updateOrCreate(['branch_id' => null, 'code' => $printer['code']], $printer);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function currencies(): array
    {
        return [
            [
                'code' => 'BOB',
                'name' => 'Bolivianos',
                'symbol' => 'Bs',
                'exchange_rate_to_base' => 1,
                'rounding_decimals' => 1,
                'is_base' => true,
                'cash_enabled' => true,
                'is_active' => true,
            ],
            [
                'code' => 'USD',
                'name' => 'Dolares estadounidenses',
                'symbol' => '$',
                'exchange_rate_to_base' => 6.96,
                'rounding_decimals' => 2,
                'is_base' => false,
                'cash_enabled' => true,
                'is_active' => true,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function imports(): array
    {
        return [
            [
                'code' => 'productos_base',
                'name' => 'Productos base',
                'entity_type' => 'products',
                'required_columns' => ['nombre', 'unidad', 'precio_venta'],
                'optional_columns' => ['sku', 'barcode', 'categoria', 'precio_compra', 'stock_inicial', 'sucursal'],
                'mapping_rules' => ['formatos' => ['xlsx', 'csv']],
                'validation_rules' => ['barcode' => 'unico si se informa'],
                'is_active' => true,
            ],
            [
                'code' => 'clientes_base',
                'name' => 'Clientes base',
                'entity_type' => 'customers',
                'required_columns' => ['nombre'],
                'optional_columns' => ['nit', 'telefono', 'direccion', 'tipo'],
                'mapping_rules' => ['formatos' => ['xlsx', 'csv']],
                'validation_rules' => ['nit' => 'opcional salvo facturacion obligatoria'],
                'is_active' => true,
            ],
            [
                'code' => 'stock_inicial',
                'name' => 'Stock inicial por sucursal',
                'entity_type' => 'stock_initial',
                'required_columns' => ['producto', 'sucursal', 'cantidad', 'unidad'],
                'optional_columns' => ['lote', 'vencimiento', 'costo'],
                'mapping_rules' => ['formatos' => ['xlsx', 'csv']],
                'validation_rules' => ['cantidad' => 'mayor o igual a cero'],
                'is_active' => true,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function notifications(): array
    {
        return [
            [
                'code' => 'stock_bajo',
                'name' => 'Stock bajo',
                'trigger' => 'stock_low',
                'channels' => ['system'],
                'recipients' => ['roles' => ['superadmin', 'administrador']],
                'conditions' => ['usar_stock_minimo_producto' => true],
                'is_active' => true,
            ],
            [
                'code' => 'backup_fallido',
                'name' => 'Backup fallido',
                'trigger' => 'backup_failed',
                'channels' => ['system', 'email'],
                'recipients' => ['roles' => ['sistemasuperadmin']],
                'conditions' => ['severidad' => 'critica'],
                'is_active' => true,
            ],
            [
                'code' => 'siat_fallido',
                'name' => 'Fallo SIAT',
                'trigger' => 'siat_failed',
                'channels' => ['system', 'email'],
                'recipients' => ['roles' => ['sistemasuperadmin', 'superadmin']],
                'conditions' => ['severidad' => 'alta'],
                'is_active' => true,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function printers(): array
    {
        return [
            [
                'branch_id' => null,
                'code' => 'caja_principal',
                'name' => 'Caja principal',
                'area' => 'cashier',
                'paper_type' => 'letter',
                'copies' => 1,
                'auto_print' => false,
                'settings' => ['uso' => 'documentos comerciales'],
                'is_active' => true,
            ],
            [
                'branch_id' => null,
                'code' => 'etiquetas_barcode',
                'name' => 'Etiquetas barcode',
                'area' => 'labels',
                'paper_type' => 'label',
                'copies' => 1,
                'auto_print' => false,
                'settings' => ['uso' => 'codigos de barra'],
                'is_active' => true,
            ],
        ];
    }
}
