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
            self::capability('uses_cash_flow', 'Finanzas', 'Flujo de efectivo', 'Consolida ingresos, egresos, resultado neto y detalles por sucursal, fecha, origen y metodo.', ['hardware_store', 'supermarket', 'restaurant', 'store']),
            self::capability('uses_banks', 'Bancos', 'Bancos/QR', 'Registra QR, transferencias y conciliacion bancaria.', ['hardware_store', 'restaurant', 'store']),
            self::capability('uses_billing', 'Facturacion', 'Facturacion SIAT', 'Activa documentos fiscales y validaciones SIAT.', ['hardware_store', 'supermarket', 'store']),
            self::capability('uses_commissions', 'Finanzas', 'Comisiones', 'Calcula comisiones por vendedor, mesero, tecnico, producto o margen.', ['restaurant', 'services', 'store']),
            self::capability('uses_custom_fields', 'Sistema', 'Campos personalizados', 'Permite campos por entidad con validacion backend.', ['mixed', 'services', 'rentals']),
            self::capability('uses_custom_statuses', 'Sistema', 'Estados personalizados', 'Permite estados y transiciones por entidad.', ['restaurant', 'services', 'factory', 'rentals']),
            self::capability('uses_price_lists', 'Precios', 'Listas de precios', 'Precios por sucursal, cliente, canal u horario.', ['hardware_store', 'restaurant', 'store']),
            self::capability('uses_printer_profiles', 'Impresion', 'Impresoras por area', 'Define caja, cocina, barra, etiquetas y factura.', ['restaurant', 'supermarket', 'store']),
            self::capability('uses_offline_pos', 'PWA', 'POS offline parcial', 'Permite cola local y recibo temporal segun perfil.', ['supermarket', 'store']),
            self::capability('uses_dynamic_entities', 'Motor 2.0', 'Entidades configurables', 'Permite activar entidades como paciente, vehiculo, mascota, proyecto, prestamo o recurso sin crear modulos aislados.', ['mixed', 'services', 'health_clinic', 'dental_clinic', 'loans', 'construction']),
            self::capability('uses_dynamic_fields', 'Motor 2.0', 'Atributos personalizados', 'Permite definir campos por entidad con validacion, visibilidad y auditoria.', ['mixed', 'services', 'health_clinic', 'dental_clinic', 'technical_service'], ['uses_dynamic_entities']),
            self::capability('uses_dynamic_relationships', 'Motor 2.0', 'Relaciones entre entidades', 'Relaciona clientes con vehiculos, pacientes con tratamientos, prestamos con garantias u ordenes con materiales.', ['mixed', 'health_clinic', 'auto_mechanic', 'loans'], ['uses_dynamic_entities']),
            self::capability('uses_dynamic_forms', 'Motor 2.0', 'Formularios por flujo', 'Define campos requeridos por momento del proceso, no solo por entidad.', ['mixed', 'services', 'loans', 'rentals'], ['uses_dynamic_fields']),
            self::capability('uses_field_level_permissions', 'Seguridad', 'Permisos por campo', 'Controla que roles ven o editan campos sensibles como historial medico, costos, sueldos o garantias.', ['mixed', 'health_clinic', 'loans'], ['uses_dynamic_fields']),
            self::capability('uses_attachments', 'Documentos', 'Adjuntos y evidencias', 'Permite fotos, PDFs, contratos, firmas, radiografias o evidencias asociadas a entidades.', ['services', 'health_clinic', 'dental_clinic', 'technical_service', 'loans']),
            self::capability('uses_automation_rules', 'Automatizacion', 'Automatizaciones y recordatorios', 'Activa recordatorios por reservas, mora, vencimientos, controles, ordenes listas o cajas sin cerrar.', ['mixed', 'services', 'loans', 'health_clinic']),
            self::capability('uses_approval_flows', 'Seguridad', 'Aprobaciones', 'Exige aprobacion para descuentos altos, anulaciones, prestamos, garantias, stock negativo o exportaciones sensibles.', ['mixed', 'loans', 'hardware_store']),
            self::capability('uses_report_templates', 'Reportes', 'Reportes configurables', 'Permite columnas, filtros y agrupaciones por tipo de negocio.', ['mixed', 'factory', 'restaurant', 'services']),
            self::capability('uses_data_governance', 'Seguridad', 'Gobierno de datos', 'Gestiona retencion, datos sensibles, anonimizado, exportacion sensible y auditoria de lectura.', ['mixed', 'health_clinic', 'loans']),
            self::capability('uses_operational_traceability', 'Auditoria', 'Trazabilidad operativa', 'Registra responsables reales: quien preparo, entrego, cobro, aprobo o modifico campos sensibles.', ['restaurant', 'services', 'factory', 'loans']),
            self::capability('uses_integrations', 'Integraciones', 'Puntos de integracion', 'Deja preparados canales como WhatsApp, email, SIAT, impresoras, lectores, basculas, camaras o calendarios externos.', ['mixed']),
            self::capability('uses_health_records', 'Salud', 'Historia clinica', 'Activa ficha medica, antecedentes, diagnosticos, evoluciones y documentos sensibles.', ['health_clinic', 'dental_clinic'], ['uses_dynamic_entities', 'uses_dynamic_fields', 'uses_field_level_permissions']),
            self::capability('uses_dental_chart', 'Salud', 'Odontograma', 'Permite registrar tratamientos por pieza dental con diagrama de adulto o nino.', ['dental_clinic'], ['uses_health_records']),
            self::capability('uses_loans', 'Prestamos', 'Prestamos y cuotas', 'Gestiona solicitudes, desembolsos, cuotas, mora y cobranza.', ['loans'], ['uses_dynamic_entities']),
            self::capability('uses_guarantees', 'Prestamos', 'Garantias', 'Controla garantias con valor minimo, documentos, fotos y devolucion.', ['loans', 'rentals'], ['uses_dynamic_entities']),
            self::capability('uses_projects', 'Proyectos', 'Proyectos', 'Permite obras, etapas, centros de costo y avance por cliente.', ['construction', 'factory'], ['uses_dynamic_entities']),
            self::capability('uses_construction_calculations', 'Calculos', 'Calculos de obra', 'Calcula materiales por m2, m3, espesor, dosificacion, merma y mano de obra.', ['construction'], ['uses_projects']),
            self::capability('uses_vehicles', 'Servicios', 'Vehiculos', 'Relaciona clientes con autos, motos, camiones u otros vehiculos.', ['auto_mechanic', 'car_wash', 'moving_service'], ['uses_dynamic_entities']),
            self::capability('uses_equipment_repairs', 'Servicios', 'Equipos en reparacion', 'Controla celulares, laptops u otros equipos con diagnostico, evidencias y entrega.', ['electronics_repair', 'technical_service'], ['uses_service_orders']),
            self::capability('uses_pet_records', 'Servicios', 'Mascotas', 'Relaciona clientes con mascotas, vacunas, consultas y servicios.', ['veterinary'], ['uses_dynamic_entities']),
            self::capability('uses_memberships', 'Servicios', 'Membresias', 'Controla planes, vigencias, pagos recurrentes y congelamientos.', ['gym'], ['uses_dynamic_entities']),
            self::capability('uses_courses', 'Servicios', 'Cursos e inscripciones', 'Controla cursos, estudiantes, pagos, horarios, docentes y certificados.', ['education'], ['uses_dynamic_entities']),
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
