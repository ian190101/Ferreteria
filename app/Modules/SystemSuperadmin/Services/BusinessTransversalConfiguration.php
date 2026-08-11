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
                'attachments' => 'Adjuntos y evidencias',
                'forms' => 'Formularios por flujo',
                'form_fields' => 'Reglas de campos por formulario',
                'document_templates' => 'Plantillas documentales 2.0',
                'report_templates' => 'Plantillas de reportes',
                'calculation_formulas' => 'Formulas de calculo',
                'commercial_flows' => 'Reglas comerciales/POS',
                'custom_fields' => 'Campos personalizados',
                'workflows' => 'Flujos de estado',
                'states' => 'Estados personalizados',
                'workflow_transitions' => 'Transiciones de flujo',
                'resources' => 'Recursos reservables',
                'price_lists' => 'Listas de precios',
                'commissions' => 'Comisiones',
                'notifications' => 'Notificaciones',
                'approval_flows' => 'Reglas de aprobacion',
                'automation_rules' => 'Automatizaciones',
                'integrations' => 'Integraciones externas',
                'customer_qr_channels' => 'Canales de pedidos por QR',
                'customer_qr_orders' => 'Pedidos recibidos por QR',
                'branch_policies' => 'Politicas multi-sucursal',
                'currencies' => 'Monedas',
                'printers' => 'Impresoras por area',
                'licenses' => 'Licencias por modulo',
                'imports' => 'Importaciones por perfil',
            ],
            'entities' => self::baseEntities(),
            'businessTypes' => BusinessProfileConfiguration::options()['businessTypes'],
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
            'attachmentPurposes' => [
                'evidence' => 'Evidencia',
                'identity_document' => 'Documento de identidad',
                'contract' => 'Contrato',
                'medical_record' => 'Historia clinica',
                'before_after' => 'Antes/despues',
                'signature' => 'Firma',
                'guarantee' => 'Garantia',
                'delivery_proof' => 'Comprobante de entrega',
            ],
            'attachmentStorageDisks' => [
                'local' => 'Local privado',
                'public' => 'Publico',
                's3' => 'S3 compatible',
            ],
            'attachmentExtensions' => [
                'jpg' => 'JPG',
                'jpeg' => 'JPEG',
                'png' => 'PNG',
                'webp' => 'WEBP',
                'pdf' => 'PDF',
                'docx' => 'Word',
                'xlsx' => 'Excel',
                'csv' => 'CSV',
            ],
            'attachmentMimeTypes' => [
                'image/jpeg' => 'Imagen JPEG',
                'image/png' => 'Imagen PNG',
                'image/webp' => 'Imagen WEBP',
                'application/pdf' => 'PDF',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'Word DOCX',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'Excel XLSX',
                'text/csv' => 'CSV',
            ],
            'retentionPolicies' => [
                'standard' => 'Retencion estandar',
                'sensitive' => 'Dato sensible con acceso restringido',
                'health_record' => 'Historia clinica',
                'financial' => 'Financiero/prestamos',
                'legal_document' => 'Documento legal',
                'permanent_audit' => 'Auditoria permanente',
            ],
            'formFlows' => [
                'customer_create' => 'Crear cliente',
                'quote_create' => 'Crear cotizacion',
                'sale_create' => 'Crear venta',
                'loan_approval' => 'Aprobar prestamo',
                'service_order_close' => 'Cerrar orden de servicio',
                'treatment_finish' => 'Finalizar tratamiento',
                'rental_delivery' => 'Entregar alquiler',
                'reservation_confirm' => 'Confirmar reserva',
            ],
            'formSurfaces' => [
                'form' => 'Formulario operativo',
                'wizard_step' => 'Paso de wizard',
                'document' => 'Documento',
                'report' => 'Reporte',
            ],
            'documentTypes' => BusinessProfileConfiguration::options()['documentTypes'],
            'formulaOperators' => BusinessProfileConfiguration::options()['formulaOperators'],
            'formulaResultTypes' => [
                'decimal' => 'Decimal',
                'integer' => 'Entero',
                'currency' => 'Moneda',
                'percentage' => 'Porcentaje',
            ],
            'salesWorkflows' => BusinessProfileConfiguration::options()['salesWorkflows'],
            'posModes' => BusinessProfileConfiguration::options()['posModes'],
            'cashPolicies' => BusinessProfileConfiguration::options()['cashPolicies'],
            'paymentPolicies' => BusinessProfileConfiguration::options()['paymentPolicies'],
            'inventoryTimings' => BusinessProfileConfiguration::options()['inventoryTimings'],
            'commercialDocuments' => BusinessProfileConfiguration::options()['documents'],
            'customerModes' => [
                'hidden' => 'Cliente oculto',
                'optional' => 'Cliente opcional',
                'required' => 'Cliente obligatorio',
            ],
            'reportModules' => [
                'sales' => 'Ventas',
                'inventory' => 'Inventario',
                'cash' => 'Caja',
                'finance' => 'Finanzas',
                'purchases' => 'Compras',
                'services' => 'Servicios',
                'reservations' => 'Reservas',
                'production' => 'Produccion',
                'customers' => 'Clientes',
                'workers' => 'Trabajadores',
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
            'qrContextTypes' => [
                'general' => 'General',
                'restaurant_table' => 'Mesa de restaurante',
                'delivery_zone' => 'Zona de delivery',
                'branch_pickup' => 'Retiro en sucursal',
                'resource' => 'Recurso reservable',
            ],
            'qrServiceModes' => [
                'pickup' => 'Retiro en sucursal',
                'table' => 'Pedido en mesa',
                'delivery' => 'Delivery',
                'reservation' => 'Reserva/cita',
                'quote_request' => 'Solicitud de cotizacion',
            ],
            'qrOrderTypes' => [
                'pickup' => 'Retiro',
                'table' => 'Mesa',
                'delivery' => 'Delivery',
                'reservation' => 'Reserva',
                'quote_request' => 'Cotizacion',
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
            'integrationProviders' => [
                'whatsapp' => 'WhatsApp',
                'email' => 'Correo SMTP/API',
                'siat' => 'SIAT',
                'thermal_printer' => 'Impresora termica',
                'barcode_scanner' => 'Lector barcode',
                'scale' => 'Bascula',
                'camera' => 'Camara/fotos',
                'external_calendar' => 'Calendario externo',
                'payment_gateway' => 'Pasarela QR/banco',
                'maps_routing' => 'Mapas/ruteo',
                'custom_api' => 'API personalizada',
            ],
            'integrationChannels' => [
                'whatsapp' => 'WhatsApp',
                'email' => 'Correo',
                'siat' => 'SIAT',
                'thermal_printers' => 'Impresoras termicas',
                'barcode_scanners' => 'Lectores barcode',
                'scales' => 'Basculas',
                'cameras' => 'Camaras/fotos',
                'external_calendar' => 'Calendario externo',
                'payments' => 'Pagos externos',
                'maps' => 'Mapas y rutas',
                'custom_api' => 'API personalizada',
            ],
            'integrationDirections' => [
                'inbound' => 'Entrada',
                'outbound' => 'Salida',
                'bidirectional' => 'Bidireccional',
            ],
            'integrationAuthTypes' => [
                'none' => 'Sin autenticacion',
                'api_key' => 'API key',
                'bearer_token' => 'Bearer token',
                'basic' => 'Usuario/contrasena',
                'oauth2' => 'OAuth2',
                'signed_webhook' => 'Webhook firmado',
            ],
            'branchPolicyOperations' => [
                'transfer_inventory' => 'Transferir inventario',
                'reserve_stock' => 'Reservar stock',
                'price_by_branch' => 'Precio por sucursal',
                'cash_by_branch' => 'Caja por sucursal',
                'bank_by_branch' => 'Banco por sucursal',
                'document_numbering' => 'Numeracion por sucursal',
                'user_branch_access' => 'Acceso de usuarios por sucursal',
                'report_consolidation' => 'Reporte consolidado',
            ],
            'branchPolicyScopes' => [
                'global' => 'Global',
                'specific_branch' => 'Sucursal especifica',
                'source_destination' => 'Origen/destino',
                'user_access' => 'Acceso de usuario',
            ],
            'branchPolicyEnforcementModes' => [
                'monitor' => 'Solo monitorear',
                'block' => 'Bloquear',
                'approval_required' => 'Requiere aprobacion',
            ],
            'approvalActions' => [
                'high_discount' => 'Descuento alto',
                'void_sale' => 'Anular venta',
                'price_override' => 'Cambiar precio',
                'negative_stock' => 'Stock negativo',
                'sensitive_export' => 'Exportar datos sensibles',
                'cash_close_difference' => 'Cerrar caja con diferencia',
                'loan_approval' => 'Aprobar prestamo',
                'guarantee_return' => 'Devolver garantia',
            ],
            'approvalTriggers' => [
                'before_action' => 'Antes de ejecutar accion',
                'on_state_change' => 'Al cambiar estado',
                'on_amount_threshold' => 'Al superar monto/porcentaje',
                'manual_request' => 'Solicitud manual',
            ],
            'automationTriggers' => [
                'reservation_due' => 'Reserva por vencer',
                'loan_overdue' => 'Prestamo en mora',
                'product_expiring' => 'Producto por vencer',
                'treatment_followup' => 'Control de tratamiento',
                'service_order_ready' => 'Orden lista',
                'cash_not_closed' => 'Caja sin cerrar',
                'stock_low' => 'Stock bajo',
                'payment_due' => 'Pago pendiente',
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
