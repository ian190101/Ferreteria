<?php

namespace App\Modules\SystemSuperadmin\Services;

use App\Models\User;
use App\Modules\Settings\Services\MaintenanceBackupService;
use App\Modules\Settings\Services\ProductionLogService;
use App\Support\OperationalCacheOutbox;
use App\Support\OperationalWriteGate;
use App\Support\SystemRoles;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

class DatabasePurgeService
{
    public function __construct(
        private readonly MaintenanceBackupService $backupService,
        private readonly ProductionLogService $logService,
    ) {}

    /**
     * Definicion completa de modulos y sus tablas asociadas.
     * El orden de las tablas en cada modulo es de hijos a padres para respetar integridad relacional.
     *
     * @return array<string, array{
     *     id: string,
     *     name: string,
     *     description: string,
     *     group: string,
     *     is_operational: bool,
     *     tables: array<int, string>
     * }>
     */
    public function modulesCatalog(): array
    {
        return [
            'sales' => [
                'id' => 'sales',
                'name' => 'Ventas, Facturación y Despachos',
                'description' => 'Notas de venta, cotizaciones, ítems, pagos de clientes, facturas SIAT, despachos, devoluciones y notas de crédito.',
                'group' => 'operacion',
                'is_operational' => true,
                'tables' => [
                    'siat_invoice_items',
                    'siat_invoices',
                    'siat_package_invoice',
                    'siat_packages',
                    'siat_logs',
                    'invoices',
                    'credit_note_items',
                    'credit_notes',
                    'sale_return_items',
                    'sale_returns',
                    'delivery_note_items',
                    'delivery_notes',
                    'delivery_drivers',
                    'delivery_trucks',
                    'sale_payments',
                    'sale_items',
                    'customer_qr_order_items',
                    'customer_qr_orders',
                    'sales',
                ],
            ],
            'purchases' => [
                'id' => 'purchases',
                'name' => 'Compras y Órdenes de Compra',
                'description' => 'Compras ingresadas, ítems de compra, recepciones físicas, órdenes de compra y pagos a proveedores.',
                'group' => 'operacion',
                'is_operational' => true,
                'tables' => [
                    'purchase_order_receipt_items',
                    'purchase_order_receipts',
                    'purchase_order_items',
                    'purchase_orders',
                    'purchase_payments',
                    'purchase_items',
                    'purchases',
                ],
            ],
            'inventory' => [
                'id' => 'inventory',
                'name' => 'Inventario, Bobinas y Kardex',
                'description' => 'Movimientos del Kardex, bobinas y unidades físicas, transferencias entre sucursales, reservas y ajustes de stock.',
                'group' => 'operacion',
                'is_operational' => true,
                'tables' => [
                    'inventory_movements',
                    'inventory_transfer_items',
                    'inventory_transfers',
                    'inventory_reservations',
                    'inventory_adjustments',
                    'product_coils',
                    'product_branch_stocks',
                ],
            ],
            'cash_finance' => [
                'id' => 'cash_finance',
                'name' => 'Cajas, Bancos y Gastos',
                'description' => 'Sesiones de caja, arqueos y cortes de turno, egresos/gastos, transacciones bancarias y conciliaciones.',
                'group' => 'finanzas',
                'is_operational' => true,
                'tables' => [
                    'cash_denominations',
                    'cash_register_sessions',
                    'bank_reconciliations',
                    'bank_transactions',
                    'payment_promises',
                    'expenses',
                ],
            ],
            'production' => [
                'id' => 'production',
                'name' => 'Producción Industrial',
                'description' => 'Órdenes de producción, consumos de insumos, fases y recetas de fabricación.',
                'group' => 'operacion',
                'is_operational' => true,
                'tables' => [
                    'production_order_inputs',
                    'production_order_stages',
                    'production_orders',
                    'production_formula_items',
                    'production_formula_stages',
                    'production_formulas',
                ],
            ],
            'restaurant' => [
                'id' => 'restaurant',
                'name' => 'Restaurante y Comandas',
                'description' => 'Comandas de cocina, ítems pedidos, recetas de cocina y mesas de atención.',
                'group' => 'operacion',
                'is_operational' => true,
                'tables' => [
                    'kitchen_order_items',
                    'kitchen_orders',
                    'recipe_items',
                    'recipes',
                ],
            ],
            'services' => [
                'id' => 'services',
                'name' => 'Servicios, Reservas y Alquileres',
                'description' => 'Órdenes de servicio técnico, reservas de espacios o recursos y registros de alquiler.',
                'group' => 'operacion',
                'is_operational' => true,
                'tables' => [
                    'service_order_items',
                    'service_orders',
                    'business_reservation_items',
                    'business_reservations',
                ],
            ],
            'hr' => [
                'id' => 'hr',
                'name' => 'Recursos Humanos y Planillas',
                'description' => 'Pagos de salarios, anticipos, registros de asistencia y fichas de personal/trabajadores.',
                'group' => 'operacion',
                'is_operational' => true,
                'tables' => [
                    'salary_payments',
                    'worker_advances',
                    'worker_attendances',
                    'workers',
                ],
            ],
            'customers' => [
                'id' => 'customers',
                'name' => 'Clientes y CRM',
                'description' => 'Fichas de clientes, historial de interacciones, tipos de clientes y límites de crédito.',
                'group' => 'catalogos',
                'is_operational' => false,
                'tables' => [
                    'customer_interactions',
                    'customer_qr_channels',
                    'customer_types',
                    'customers',
                ],
            ],
            'suppliers' => [
                'id' => 'suppliers',
                'name' => 'Directorio de Proveedores',
                'description' => 'Fichas de proveedores y datos de contacto comercial.',
                'group' => 'catalogos',
                'is_operational' => false,
                'tables' => [
                    'suppliers',
                ],
            ],
            'products_catalog' => [
                'id' => 'products_catalog',
                'name' => 'Catálogo de Productos y Precios',
                'description' => 'Productos, categorías, calibres/espesores, listas de precios, unidades y plantillas de código de barra.',
                'group' => 'catalogos',
                'is_operational' => false,
                'tables' => [
                    'product_price_rules',
                    'price_list_items',
                    'price_lists',
                    'product_unit_conversions',
                    'product_category_attributes',
                    'product_categories',
                    'product_images',
                    'product_variants',
                    'thicknesses',
                    'barcode_label_templates',
                    'receipt_templates',
                    'products',
                ],
            ],
            'audits_logs' => [
                'id' => 'audits_logs',
                'name' => 'Auditoría, Trazabilidad y Logs',
                'description' => 'Historial de cambios en BD (audits), trazas operativas, logs de mantenimiento y conversaciones del Asistente IA.',
                'group' => 'auditoria',
                'is_operational' => true,
                'tables' => [
                    'audits',
                    'operational_traces',
                    'maintenance_logs',
                    'ai_assistant_api_logs',
                    'ai_assistant_tool_runs',
                    'ai_assistant_messages',
                    'ai_assistant_conversations',
                    'transport_route_caches',
                    'print_jobs',
                    'alerts',
                ],
            ],
        ];
    }

