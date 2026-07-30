<?php

namespace App\Modules\SystemSuperadmin\Services;

class BusinessTransversalConfiguration
{
    /**
     * @return array<string, array<string, string>>
     */
    public static function options(): array
    {
        return [
            'sections' => [
                'custom_fields' => 'Campos personalizados',
                'states' => 'Estados personalizados',
                'resources' => 'Recursos reservables',
                'price_lists' => 'Listas de precios',
                'commissions' => 'Comisiones',
                'notifications' => 'Notificaciones',
                'currencies' => 'Monedas',
                'printers' => 'Impresoras por area',
                'licenses' => 'Licencias por modulo',
                'imports' => 'Importaciones por perfil',
            ],
            'entities' => [
                'product' => 'Producto',
                'customer' => 'Cliente',
                'supplier' => 'Proveedor',
                'sale' => 'Venta',
                'quote' => 'Cotizacion',
                'reservation' => 'Reserva',
                'service_order' => 'Orden de servicio',
                'restaurant_table' => 'Mesa',
                'kitchen_order' => 'Comanda/cocina',
                'worker' => 'Trabajador',
                'rental' => 'Alquiler',
                'production_order' => 'Orden de produccion',
            ],
            'fieldTypes' => [
                'text' => 'Texto',
                'number' => 'Numero',
                'decimal' => 'Decimal',
                'date' => 'Fecha',
                'datetime' => 'Fecha y hora',
                'boolean' => 'Si/No',
                'select' => 'Seleccion unica',
                'multi_select' => 'Seleccion multiple',
                'file' => 'Archivo',
                'image' => 'Imagen',
            ],
            'resourceTypes' => [
                'table' => 'Mesa',
                'room' => 'Habitacion',
                'technician' => 'Tecnico',
                'vehicle' => 'Vehiculo',
                'equipment' => 'Equipo',
                'hall' => 'Salon',
                'field' => 'Cancha',
                'rental_product' => 'Producto alquilable',
            ],
            'channels' => [
                'counter' => 'Mostrador',
                'pos' => 'POS',
                'delivery' => 'Delivery',
                'table' => 'Mesa',
                'reservation' => 'Reserva',
                'wholesale' => 'Mayorista',
                'external' => 'Pedido externo',
            ],
            'commissionBases' => [
                'subtotal' => 'Subtotal',
                'margin' => 'Margen',
                'quantity' => 'Cantidad',
                'payment' => 'Cobro',
            ],
            'commissionTypes' => [
                'percentage' => 'Porcentaje',
                'fixed_amount' => 'Monto fijo',
            ],
            'responsibleTypes' => [
                'seller' => 'Vendedor',
                'waiter' => 'Mesero',
                'technician' => 'Tecnico',
                'cashier' => 'Cajero',
                'driver' => 'Repartidor',
            ],
            'notificationTriggers' => [
                'stock_low' => 'Stock bajo',
                'reservation_upcoming' => 'Reserva proxima',
                'order_overdue' => 'Orden vencida',
                'payment_due' => 'Pago pendiente',
                'receivable_overdue' => 'Cuenta por cobrar vencida',
                'product_expiring' => 'Producto por vencer',
                'backup_failed' => 'Backup fallido',
                'siat_failed' => 'Fallo SIAT',
            ],
            'notificationChannels' => [
                'email' => 'Correo',
                'system' => 'Sistema',
                'whatsapp_manual' => 'WhatsApp manual',
            ],
            'printerAreas' => [
                'cashier' => 'Caja',
                'kitchen' => 'Cocina',
                'bar' => 'Barra',
                'warehouse' => 'Almacen',
                'labels' => 'Etiquetas',
                'billing' => 'Factura',
                'closing_report' => 'Reporte de cierre',
            ],
            'paperTypes' => [
                'letter' => 'Carta',
                'half_letter' => 'Media carta',
                'legal' => 'Oficio',
                'thermal_58' => 'Termica 58 mm',
                'thermal_80' => 'Termica 80 mm',
                'label' => 'Etiqueta',
            ],
            'modules' => [
                'sales_notes' => 'Ventas',
                'quotes' => 'Cotizaciones',
                'pos' => 'POS',
                'purchases' => 'Compras',
                'inventory' => 'Inventario',
                'customers' => 'Clientes',
                'suppliers' => 'Proveedores',
                'cash' => 'Caja',
                'banks' => 'Bancos/QR',
                'billing' => 'Facturacion SIAT',
                'restaurant_tables' => 'Mesas',
                'kitchen_orders' => 'Comandas',
                'service_orders' => 'Ordenes de servicio',
                'reservations' => 'Reservas',
                'rentals' => 'Alquileres',
                'production' => 'Produccion',
                'reports' => 'Reportes',
                'exports' => 'Exportaciones',
                'workers' => 'Trabajadores',
                'payroll' => 'Sueldos',
            ],
            'importEntities' => [
                'products' => 'Productos',
                'services' => 'Servicios',
                'customers' => 'Clientes',
                'suppliers' => 'Proveedores',
                'stock_initial' => 'Stock inicial',
                'price_lists' => 'Listas de precios',
                'reservable_resources' => 'Recursos reservables',
                'restaurant_tables' => 'Mesas',
                'recipes' => 'Recetas',
                'variants' => 'Variantes',
                'workers' => 'Trabajadores/tecnicos',
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function listFromCsv(?string $value): array
    {
        return collect(explode(',', (string) $value))
            ->map(fn (string $item) => trim($item))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|array<int, mixed>|null
     */
    public static function jsonFromText(?string $value): ?array
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            return ['texto' => $value];
        }

        return $decoded;
    }
}
