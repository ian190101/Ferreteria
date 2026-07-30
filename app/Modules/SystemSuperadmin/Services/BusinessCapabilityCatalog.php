<?php

namespace App\Modules\SystemSuperadmin\Services;

class BusinessCapabilityCatalog
{
    public static function all(): array
    {
        return collect([
            self::capability('uses_inventory', 'Inventario', 'Inventario por sucursal', 'Controla existencias por sucursal o almacen.', ['hardware_store', 'restaurant', 'supermarket', 'factory', 'store']),
            self::capability('uses_lots', 'Inventario', 'Lotes o unidad fisica', 'Permite rastrear lotes, vencimientos, rollos o unidades fisicas.', ['hardware_store', 'factory']),
            self::capability('uses_expiration_dates', 'Inventario', 'Fechas de vencimiento', 'Controla vencimientos para productos sensibles.', ['restaurant', 'supermarket', 'store']),
            self::capability('uses_recipes', 'Inventario', 'Recetas e insumos', 'Descuenta insumos por receta al vender productos preparados.', ['restaurant', 'factory'], ['uses_inventory']),
            self::capability('uses_production', 'Produccion', 'Produccion simple', 'Maneja formulas, insumos, producto terminado, merma y costos.', ['factory'], ['uses_inventory']),
            self::capability('uses_services', 'Servicios', 'Servicios sin stock obligatorio', 'Permite vender servicios, mano de obra y trabajos por orden.', ['services', 'mixed']),
            self::capability('uses_service_orders', 'Servicios', 'Ordenes de servicio', 'Gestiona diagnostico, tecnico, estados, evidencias y cierre.', ['services'], ['uses_services']),
            self::capability('uses_reservations', 'Reservas', 'Reservas y citas', 'Permite agendar reservas por fecha, hora, cliente y recurso.', ['restaurant', 'services', 'rentals']),
            self::capability('uses_reservable_resources', 'Reservas', 'Recursos reservables', 'Mesas, tecnicos, equipos, salones, habitaciones o productos alquilables.', ['restaurant', 'services', 'rentals'], ['uses_reservations']),
            self::capability('uses_rentals', 'Alquileres', 'Alquileres', 'Gestiona salida, devolucion, garantia, penalidad y estado del bien.', ['rentals'], ['uses_reservations', 'uses_reservable_resources']),
            self::capability('uses_tables', 'Restaurante', 'Mesas', 'Controla mesas, salones y consumo en mesa.', ['restaurant'], ['uses_pos']),
            self::capability('uses_waiters', 'Restaurante', 'Meseros', 'Asigna meseros a mesas, comandas y ventas.', ['restaurant'], ['uses_tables']),
            self::capability('uses_kitchen_orders', 'Restaurante', 'Comandas cocina/barra', 'Envia items a cocina, barra u otra area de preparacion.', ['restaurant'], ['uses_pos']),
            self::capability('uses_tips', 'Finanzas', 'Propinas', 'Permite registrar propinas obligatorias, opcionales o desactivadas.', ['restaurant']),
            self::capability('uses_split_payments', 'Finanzas', 'Pago dividido o mixto', 'Permite dividir cuenta o cobrar con varios metodos.', ['restaurant', 'supermarket', 'store']),
            self::capability('uses_delivery', 'Ventas', 'Delivery', 'Activa direccion de entrega, repartidor y seguimiento.', ['restaurant', 'store']),
            self::capability('requires_customer', 'Ventas', 'Cliente obligatorio', 'Exige cliente para vender, facturar o llevar cuentas por cobrar.', ['hardware_store', 'services']),
            self::capability('allows_direct_sale', 'Ventas', 'Venta directa', 'Permite venta sin cotizacion previa.', ['supermarket', 'store', 'restaurant']),
            self::capability('allows_quote_to_sale', 'Ventas', 'Cotizacion a nota', 'Permite convertir cotizaciones en notas de venta.', ['hardware_store', 'factory']),
            self::capability('uses_pos', 'POS', 'POS dinamico', 'Activa la interfaz POS configurada por tipo de negocio.', ['restaurant', 'supermarket', 'store']),
            self::capability('uses_barcode_scanner', 'POS', 'Lector de barras', 'Optimiza busqueda rapida por codigo de barras.', ['supermarket', 'store', 'hardware_store'], ['uses_pos']),
            self::capability('requires_cash_session', 'Caja', 'Caja obligatoria', 'Exige caja abierta antes de vender o cobrar.', ['hardware_store', 'supermarket', 'restaurant'], ['uses_cash']),
            self::capability('uses_cash', 'Caja', 'Caja', 'Gestiona apertura, cierre y cuadre de efectivo por alcance.', ['hardware_store', 'supermarket', 'restaurant', 'store']),
            self::capability('uses_banks', 'Bancos', 'Bancos/QR', 'Registra QR, transferencias y conciliacion bancaria.', ['hardware_store', 'restaurant', 'store']),
            self::capability('uses_billing', 'Facturacion', 'Facturacion SIAT', 'Activa documentos fiscales y validaciones SIAT.', ['hardware_store', 'supermarket', 'store']),
            self::capability('uses_commissions', 'Finanzas', 'Comisiones', 'Calcula comisiones por vendedor, mesero, tecnico, producto o margen.', ['restaurant', 'services', 'store']),
            self::capability('uses_custom_fields', 'Sistema', 'Campos personalizados', 'Permite campos por entidad con validacion backend.', ['mixed', 'services', 'rentals']),
            self::capability('uses_custom_statuses', 'Sistema', 'Estados personalizados', 'Permite estados y transiciones por entidad.', ['restaurant', 'services', 'factory', 'rentals']),
            self::capability('uses_price_lists', 'Precios', 'Listas de precios', 'Precios por sucursal, cliente, canal u horario.', ['hardware_store', 'restaurant', 'store']),
            self::capability('uses_printer_profiles', 'Impresion', 'Impresoras por area', 'Define caja, cocina, barra, etiquetas y factura.', ['restaurant', 'supermarket', 'store']),
            self::capability('uses_offline_pos', 'PWA', 'POS offline parcial', 'Permite cola local y recibo temporal segun perfil.', ['supermarket', 'store']),
        ])->keyBy('code')->all();
    }

    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function byKey(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    public static function recommendedFor(string $businessType): array
    {
        return collect(self::all())
            ->filter(fn (array $capability) => in_array($businessType, $capability['recommended_business_types'] ?? [], true))
            ->keys()
            ->values()
            ->all();
    }

    public static function matrix(): array
    {
        $types = BusinessProfileConfiguration::options()['businessTypes'];

        return collect($types)
            ->mapWithKeys(fn (string $label, string $type) => [$type => self::recommendedFor($type)])
            ->all();
    }

    private static function capability(
        string $code,
        string $group,
        string $name,
        string $description,
        array $recommendedBusinessTypes = [],
        array $dependencies = [],
        array $conflicts = [],
        array $metadata = [],
    ): array {
        return [
            'code' => $code,
            'group' => $group,
            'name' => $name,
            'description' => $description,
            'recommended_business_types' => $recommendedBusinessTypes,
            'dependencies' => $dependencies,
            'conflicts' => $conflicts,
            'metadata' => $metadata,
            'is_active' => true,
        ];
    }
}