    /**
     * Tablas protegidas del sistema que NO se deben purgar de manera accidental.
     *
     * @return array<int, string>
     */
    public function protectedSystemTables(): array
    {
        return [
            'users',
            'roles',
            'permissions',
            'model_has_roles',
            'model_has_permissions',
            'role_has_permissions',
            'branches',
            'branch_settings',
            'branch_user',
            'business_profiles',
            'business_profile_presets',
            'business_profile_drafts',
            'business_profile_versions',
            'business_capabilities',
            'system_settings',
            'currencies',
            'payment_methods',
            'product_units',
            'migrations',
            'maintenance_barriers',
            'maintenance_backups',
            'maintenance_logs',
            'audits',
            'operational_traces',
            'document_sequences',
            'maintenance_purge_runs',
            'maintenance_deployment_runs',
            'cache_invalidation_outbox',
        ];
    }

    /**
     * Retorna estadísticas y conteo en tiempo real de cada tabla y módulo.
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        $modules = $this->modulesCatalog();
        $tableStats = [];
        $moduleStats = [];
        $totalRows = 0;
        $totalSoftDeleted = 0;
        $operationalRows = 0;

        foreach ($modules as $moduleId => $module) {
            $mRows = 0;
            $mSoftDeleted = 0;
            $mTables = [];

            foreach ($module['tables'] as $table) {
                if (in_array($table, $this->protectedSystemTables(), true)) {
                    continue;
                }
                if (! Schema::hasTable($table)) {
                    continue;
                }

                try {
                    $rowCount = DB::table($table)->count();
                } catch (Throwable) {
                    $rowCount = 0;
                }

                $hasSoftDelete = Schema::hasColumn($table, 'deleted_at');
                $softCount = 0;

                if ($hasSoftDelete && $rowCount > 0) {
                    try {
                        $softCount = DB::table($table)->whereNotNull('deleted_at')->count();
                    } catch (Throwable) {
                        $softCount = 0;
                    }
                }

                $stat = [
                    'table' => $table,
                    'row_count' => $rowCount,
                    'soft_deleted_count' => $softCount,
                    'has_soft_delete' => $hasSoftDelete,
                ];

                $tableStats[$table] = $stat;
                $mTables[] = $stat;
                $mRows += $rowCount;
                $mSoftDeleted += $softCount;

                if ($module['is_operational']) {
                    $operationalRows += $rowCount;
                }
            }

            $moduleStats[$moduleId] = [
                'id' => $module['id'],
                'name' => $module['name'],
                'description' => $module['description'],
                'group' => $module['group'],
                'is_operational' => $module['is_operational'],
                'total_rows' => $mRows,
                'soft_deleted_rows' => $mSoftDeleted,
                'tables' => $mTables,
            ];

            $totalRows += $mRows;
            $totalSoftDeleted += $mSoftDeleted;
        }

        return [
            'modules' => $moduleStats,
            'table_stats' => $tableStats,
            'summary' => [
                'total_rows' => $totalRows,
                'total_soft_deleted' => $totalSoftDeleted,
                'operational_rows' => $operationalRows,
                'total_tables' => count($tableStats),
                'database_driver' => DB::connection()->getDriverName(),
                'database_name' => config('database.connections.'.DB::connection()->getName().'.database'),
            ],
        ];
    }

    public function preview(array $options, User $user): array
    {
        $this->authorize($user);
        $options = $this->normalizedOptions($options);
        [$tables, $plan] = $this->resolvePlan($options);
        $numbering = app(PurgeNumberingPolicy::class)->inspect($options, $tables);
        $plan['blockers'] = [...$plan['blockers'], ...$numbering['blockers']];
        if (! config('maintenance.purge_enabled')) {
            $plan['blockers'][] = 'La purga permanece deshabilitada hasta aprobar las verificaciones del entorno.';
        }
        $token = Crypt::encryptString(json_encode([
            'user_id' => $user->id, 'issued_at' => time(), 'options' => $options, 'database' => DB::connection()->getDatabaseName(),
            'fingerprint' => $plan['fingerprint'],
            'numbering_hash' => hash('sha256', json_encode($numbering, JSON_THROW_ON_ERROR)),
            'allowed' => $plan['blockers'] === [],
        ], JSON_THROW_ON_ERROR));

        return [...$plan, 'tables' => $tables, 'protected' => $this->protectedSystemTables(),
            'preview_token' => $token, 'expires_in' => 900,
            'backup' => ['required' => app()->isProduction() || $options['create_backup'], 'status' => 'pendiente de crear y verificar'],
            'sequences' => $numbering['sequences'], 'auto_increment_tables' => $numbering['auto_increment_tables'],
            'can_execute' => $plan['blockers'] === []];
    }

    public function validatePreview(array $options, User $user): array
    {
        $this->authorize($user);
        abort_unless(config('maintenance.purge_enabled'), 423, 'La purga todavía no está habilitada en este entorno.');
        try {
            $preview = json_decode(Crypt::decryptString((string) ($options['preview_token'] ?? '')), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw ValidationException::withMessages(['preview_token' => 'Obtén una vista previa válida antes de ejecutar la purga.']);
        }
        $normalized = $this->normalizedOptions($options);
        if (($preview['user_id'] ?? null) !== $user->id || ($preview['issued_at'] ?? 0) < time() - 900
            || ($preview['options'] ?? null) !== $normalized || ($preview['database'] ?? null) !== DB::connection()->getDatabaseName()) {
            throw ValidationException::withMessages(['preview_token' => 'El alcance cambió o la vista previa expiró. Vuelve a revisarla.']);
        }
        if (! ($preview['allowed'] ?? false)) {
            throw ValidationException::withMessages(['tables' => 'La vista previa contiene dependencias o bloqueos pendientes. Resuélvelos y vuelve a calcularla.']);
        }

        return [$preview, $normalized];
    }

    public function executePurge(array $options, User $user, ?\Closure $progress = null): array
    {
        [$preview, $normalized] = $this->validatePreview($options, $user);

        return app(OperationalWriteGate::class)->exclusive(function () use ($normalized, $preview, $user, $progress): array {
            [$tables, $plan] = $this->resolvePlan($normalized);
            $planner = app(PurgeScopePlanner::class);
            $numberingPolicy = app(PurgeNumberingPolicy::class);
            $numbering = $numberingPolicy->inspect($normalized, $tables);
            $plan['blockers'] = [...$plan['blockers'], ...$numbering['blockers']];
            if ($plan['blockers'] !== []) {
                throw ValidationException::withMessages(['tables' => $plan['blockers']]);
            }
            if (! hash_equals($preview['fingerprint'], $plan['fingerprint'])) {
                throw ValidationException::withMessages(['preview_token' => 'Los datos cambiaron desde la vista previa. Revisa nuevamente el alcance.']);
            }
            if (! hash_equals($preview['numbering_hash'] ?? '', hash('sha256', json_encode($numbering, JSON_THROW_ON_ERROR)))) {
                throw ValidationException::withMessages(['preview_token' => 'Los correlativos cambiaron desde la vista previa. Vuelve a revisarlos.']);
            }

            $backupId = null;
            if ($normalized['create_backup'] || app()->isProduction()) {
                $progress?->__invoke('backing_up');
                $backup = $this->backupService->generate('sql', $user, ['reason' => 'pre_purge', 'scope' => $normalized['scope']]);
                $verification = $this->backupService->verifyReadable($backup);
                if (! $verification['ok']) {
                    throw ValidationException::withMessages(['backup' => 'El respaldo previo no pudo verificarse. No se eliminó ningún registro.']);
                }
                $backupId = $backup->id;
                $progress?->__invoke('verifying_recovery', ['backup_id' => $backupId]);
                $this->backupService->verifyRecovery($backup);
            }

            try {
                $progress?->__invoke('deleting');
                $result = DB::transaction(function () use ($normalized, $plan, $planner, $user, $backupId, $numberingPolicy, $numbering, $progress): array {
                    // La barrera exclusiva ya detuvo las escrituras HTTP, colas y tareas operativas.
                    foreach ($plan['order'] as $table) {
                        if ($plan['counts'][$table] === 0) {
                            continue;
                        }
                        $query = $planner->candidates($table, $normalized['only_soft_deleted'])->orderByRaw('1')->lockForUpdate();
                        $query->chunk(500, static function (): void {});
                    }
                    // Solo se cortan enlaces anulables de registros que van a eliminarse en esta transacción.
                    foreach ($plan['nullable_breaks'] as $edge) {
                        $planner->candidates($edge['child'], $normalized['only_soft_deleted'])
                            ->update(array_fill_keys($edge['columns'], null));
                    }
                    $deleted = [];
                    foreach ($plan['order'] as $table) {
                        $deleted[$table] = $plan['counts'][$table] === 0 ? 0 : $planner->candidates($table, $normalized['only_soft_deleted'])->delete();
                    }
                    $total = array_sum($deleted);
                    $numberingPolicy->applySequences($numbering['sequences']);
                    $audit = $this->logService->record('maintenance', 'warning', 'database_purged',
                        "Purga ejecutada por {$user->name}: {$total} registros eliminados.",
                        ['user_id' => $user->id, 'scope' => $normalized['scope'], 'tables' => $deleted, 'backup_id' => $backupId]);
                    if (! $audit->exists) {
                        throw new \RuntimeException('No se pudo conservar la auditoría de la purga.');
                    }
                    app(OperationalCacheOutbox::class)->schedule();

                    $result = ['ok' => true, 'message' => "Se eliminaron {$total} registros de forma definitiva.",
                        'deleted_counts' => $deleted, 'total_deleted' => $total, 'backup_id' => $backupId];
                    $progress?->__invoke('committed', $result);

                    return $result;
                });
            } catch (Throwable $exception) {
                $this->logService->record('maintenance', 'error', 'database_purge_failed',
                    'La purga falló y se revirtió su transacción.',
                    ['user_id' => $user->id, 'backup_id' => $backupId, 'exception' => $exception::class]);
                throw $exception;
            }
            $result['identifier_reset'] = $numberingPolicy->resetIdentifiers($numbering['auto_increment_tables']);
            if (! $result['identifier_reset']['ok']) {
                $result['message'] .= ' El borrado se confirmó, pero el reinicio de identificadores no se completó. Revisa el registro de mantenimiento.';
                $this->logService->record('maintenance', 'warning', 'identifier_reset_failed', $result['message'], $result['identifier_reset']);
            }

            return $result;
        });
    }

    private function authorize(User $user): void
    {
        abort_unless($user->hasRole(SystemRoles::SYSTEM_SUPERADMIN), 403);
    }

    private function normalizedOptions(array $options): array
    {
        $scope = (string) ($options['scope'] ?? 'selected');
        if (! in_array($scope, ['selected', 'all_operational', 'full_reset'], true)) {
            throw ValidationException::withMessages(['scope' => 'El alcance de purga no es válido.']);
        }
        $modules = array_values(array_unique($options['modules'] ?? []));
        $tables = array_values(array_unique($options['tables'] ?? []));
        sort($modules);
        sort($tables);
        $trash = (bool) ($options['only_soft_deleted'] ?? false);
        $sequenceIds = array_values(array_unique(array_map('intval', $options['sequence_ids'] ?? [])));
        sort($sequenceIds);

        return ['scope' => $scope, 'modules' => $modules, 'tables' => $tables,
            'only_soft_deleted' => $trash,
            'include_dependencies' => (bool) ($options['include_dependencies'] ?? false),
            'create_backup' => (bool) ($options['create_backup'] ?? true),
            'reset_sequences' => ! $trash && (bool) ($options['reset_sequences'] ?? false),
            'reset_auto_increment' => ! $trash && (bool) ($options['reset_auto_increment'] ?? false), 'sequence_ids' => $sequenceIds];
    }

    private function resolvePlan(array $options): array
    {
        $tables = $this->resolveTablesToPurge($options['scope'], $options);
        $requested = $tables;
        $allowed = array_values(array_diff(
            array_unique(array_merge(...array_column($this->modulesCatalog(), 'tables'))),
            $this->protectedSystemTables(),
        ));
        $planner = app(PurgeScopePlanner::class);

        do {
            $plan = $planner->calculate($tables, $options['only_soft_deleted']);
            $additional = $options['include_dependencies']
                ? array_values(array_diff(array_intersect(array_column($plan['dependencies'], 'table'), $allowed), $tables))
                : [];
            if ($additional === []) {
                break;
            }
            // Cada ampliación puede descubrir nietos; las tablas protegidas nunca entran en el alcance.
            $tables = [...$tables, ...$additional];
            sort($tables);
        } while (true);

        $plan['added_dependency_tables'] = array_values(array_diff($tables, $requested));

        return [$tables, $plan];
    }

    private function resolveTablesToPurge(string $scope, array $options): array
    {
        $catalog = $this->modulesCatalog();
        $protected = $this->protectedSystemTables();
        $allowed = array_values(array_unique(array_diff(array_merge(...array_column($catalog, 'tables')), $protected)));
        $tables = [];
        if ($scope !== 'selected') {
            foreach ($catalog as $module) {
                if ($scope === 'full_reset' || $module['is_operational']) {
                    $tables = [...$tables, ...$module['tables']];
                }
            }
        } else {
            foreach ($options['modules'] as $module) {
                if (! isset($catalog[$module])) {
                    throw ValidationException::withMessages(['modules' => 'El módulo seleccionado no pertenece al catálogo permitido.']);
                }
                $tables = [...$tables, ...$catalog[$module]['tables']];
            }
            foreach ($options['tables'] as $table) {
                if (! in_array($table, $allowed, true)) {
                    throw ValidationException::withMessages(['tables' => 'La tabla solicitada está protegida o no pertenece al catálogo permitido.']);
                }
                $tables[] = $table;
            }
        }
        $tables = array_values(array_filter(array_unique(array_intersect($tables, $allowed)), fn ($table) => Schema::hasTable($table)));
        sort($tables);
        if ($tables === []) {
            throw ValidationException::withMessages(['tables' => 'Selecciona al menos una tabla permitida.']);
        }

        return $tables;
    }
}
