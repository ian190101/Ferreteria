<?php

namespace App\Modules\SystemSuperadmin\Services;

class BusinessSpecializedReportTemplateFactory
{
    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function all(): array
    {
        return [
            'Restaurante completo' => [
                $this->template('reporte_restaurante_mesas', 'Ventas por mesa y mesero', 'restaurant_summary', 'kitchen_order', ['date', 'branch', 'order', 'table', 'waiter', 'status', 'total'], ['table', 'waiter'], ['total_sales' => 'sum:total']),
                $this->template('reporte_restaurante_cocina', 'Produccion cocina/barra', 'restaurant_summary', 'kitchen_order', ['date', 'branch', 'order', 'area', 'status', 'total'], ['area', 'status'], ['orders' => 'count:order']),
            ],
            'Comida rapida y cafeteria' => [
                $this->template('reporte_comida_rapida_turno', 'Ventas rapidas por turno', 'sales', 'sale', ['branch', 'number', 'date', 'status', 'total', 'paid'], ['branch', 'status'], ['total_sales' => 'sum:total']),
                $this->template('reporte_comida_rapida_cocina', 'Comandas rapidas por area', 'restaurant_summary', 'kitchen_order', ['date', 'branch', 'order', 'area', 'status'], ['area', 'status'], ['orders' => 'count:order']),
            ],
            'Foodtruck' => [
                $this->template('reporte_foodtruck_turno', 'Foodtruck por turno y caja', 'sales', 'sale', ['branch', 'number', 'date', 'total', 'paid', 'balance'], ['branch'], ['total_sales' => 'sum:total']),
            ],
            'Servicios profesionales' => [
                $this->template('reporte_servicios_cliente', 'Servicios por cliente', 'service_orders_summary', 'service_order', ['date', 'branch', 'order', 'service', 'customer', 'status', 'labor', 'total'], ['customer', 'status'], ['total_services' => 'sum:total']),
            ],
            'Taller y servicio tecnico' => [
                $this->template('reporte_taller_tecnico', 'Ordenes por tecnico y estado', 'service_orders_summary', 'service_order', ['date', 'branch', 'order', 'service', 'customer', 'worker', 'status', 'materials', 'total'], ['worker', 'status'], ['materials_total' => 'sum:materials']),
            ],
            'Mecanica autos y motos' => [
                $this->template('reporte_mecanica_ordenes', 'Mecanica por tecnico y garantia', 'service_orders_summary', 'service_order', ['date', 'branch', 'order', 'customer', 'worker', 'status', 'materials', 'total'], ['worker', 'status'], ['order_total' => 'sum:total']),
            ],
            'Reparacion celulares y laptops' => [
                $this->template('reporte_reparaciones_estado', 'Reparaciones por estado', 'service_orders_summary', 'service_order', ['date', 'branch', 'order', 'customer', 'worker', 'status', 'materials', 'total'], ['status', 'worker'], ['order_count' => 'count:order']),
            ],
            'Lavadero de autos' => [
                $this->template('reporte_lavadero_servicios', 'Lavadero por tipo de servicio', 'service_orders_summary', 'service_order', ['date', 'branch', 'service', 'customer', 'worker', 'status', 'total'], ['service', 'worker'], ['service_total' => 'sum:total']),
            ],
            'Odontologia' => [
                $this->template('reporte_odontologia_tratamientos', 'Tratamientos odontologicos', 'service_orders_summary', 'service_order', ['date', 'branch', 'service', 'customer', 'worker', 'status', 'total'], ['service', 'status'], ['treatment_total' => 'sum:total'], ['sensitive' => true]),
                $this->template('reporte_odontologia_citas', 'Citas odontologicas', 'reservations_summary', 'reservation', ['start', 'end', 'branch', 'number', 'resource', 'customer', 'status', 'advance', 'total'], ['resource', 'status'], ['appointments' => 'count:number'], ['sensitive' => true]),
            ],
            'Consultorio medico' => [
                $this->template('reporte_consultorio_citas', 'Citas y consultas medicas', 'reservations_summary', 'reservation', ['start', 'end', 'branch', 'number', 'resource', 'customer', 'status', 'advance', 'total'], ['resource', 'status'], ['appointments' => 'count:number'], ['sensitive' => true]),
            ],
            'Medicina estetica y spa' => [
                $this->template('reporte_estetica_sesiones', 'Sesiones y paquetes esteticos', 'service_orders_summary', 'service_order', ['date', 'branch', 'service', 'customer', 'worker', 'status', 'materials', 'total'], ['service', 'worker'], ['sessions_total' => 'sum:total'], ['sensitive' => true]),
            ],
            'Veterinaria' => [
                $this->template('reporte_veterinaria_consultas', 'Consultas veterinarias', 'service_orders_summary', 'service_order', ['date', 'branch', 'service', 'customer', 'worker', 'status', 'materials', 'total'], ['service', 'status'], ['consultations' => 'count:order']),
            ],
            'Gimnasio' => [
                $this->template('reporte_gimnasio_membresias', 'Membresias y vigencias', 'sales', 'sale', ['branch', 'number', 'customer', 'date', 'status', 'total', 'paid', 'balance'], ['status'], ['memberships_total' => 'sum:total']),
            ],
            'Mudanzas' => [
                $this->template('reporte_mudanzas_servicios', 'Mudanzas por estado y monto', 'service_orders_summary', 'service_order', ['date', 'branch', 'service', 'customer', 'worker', 'status', 'total'], ['status', 'worker'], ['moving_total' => 'sum:total']),
            ],
            'Arquitectura y construccion' => [
                $this->template('reporte_construccion_presupuestos', 'Presupuestos de obra', 'sales', 'sale', ['branch', 'number', 'customer', 'date', 'status', 'total', 'balance'], ['customer', 'status'], ['budget_total' => 'sum:total']),
                $this->template('reporte_construccion_materiales', 'Materiales y stock de obra', 'inventory', 'product', ['branch', 'product', 'sku', 'unit', 'available', 'reserved', 'minimum'], ['branch'], ['available_total' => 'sum:available']),
            ],
            'Prestamos y casa de empeno' => [
                $this->template('reporte_prestamos_cobranza', 'Prestamos y cobranza', 'finance', 'loan', ['date', 'branch', 'income', 'outflows', 'profit'], ['branch'], ['net_result' => 'sum:profit'], ['sensitive' => true]),
                $this->template('reporte_prestamos_clientes', 'Clientes con operaciones sensibles', 'customers', 'customer', ['document', 'name', 'phone', 'active'], ['active'], ['customers' => 'count:name'], ['sensitive' => true]),
            ],
            'Hotel y hostal' => [
                $this->template('reporte_hotel_ocupacion', 'Reservas y ocupacion', 'reservations_summary', 'reservation', ['start', 'end', 'branch', 'number', 'resource', 'customer', 'status', 'advance', 'deposit', 'total'], ['resource', 'status'], ['booking_total' => 'sum:total']),
            ],
            'Canchas deportivas' => [
                $this->template('reporte_canchas_horarios', 'Reservas por cancha', 'reservations_summary', 'reservation', ['start', 'end', 'branch', 'resource', 'customer', 'status', 'advance', 'total'], ['resource', 'status'], ['booking_total' => 'sum:total']),
            ],
            'Alquileres' => [
                $this->template('reporte_alquileres_devoluciones', 'Alquileres, garantias y penalidades', 'reservations_summary', 'reservation', ['start', 'end', 'branch', 'number', 'resource', 'customer', 'status', 'deposit', 'penalty', 'total'], ['resource', 'status'], ['penalties' => 'sum:penalty']),
            ],
            'Alquiler equipos y herramientas' => [
                $this->template('reporte_alquiler_equipos', 'Equipos alquilados y garantias', 'reservations_summary', 'reservation', ['start', 'end', 'branch', 'resource', 'customer', 'status', 'deposit', 'penalty', 'total'], ['resource', 'status'], ['rental_total' => 'sum:total']),
            ],
            'Educacion y cursos' => [
                $this->template('reporte_educacion_inscripciones', 'Inscripciones y pagos', 'sales', 'sale', ['branch', 'number', 'customer', 'date', 'status', 'total', 'paid', 'balance'], ['status'], ['enrollment_total' => 'sum:total']),
            ],
            'Eventos y salones' => [
                $this->template('reporte_eventos_reservas', 'Eventos y salones reservados', 'reservations_summary', 'reservation', ['start', 'end', 'branch', 'resource', 'customer', 'status', 'advance', 'deposit', 'total'], ['resource', 'status'], ['event_total' => 'sum:total']),
            ],
            'Transporte y logistica' => [
                $this->template('reporte_transporte_servicios', 'Servicios de transporte', 'service_orders_summary', 'service_order', ['date', 'branch', 'service', 'customer', 'worker', 'status', 'materials', 'total'], ['worker', 'status'], ['transport_total' => 'sum:total']),
                $this->template('reporte_transporte_despachos', 'Despachos y responsables', 'deliveries', 'delivery', ['branch', 'sale', 'driver', 'truck', 'date', 'status', 'notes'], ['driver', 'status'], ['deliveries' => 'count:sale']),
            ],
            'Fabrica simple' => [
                $this->template('reporte_fabrica_costeo', 'Costeo de produccion', 'production_summary', 'production_order', ['date', 'branch', 'order', 'product', 'status', 'quantity', 'waste', 'input_cost', 'total_cost', 'unit_cost'], ['product', 'status'], ['production_cost' => 'sum:total_cost']),
            ],
            'Agropecuaria simple' => [
                $this->template('reporte_agro_vencimientos', 'Inventario agropecuario por vencimiento', 'inventory', 'product', ['branch', 'product', 'sku', 'barcode', 'unit', 'available', 'minimum'], ['branch'], ['available_total' => 'sum:available']),
            ],
            'Supermercado' => [
                $this->template('reporte_supermercado_rotacion', 'Rotacion y ventas retail', 'sales', 'sale', ['branch', 'number', 'date', 'status', 'total', 'paid'], ['branch', 'status'], ['sales_total' => 'sum:total']),
            ],
            'Ferreteria con cotizacion y nota' => [
                $this->template('reporte_ferreteria_cotizaciones', 'Cotizaciones y notas por cliente', 'sales', 'sale', ['branch', 'number', 'type', 'customer', 'date', 'status', 'total', 'balance'], ['customer', 'status'], ['sales_total' => 'sum:total']),
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forPreset(string $presetName): array
    {
        return $this->all()[$presetName] ?? [];
    }

    /**
     * @param array<string, string> $metrics
     * @param array<string, mixed> $metadata
     * @param array<int, string> $columns
     * @param array<int, string> $groupings
     * @return array<string, mixed>
     */
    private function template(
        string $code,
        string $name,
        string $module,
        string $entityType,
        array $columns,
        array $groupings = [],
        array $metrics = [],
        array $metadata = [],
    ): array {
        $sensitive = (bool) ($metadata['sensitive'] ?? false);

        return [
            'code' => $code,
            'name' => $name,
            'module' => $module,
            'entity_type' => $entityType,
            'description' => 'Plantilla de reporte configurable para '.$name.'.',
            'columns' => $columns,
            'filters' => ['date_range' => true, 'branch' => true],
            'groupings' => $groupings,
            'metrics' => $metrics,
            'permissions' => [
                'view' => ['reports.view'],
                'export' => $sensitive ? ['exports.sensitive'] : ['reports.view'],
            ],
            'metadata' => $metadata,
            'cache_ttl_minutes' => 15,
            'is_exportable' => true,
            'is_default' => true,
            'is_active' => true,
        ];
    }
}
