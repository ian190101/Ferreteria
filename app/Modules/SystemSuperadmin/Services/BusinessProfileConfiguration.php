<?php

namespace App\Modules\SystemSuperadmin\Services;

class BusinessProfileConfiguration
{
    public static function defaults(): array
    {
        return [
            'modules' => [
                'quotes' => true,
                'alerts' => true,
                'sales_notes' => true,
                'pos' => false,
                'purchases' => true,
                'quick_purchases' => false,
                'cash' => true,
                'banks' => true,
                'billing' => false,
                'inventory' => true,
                'deliveries' => true,
                'customers' => true,
                'suppliers' => true,
                'expenses' => true,
                'returns' => true,
                'payment_promises' => true,
                'reports' => true,
                'exports' => true,
                'production' => false,
                'barcode_labels' => true,
                'workers' => true,
                'payroll' => true,
                'reservations' => true,
                'transfers' => true,
                'offline_pos' => false,
            ],
            'sales' => [
                'workflow' => 'quotation_to_sale_note',
                'quotation_mode' => 'required',
                'document_main' => 'sale_note',
                'quotation_label' => 'Cotizacion',
                'sale_note_label' => 'Nota de venta',
                'ticket_label' => 'Ticket POS',
                'default_terms' => 'NOTA: NO SE ACEPTAN CAMBIOS NI DEVOLUCIONES.',
                'terms_by_document' => [
                    'quotation' => 'Cotizacion valida solo por el periodo acordado.',
                    'sale_note' => 'NOTA: NO SE ACEPTAN CAMBIOS NI DEVOLUCIONES.',
                    'ticket' => 'Gracias por su compra.',
                ],
                'customer_mode' => 'required',
                'customer_required' => true,
                'allow_occasional_customer' => true,
                'allow_price_override' => 'permission',
                'allow_negative_stock' => false,
                'negative_stock_policy' => 'never',
                'negative_stock_roles' => [],
                'negative_stock_categories' => [],
                'price_policy' => 'base_price',
                'discount_policy' => 'permission',
                'max_discount_percent' => 0,
                'discount_roles' => [],
                'credit_limit_policy' => 'disabled',
                'default_credit_limit' => 0,
                'inventory_discount_timing' => 'sale_note',
                'visible_columns' => ['description', 'model', 'quantity', 'unit', 'base', 'price', 'subtotal'],
                'allowed_payment_methods' => ['cash', 'qr', 'transfer'],
                'payment_methods_by_flow' => [
                    'sales' => ['cash', 'qr', 'transfer'],
                    'pos' => ['cash', 'qr'],
                    'collections' => ['cash', 'qr', 'transfer'],
                ],
            ],
            'purchases' => [
                'workflow' => 'standard_purchase',
                'barcode_entry' => false,
                'allow_create_product' => true,
                'supplier_mode' => 'optional',
                'register_expense_when_paid' => true,
            ],
            'deliveries' => [
                'mode' => 'optional',
                'driver_required' => false,
                'truck_required' => false,
            ],
            'banks' => [
                'reconciliation_mode' => 'automatic',
                'require_branch_account' => true,
            ],
            'billing' => [
                'enabled' => false,
                'mode' => 'computerized_online',
                'document_sector' => 'compra_venta',
                'invoice_flow' => 'sale_note_then_invoice',
                'issue_from' => 'sale_note',
                'issue_timing' => 'manual',
                'offline_behavior' => 'temporary_receipt',
                'require_customer_tax_data' => true,
                'auto_request_cufd' => true,
                'daily_catalog_sync' => true,
                'allow_temporary_receipt' => true,
                'require_product_mapping' => true,
                'block_sale_if_invoice_fails' => true,
            ],
            'pos' => [
                'scanner_mode' => 'optional',
                'cart_merge_rule' => 'same_product_and_unit',
                'offline_mode' => 'disabled',
                'payment_flow' => 'single_or_mixed',
                'customer_prompt' => 'optional',
            ],
            'products' => [
                'catalog_mode' => 'mixed_inventory',
                'barcode_required' => false,
                'barcode_labels' => true,
                'unit_equivalences' => true,
                'allow_service_items' => false,
                'creation_context' => 'inventory_and_purchase',
            ],
            'cash' => [
                'required_to_sell' => true,
                'scope' => 'user_branch',
                'bank_reconciliation' => true,
                'allow_offline_cash_sales' => false,
            ],
            'inventory' => [
                'always_by_branch' => true,
                'lot_tracking_optional' => true,
                'unit_conversions' => true,
            ],
            'ux' => [
                'context_help' => true,
                'spanish_messages' => true,
                'responsive_tables' => true,
                'demo_mode' => true,
            ],
            'human_resources' => [
                'workers_mode' => 'optional',
                'payroll_enabled' => true,
                'salary_expense_integration' => true,
            ],
        ];
    }

    public static function normalized(array $configuration): array
    {
        return self::replaceRecursive(self::defaults(), $configuration);
    }

    private static function replaceRecursive(array $defaults, array $configuration): array
    {
        foreach ($configuration as $key => $value) {
            if (is_array($value) && isset($defaults[$key]) && is_array($defaults[$key]) && ! array_is_list($value)) {
                $defaults[$key] = self::replaceRecursive($defaults[$key], $value);

                continue;
            }

            $defaults[$key] = $value;
        }

        return $defaults;
    }

