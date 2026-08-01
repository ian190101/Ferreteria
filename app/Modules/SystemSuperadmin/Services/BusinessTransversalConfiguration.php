<?php

namespace App\Modules\SystemSuperadmin\Services;

class BusinessTransversalConfiguration
{
    /**
     * @return array<string, string>
     */
    public static function baseEntities(): array
    {
        return [
            'product' => 'Producto',
            'customer' => 'Cliente',
            'supplier' => 'Proveedor',
            'sale' => 'Venta',
            'quote' => 'Cotizacion',
            'purchase' => 'Compra',
            'reservation' => 'Reserva',
            'service_order' => 'Orden de servicio',
            'restaurant_table' => 'Mesa',
            'kitchen_order' => 'Comanda/cocina',
            'worker' => 'Trabajador',
            'rental' => 'Alquiler',
            'production_order' => 'Orden de produccion',
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function options(): array
    {
        return [
            'sections' => [
                'entities' => 'Entidades configurables',
                'relationships' => 'Relaciones entre entidades',
                'custom_fields' => 'Campos personalizados',
                'workflows' => 'Flujos de estado',
                'states' => 'Estados personalizados',
                'workflow_transitions' => 'Transiciones de flujo',
                'resources' => 'Recursos reservables',
                'price_lists' => 'Listas de precios',
                'commissions' => 'Comisiones',
                'notifications' => 'Notificaciones',
                'currencies' => 'Monedas',
                'printers' => 'Impresoras por area',
                'licenses' => 'Licencias por modulo',
                'imports' => 'Importaciones por perfil',
            ],
            'entities' => self::baseEntities(),
            'entityModes' => [
                'hidden' => 'Oculta',
                'optional' => 'Opcional',
                'required' => 'Obligatoria',
                'read_only' => 'Solo lectura historica',
            ],
            'relationshipTypes' => [
                'one_to_one' => 'Uno a uno',
                'one_to_many' => 'Uno a muchos',
                'many_to_one' => 'Muchos a uno',
                'many_to_many' => 'Muchos a muchos',
            ],
            'relationshipCascadeBehaviors' => [
                'restrict' => 'Restringir si hay datos relacionados',
                'detach' => 'Desvincular relacion',
                'readonly_history' => 'Mantener historial solo lectura',
            ],
            'retentionPolicies' => [
                'standard' => 'Retencion estandar',
                'sensitive' => 'Dato sensible con acceso restringido',
                'health_record' => 'Historia clinica',
                'financial' => 'Financiero/prestamos',
                'legal_document' => 'Documento legal',
                'permanent_audit' => 'Auditoria permanente',
            ],
            'stateTypes' => [
                'initial' => 'Inicial',
                'intermediate' => 'Intermedio',
                'final' => 'Final',
                'cancelled' => 'Cancelado',
                'on_hold' => 'En espera',
            ],
            'fieldTypes' => [
                'text' => 'Texto',
                'number' => 'Numero',
                'decimal' => 'Decimal',
                'currency' => 'Moneda',
                'percentage' => 'Porcentaje',
                'date' => 'Fecha',
                'time' => 'Hora',
                'datetime' => 'Fecha y hora',
                'boolean' => 'Si/No',
                'select' => 'Seleccion unica',
                'multi_select' => 'Seleccion multiple',
                'file' => 'Archivo',
                'image' => 'Imagen',
                'signature' => 'Firma',
                'location' => 'Ubicacion',
                'phone' => 'Telefono',
                'email' => 'Email',
                'identity_document' => 'Documento de identidad',
                'nit' => 'NIT',
                'barcode' => 'Codigo de barras',
                'formula' => 'Formula calculada',
                'entity_relation' => 'Relacion con entidad',
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
            'resourceStatuses' => [
                'available' => 'Disponible',
                'maintenance' => 'En mantenimiento',
                'blocked' => 'Bloqueado',
                'inactive' => 'Inactivo operativo',
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