    public static function options(): array
    {
        return [
            'businessTypes' => [
                'hardware_store' => 'Ferreteria',
                'store' => 'Tienda',
                'bookstore' => 'Libreria',
                'stationery' => 'Papeleria',
                'supermarket' => 'Supermercado',
                'distributor' => 'Distribuidora',
                'warehouse' => 'Almacen',
                'factory' => 'Fabrica o produccion',
                'services' => 'Servicios',
                'mixed' => 'Mixto o personalizado',
            ],
            'salesWorkflows' => [
                'quotation_to_sale_note' => 'Cotizacion obligatoria y luego nota de venta',
                'optional_quotation' => 'Cotizacion opcional y venta directa',
                'direct_sale' => 'Venta directa',
                'pos' => 'POS rapido con lector de barras',
                'service_sale' => 'Venta de servicios',
            ],
            'quotationModes' => [
                'required' => 'Obligatoria',
                'optional' => 'Opcional',
                'disabled' => 'No usa cotizacion',
            ],
            'documents' => [
                'sale_note' => 'Nota de venta',
                'ticket' => 'Ticket POS',
                'receipt' => 'Recibo interno',
                'invoice_later' => 'Factura en modulo fiscal separado',
                'invoice_direct' => 'Factura directa',
            ],
            'billingFlows' => [
                'quote_sale_note_invoice' => 'Cotizacion -> nota de venta -> factura',
                'sale_note_then_invoice' => 'Nota de venta -> factura',
                'direct_invoice' => 'Venta directa con factura inmediata',
                'choose_per_sale' => 'Escoger en cada venta si se factura',
                'billing_disabled' => 'Sin facturacion fiscal',
            ],
            'billingIssueTimings' => [
                'manual' => 'Manual con boton Emitir factura',
                'automatic_on_sale_note' => 'Automatico al crear nota de venta',
                'automatic_after_quote_conversion' => 'Automatico al convertir cotizacion',
                'automatic_direct' => 'Automatico en venta directa',
            ],
            'saleColumns' => [
                'description' => 'Descripcion',
                'model' => 'Modelo',
                'color' => 'Color',
                'width' => 'Ancho',
                'length' => 'Largo',
                'quantity' => 'Cantidad',
                'unit' => 'Unidad',
                'base' => 'Base calculada',
                'price' => 'Precio',
                'subtotal' => 'Subtotal',
            ],
            'paymentMethodCodes' => [
                'cash' => 'Efectivo',
                'qr' => 'QR',
                'transfer' => 'Transferencia',
                'card' => 'Tarjeta',
                'credit' => 'Credito',
            ],
            'negativeStockPolicies' => [
                'never' => 'Nunca permitir stock negativo',
                'global' => 'Permitir segun regla general',
                'role' => 'Permitir solo a roles autorizados',
                'category' => 'Permitir solo en categorias autorizadas',
            ],
            'pricePolicies' => [
                'base_price' => 'Precio base del producto',
                'branch_price' => 'Lista de precios por sucursal',
                'customer_price' => 'Lista de precios por cliente',
                'mixed' => 'Cliente, sucursal y precio base',
            ],
            'discountPolicies' => [
                'never' => 'No permitir descuentos',
                'permission' => 'Solo con permiso',
                'role_limit' => 'Limite por rol',
                'always_with_limit' => 'Todos con limite configurado',
            ],
            'creditLimitPolicies' => [
                'disabled' => 'Sin control de limite de credito',
                'warn' => 'Advertir si supera el limite',
                'block' => 'Bloquear si supera el limite',
            ],
            'inventoryTimings' => [
                'sale_note' => 'Al generar nota de venta',
                'payment' => 'Al cobrar',
                'delivery' => 'Al despachar',
                'manual' => 'Manual',
            ],
            'purchaseWorkflows' => [
                'standard_purchase' => 'Compra tradicional',
                'barcode_purchase' => 'Compra rapida por codigo de barras',
                'order_to_purchase' => 'Orden de compra y recepcion',
            ],
            'entityModes' => [
                'hidden' => 'Oculto',
                'optional' => 'Opcional',
                'required' => 'Obligatorio',
            ],
            'deliveryModes' => [
                'disabled' => 'Sin despachos',
                'optional' => 'Despacho opcional',
                'required' => 'Despacho obligatorio',
            ],
            'bankReconciliationModes' => [
                'disabled' => 'Sin conciliacion bancaria',
                'manual' => 'Registrar pendiente de conciliacion',
                'automatic' => 'Conciliar automaticamente',
            ],
            'scannerModes' => [
                'disabled' => 'No usa lector de barras',
                'optional' => 'Lector opcional',
                'required' => 'Lector obligatorio para POS',
            ],
            'offlineModes' => [
                'disabled' => 'Sin ventas offline',
                'cash_only' => 'Offline solo efectivo',
                'local_queue' => 'Cola local para sincronizar',
            ],
            'catalogModes' => [
                'mixed_inventory' => 'Productos mixtos con inventario',
                'barcode_retail' => 'Retail por codigo de barras',
                'services' => 'Servicios sin stock obligatorio',
                'warehouse' => 'Almacen y distribucion',
            ],
            'productCreationContexts' => [
                'inventory_only' => 'Solo desde inventario',
                'inventory_and_purchase' => 'Inventario y compras',
                'restricted' => 'Solo usuarios autorizados',
            ],
            'cashScopes' => [
                'user_branch' => 'Caja por usuario y sucursal',
                'branch' => 'Caja por sucursal',
                'pos_terminal' => 'Caja por punto de venta',
            ],
        ];
    }
}
