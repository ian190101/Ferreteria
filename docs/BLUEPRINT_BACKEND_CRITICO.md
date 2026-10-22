# Plano de backend crítico — Fabrica_Calmina_Aroma

Fecha: 21 de octubre de 2026. Destinatario: Sol 6.1. Auditoría estática del árbol de trabajo local sobre HEAD `28c3ea35df5ca52d5adbb58cded8202c8a4d8ca3`.

## Alcance y reglas de ejecución

Se seleccionaron 25 archivos; el inventario completo está en BLUEPRINT_OPTIMIZACION_Y_UI.md. Se consultaron los dos mapas. No se abrió ni cargó `graphify-out/graph.json`. El reporte AST comparte commit con HEAD, pero no demuestra correspondencia con los cambios sin commit. Sus comunidades orientan la selección: los diagnósticos siguientes se sustentan en código local.

Las líneas corresponden a esta captura; aplicar por método y ancla, porque los primeros cambios desplazarán las siguientes líneas. Estos planos no autorizan ejecutar modificaciones en esta sesión. No se ejecutaron Artisan, migraciones, pruebas que escriben en BD, colas ni llamadas SIAT. Los comandos de prueba son instrucciones para la implementación, sobre una BD de pruebas aislada. Un filtro sin pruebas ejecutadas no constituye aprobación.

Orden: CRIT-18 → 01 → 02 → 03 → 04 → 05 → 06 → 07 → 08 → 09 → 10 → 11 → 12 → 13 → 14 → 15 → 16 → 17. Aplicar las tareas conectadas en el mismo cambio y ejecutar sus pruebas antes de pasar a refactorizaciones.

No trasladar `SaleNoteIssued` a `ShouldDispatchAfterCommit`: su listener descuenta stock, registra el cobro y consume reservas dentro de la transacción de venta. Esos efectos deben revertirse juntos. La frontera posterior al commit corresponde a comunicaciones externas y trabajos derivados. Tampoco sustituir escrituras Eloquent por `insert/update` masivos sobre modelos auditados sin conservar sus eventos.

Hallazgos negativos: las rutas examinadas de Sales y Cash tienen `auth`, `verified` y permisos; `StoreSaleDocumentRequest::authorize` comprueba `sales.manage`; el servidor sustituye precios cuando falta `sales.prices.override`. No se confirmó inyección SQL en los fragmentos examinados: los SQL crudos observados son estáticos. `SalePayment` tiene fillable explícito, casts decimal y auditoría; su soft delete no prueba borrado silencioso. Compras ya tiene transacción, bloqueos ordenados, reversos de kardex y outbox: conservarlos.

## Intervenciones

### Cobros, inventario y concurrencia

- **ID de Tarea:** [CRIT-01] — [x] COMPLETADO
- **Archivo Objetivo:** app/Modules/Payments/Http/Controllers/PaymentController.php; app/Modules/Finance/Services/FinancialLedgerService.php.
- **Ubicación Exacta:** PaymentController::store L68-L92; FinancialLedgerService::registerSalePayment L26-L45 y applySalePaymentBalance L48-L55.
- **Categoría y Severidad:** [Concurrencia] - [Crítica].
- **Diagnóstico Técnico Conciso:** El controlador bloquea la venta, pero no revalida monto/estado después del bloqueo. El ledger aplica max(..., 0), ocultando sobrecobros: dos solicitudes previamente validadas pueden cobrar 80 + 80 sobre saldo 100.
- **Ancla de Código Actual:**
~~~php
$sale = Sale::query()->lockForUpdate()->findOrFail($request->integer('sale_id'));
$amount = round((float) $request->input('amount'), 2);
$payment = $ledger->registerSalePayment(
~~~
- **Especificación / Código Objetivo (Diff Quirúrgico):** En store, inmediatamente después de cargar la venta, insertar:
~~~php
abort_unless(BranchAccess::canAccess($request->user(), (int) $sale->branch_id), 403);
if ($sale->document_type !== 'sale_note' || ! in_array($sale->status, ['issued', 'partial_paid'], true)) {
    throw \Illuminate\Validation\ValidationException::withMessages([
        'sale_id' => 'La venta ya no admite cobros.',
    ]);
}
~~~
  En registerSalePayment, después de normalizar amount y antes de create, insertar la condición siguiente. CRIT-12 reemplaza después esta comparación por decimal exacto:
~~~php
if (! is_finite($amount) || $amount <= 0 || $amount > (float) $sale->balance_due) {
    throw \Illuminate\Validation\ValidationException::withMessages([
        'amount' => 'El cobro debe ser positivo y no superar el saldo pendiente.',
    ]);
}
~~~
  El contrato interno del ledger exige que su llamador mantenga la transacción y el bloqueo de Sale hasta registrar banco/inventario. No abrir una transacción pequeña que confirme únicamente el cobro. Añadir al comienzo de registerSalePayment un rechazo con LogicException si $sale->getConnection()->transactionLevel() < 1; texto: «El cobro requiere una transacción del flujo completo». Esto comprueba transacción, no demuestra por sí solo que exista bloqueo. Conservar los bloqueos de los llamadores examinados.
- **Contratos Inmutables (NO ROMPER):** Firmas públicas store y registerSalePayment; RedirectResponse de vuelta con flash success «Pago {id} registrado correctamente.»; argumentos con nombre sale/userId/paymentMethodId/amount/reference/notes; estados issued, partial_paid, paid.
- **Criterio de Verificación:** php artisan test --filter=PaymentsModuleTest. Agregar una prueba con dos conexiones reales, ambas validadas antes del primer commit, cobros 80 y 80/saldo 100: una sola inserción, saldo 20, un movimiento bancario. Venta void o sucursal ajena: ningún pago ni movimiento.

- **ID de Tarea:** [CRIT-02] — [x] COMPLETADO
- **Archivo Objetivo:** app/Modules/Payments/Http/Controllers/PaymentController.php; app/Modules/Sales/Services/SalePaymentService.php.
- **Ubicación Exacta:** PaymentController::store L86-L88; SalePaymentService::registerPosPayment L45-L47; consumidor SaleInventoryService::tracksInventory L337-L339.
- **Categoría y Severidad:** [Seguridad] - [Alta].
- **Diagnóstico Técnico Conciso:** La proyección omite is_inventory_item. tracksInventory interpreta un atributo ausente como true; al cobrar una venta de servicios puede fallar por stock o descontar existencias indebidamente.
- **Ancla de Código Actual:**
~~~php
$sale->loadMissing('items.product:id,inventory_tracking_mode');
~~~
- **Especificación / Código Objetivo (Diff Quirúrgico):** Reemplazar esa línea en ambos archivos por:
~~~php
$sale->load('items.product:id,inventory_tracking_mode,item_type,is_inventory_item,product_category_id');
~~~
  Se usa load, porque loadMissing no completa columnas de una relación ya cargada parcialmente. En ProcessSaleNoteIssued L23 y SaleController L216/L318/L408/L455 añadir product_category_id a las proyecciones ya existentes: es la FK que necesita la política por categoría; no eliminar las demás columnas.
- **Contratos Inmutables (NO ROMPER):** registerPosPayment(Sale $sale, int $userId, int $paymentMethodId, float $amount, ?string $reference = null): void; store; relaciones items/product; ningún cambio al payload de cobro ni a la política que decide cuándo descontar.
- **Criterio de Verificación:** php artisan test --filter=PaymentsModuleTest. Caso añadido: producto is_inventory_item=false, rastreo global, sin stock, descuento al pagar: cobro exitoso, cero InventoryMovement. Repetir con items.product previamente cargado solo con id y inventory_tracking_mode.

- **ID de Tarea:** [CRIT-03] — [x] COMPLETADO
- **Archivo Objetivo:** app/Modules/Production/Http/Controllers/ProductionOrderController.php.
- **Ubicación Exacta:** ProductionOrderController::consumeInput L269-L294; store L94-L110.
- **Categoría y Severidad:** [Concurrencia] - [Crítica].
- **Diagnóstico Técnico Conciso:** Los bloqueos existen, pero se descuenta sin comprobar existencias libres. Una validación anterior a la transacción no evita negativos ni consumo de unidades reservadas; la bobina tampoco se restringe aquí por producto/sucursal.
- **Ancla de Código Actual:**
~~~php
$coil = ProductCoil::query()->lockForUpdate()->findOrFail($coilId);
$before = (float) $coil->available_meters;
$after = round($before - $meters, 3);
~~~
- **Especificación / Código Objetivo (Diff Quirúrgico):** Antes del if de consumeInput insertar:
~~~php
$meters = round($meters, 3);
if (! is_finite($meters) || $meters <= 0) {
    throw \Illuminate\Validation\ValidationException::withMessages([
        'input_meters' => 'El consumo debe ser mayor que cero a precisión de inventario.',
    ]);
}
~~~
  Sustituir la consulta de bobina por:
~~~php
$coil = ProductCoil::query()
    ->where('branch_id', $order->branch_id)
    ->where('product_id', $product->id)
    ->where('status', 'available')
    ->lockForUpdate()
    ->findOrFail($coilId);
$reserved = (float) \App\Modules\Inventory\Models\InventoryReservation::query()
    ->where('product_coil_id', $coil->id)
    ->where('status', \App\Modules\Inventory\Models\InventoryReservation::STATUS_ACTIVE)
    ->sum('meters');
if (round((float) $coil->available_meters - $reserved, 3) < $meters) {
    throw \Illuminate\Validation\ValidationException::withMessages([
        'input_meters' => 'La bobina no tiene cantidad libre suficiente.',
    ]);
}
~~~
  Antes de decrease del stock global insertar:
~~~php
if ($quantities->free($stock) < $meters) {
    throw \Illuminate\Validation\ValidationException::withMessages([
        'input_meters' => 'El inventario libre no alcanza para producir.',
    ]);
}
~~~
  En store L97 normalizar quantity a 3 decimales, no 4: kardex y stock usan 3. Antes de crear order, si existe fórmula y algún items.inputProduct usa TRACKING_COIL, rechazar con campo production_formula_id y mensaje «La fórmula requiere seleccionar bobinas; usa producción manual por bobina». El payload de fórmulas actual no contiene asignación de bobina por insumo; no inventar una selección automática. La coordinación completa con escritores de reservas no fue examinada: exigir después el mismo bloqueo de bobina en reservas (lote 1).
- **Contratos Inmutables (NO ROMPER):** store(StoreProductionOrderRequest $request, InventoryQuantityService $quantities): RedirectResponse; production.index; tipos production_input_coil, production_input_global, production_formula_input_global y production_output_*; campos meters_delta/before/after.
- **Criterio de Verificación:** php artisan test --filter=ProductionModuleTest. Dos producciones de 7 sobre libre 10: una confirma, otra valida con error; stock 3. Reservado 8/disponible 10/consumo 3: rechazo y rollback de order, inputs, outputs, costos y movimientos. Bobina de otra sucursal/producto: rechazo.

- **ID de Tarea:** [CRIT-04] — [x] COMPLETADO
- **Archivo Objetivo:** app/Modules/Sales/Http/Controllers/SaleController.php.
- **Ubicación Exacta:** SaleController::store L185-L193; contraste con convert L317-L325 y StoreSaleDocumentRequest::withValidator L293-L305.
- **Categoría y Severidad:** [Concurrencia] - [Crítica].
- **Diagnóstico Técnico Conciso:** store bloquea sourceQuotation pero no vuelve a comprobar su vigencia. Dos solicitudes que superan el FormRequest pueden crear dos notas desde la misma cotización; convert sí revalida.
- **Ancla de Código Actual:**
~~~php
->lockForUpdate()
->first()
: null;
$sale = Sale::query()->create([
~~~
- **Especificación / Código Objetivo (Diff Quirúrgico):** Insertar después de L191:
~~~php
if ($request->filled('source_quotation_id') && (
    ! $sourceQuotation
    || $sourceQuotation->document_type !== 'quotation'
    || $sourceQuotation->status !== 'quoted'
    || (int) $sourceQuotation->branch_id !== $request->integer('branch_id')
    || $request->input('document_type') !== 'sale_note'
)) {
    throw ValidationException::withMessages([
        'source_quotation_id' => 'La cotización ya no está vigente para esta sucursal.',
    ]);
}
~~~
- **Contratos Inmutables (NO ROMPER):** store(StoreSaleDocumentRequest $request): RedirectResponse; sales.store, sales.show y sales.convert; constructor SaleNoteIssued; campo de error source_quotation_id.
- **Criterio de Verificación:** php artisan test --filter=SalesModuleTest. Ejecutar dos store concurrentes con el mismo source_quotation_id ya validado: exactamente una nota nueva y una conversión. Mantener pruebas de convert y de sucursal.

- **ID de Tarea:** [CRIT-05] — [x] COMPLETADO
- **Archivo Objetivo:** app/Modules/Sales/Listeners/ProcessSaleNoteIssued.php.
- **Ubicación Exacta:** ProcessSaleNoteIssued::handle L31-L47.
- **Categoría y Severidad:** [Transacciones] - [Alta].
- **Diagnóstico Técnico Conciso:** Se descuenta antes de consumir/liberar la reserva de la propia cotización. Una venta de todo el stock reservado puede rechazarse porque decrementGlobalStock/decrementCoilStock comparan contra reservas todavía activas.
- **Ancla de Código Actual:**
~~~php
if ($this->workflow->shouldDiscountInventoryOnSaleNote()) {
    $this->inventory->decrementForSale($sale, $event->userId);
}
if ($event->sourceQuotationId) {
~~~
- **Especificación / Código Objetivo (Diff Quirúrgico):** Mover exactamente el bloque L31-L33 después del cierre del if de sourceQuotationId, L48, y antes del if posPayment. Al inicio de handle insertar:
~~~php
if (\Illuminate\Support\Facades\DB::transactionLevel() < 1) {
    throw new \LogicException('La emisión requiere la transacción de venta.');
}
~~~
  La liberación, cambio de estado de cotización, decremento y cobro permanecen en la misma transacción. Un error de stock revierte también las reservas. No implementar ShouldQueue ni ShouldDispatchAfterCommit en este evento/listener.
- **Contratos Inmutables (NO ROMPER):** handle(SaleNoteIssued $event): void; nombre y cuatro argumentos del evento; estados converted/consumed; orden cobro después de ajustes de reserva e inventario.
- **Criterio de Verificación:** php artisan test --filter=SalesModuleTest. Reserva propia 10/disponible 10/venta 10: éxito, reserva consumed, stock 0. Reserva ajena 4 y propia 6/venta 7: rechazo sin perder ninguna reserva.

- **ID de Tarea:** [CRIT-06] — [x] COMPLETADO
- **Archivo Objetivo:** app/Modules/Cash/Http/Controllers/CashRegisterController.php.
- **Ubicación Exacta:** CashRegisterController::close L90-L104.
- **Categoría y Severidad:** [Concurrencia] - [Alta].
- **Diagnóstico Técnico Conciso:** close bloquea la sesión, pero no revalida status. Dos cierres validados antes del primer commit pueden sobrescribir conteo, diferencia, responsable y fecha del cierre confirmado.
- **Ancla de Código Actual:**
~~~php
->lockForUpdate()
->firstOrFail();
$closedAt = now();
~~~
- **Especificación / Código Objetivo (Diff Quirúrgico):** Insertar después de firstOrFail:
~~~php
if ($cashSession->status !== CashRegisterSession::STATUS_OPEN) {
    throw \Illuminate\Validation\ValidationException::withMessages([
        'cash_session' => 'La caja ya fue cerrada; actualiza la pantalla.',
    ]);
}
~~~
- **Contratos Inmutables (NO ROMPER):** close(CloseCashSessionRequest $request, CashRegisterSession $cashSession, FinancialLedgerService $ledger): RedirectResponse; cash.close/cash.index; estructura cash_count y cash_count_breakdown; primer cierre permanece inalterado.
- **Criterio de Verificación:** php artisan test --filter=CashModuleTest. Dos solicitudes con conteos distintos preparadas antes del cierre: solo la primera actualiza. Verificar closed_at, counted_cash_amount y closing_notes originales.

- **ID de Tarea:** [CRIT-07] — [x] COMPLETADO
- **Archivo Objetivo:** app/Modules/Cash/Http/Controllers/CashRegisterController.php.
- **Ubicación Exacta:** CashRegisterController::open L69-L85.
- **Categoría y Severidad:** [Concurrencia] - [Alta].
- **Diagnóstico Técnico Conciso:** El create no comparte bloqueo con otra apertura; una consulta previa del FormRequest no serializa dos solicitudes. No se inspeccionó la migración original de caja: una restricción existente podría convertir la carrera en error SQL en lugar de duplicado.
- **Ancla de Código Actual:**
~~~php
CashRegisterSession::query()->create([
    ...$request->validated(),
    'opened_by' => $request->user()->id,
~~~
- **Especificación / Código Objetivo (Diff Quirúrgico):** Envolver únicamente L77-L83 en DB::transaction(function () use ($request) { ... }, 3). Insertar al principio del callback:
~~~php
\App\Models\User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
$open = CashRegisterSession::query()
    ->where('branch_id', $request->integer('branch_id'))
    ->where('opened_by', $request->user()->id)
    ->where('status', CashRegisterSession::STATUS_OPEN)
    ->lockForUpdate()->first();
if ($open) {
    throw \Illuminate\Validation\ValidationException::withMessages([
        'branch_id' => 'Ya tienes una caja abierta en esta sucursal.',
    ]);
}
~~~
  Bloquear User proporciona una fila existente incluso cuando no hay ninguna sesión. Conservar las reglas adicionales del FormRequest; no añadir un unique(branch_id, opened_by, status), pues impediría múltiples sesiones históricas cerradas.
- **Contratos Inmutables (NO ROMPER):** open(OpenCashSessionRequest $request): RedirectResponse; cash.open; payload y flash existentes; múltiples sesiones históricas cerradas.
- **Criterio de Verificación:** php artisan test --filter=CashModuleTest. Dos aperturas simultáneas del mismo usuario/sucursal: una sesión abierta; distintos usuarios mantienen la regla de negocio ya vigente en el FormRequest.

- **ID de Tarea:** [CRIT-08] — [x] COMPLETADO
- **Archivo Objetivo:** app/Modules/Cash/Support/CashSessionGuard.php; app/Modules/Sales/Http/Controllers/SaleController.php; app/Modules/Payments/Http/Controllers/PaymentController.php.
- **Ubicación Exacta:** CashSessionGuard::requiresOpenSession L11-L17 y hasOpenSession L20-L26; SaleController::store L118-L119 y convert L316-L317; PaymentController::store L70-L74.
- **Categoría y Severidad:** [Concurrencia] - [Crítica].
- **Diagnóstico Técnico Conciso:** La comprobación exists de caja ocurre antes de escribir y no bloquea el cierre. Un cobro puede entrar después de calcular el cierre, quedando fuera del resumen guardado de una caja que ya estaba cerrada.
- **Ancla de Código Actual:**
~~~php
->where('opened_by', $user->id)
->where('status', CashRegisterSession::STATUS_OPEN)
->exists();
~~~
- **Especificación / Código Objetivo (Diff Quirúrgico):** Añadir método público lockRequiredOpenSession(User $user, int $branchId): void. Su cuerpo será:
~~~php
if (\Illuminate\Support\Facades\DB::transactionLevel() < 1) {
    throw new \LogicException('La validación de caja requiere transacción.');
}
$rules = ActiveBusinessProfile::payload()['cash'] ?? [];
if (! ($rules['required_to_sell'] ?? true) || $user->isSuperAdministrator()) {
    return;
}
$session = CashRegisterSession::query()
    ->where('branch_id', $branchId)
    ->where('opened_by', $user->id)
    ->where('status', CashRegisterSession::STATUS_OPEN)
    ->orderBy('id')->lockForUpdate()->first();
if (! $session) {
    throw \Illuminate\Validation\ValidationException::withMessages([
        'branch_id' => self::message(),
    ]);
}
~~~
  En SaleController::store, al entrar en el callback y solo para document_type=sale_note, llamarlo con request->user() y request->integer('branch_id'). En convert llamarlo con la sucursal de $sale al entrar en el callback. En PaymentController::store después de bloquear/reautorizar $sale llamarlo con su branch_id. Usar nombre cualificado \App\Modules\Cash\Support\CashSessionGuard::lockRequiredOpenSession(...). No reemplazar la validación temprana amigable.
  Este parche cierra los caminos auditados de ventas y cobros. Gastos, pagos a proveedores y bancos deben adoptar la misma coordinación antes de afirmar cierre consistente de TODO el sistema: son puertas de verificación del lote 1, no archivos ya auditados. No añadir bloqueos globales sobre todas las sucursales.
- **Contratos Inmutables (NO ROMPER):** Conservar tres métodos existentes del guard, excepciones de superadministrador/configuración, firmas de store/convert, rutas y respuesta Inertia; método nuevo es aditivo.
- **Criterio de Verificación:** php artisan test --filter=CashModuleTest. Dos conexiones: A bloquea la sesión y registra cobro, B intenta cerrar; B espera y su resumen incluye A. Orden inverso: B cierra primero y A rechaza el cobro. Ejecutar con el motor real de despliegue, no SQLite.

### Facturación y precisión

- **ID de Tarea:** [CRIT-09] — [x] COMPLETADO
- **Archivo Objetivo:** app/Modules/Billing/Services/SiatInvoiceService.php.
- **Ubicación Exacta:** SiatInvoiceService::issueFromSale L35-L139, requestCufd L78, send L142-L186.
- **Categoría y Severidad:** [Transacciones] - [Crítica].
- **Diagnóstico Técnico Conciso:** requestCufd y recepcionFactura se ejecutan con la transacción abierta. Un timeout o fallo local después de aceptación remota puede borrar la evidencia local y permitir emitir otra factura; además mantiene bloqueada la venta durante la red.
- **Ancla de Código Actual:**
~~~php
$cufd ??= app(SiatCodeService::class)->requestCufd((int) $sale->branch_id);
return $this->send($invoice->refresh(), $setting);
~~~
- **Especificación / Código Objetivo (Diff Quirúrgico):** Antes de iniciar la transacción de issueFromSale insertar:
~~~php
if (DB::transactionLevel() !== 0) {
    throw new \LogicException('La emisión fiscal debe iniciarse fuera de otra transacción.');
}
$setting = $this->configuration->settingForBranch((int) $sale->branch_id);
$cufd = $this->configuration->activeCufd((int) $sale->branch_id);
if (! $cufd && ! $temporaryWhenOffline && $this->workflow->autoRequestCufd()) {
    $cufd = app(SiatCodeService::class)->requestCufd((int) $sale->branch_id);
}
~~~
  Cambiar L35 a asignación $invoice = DB::transaction(...); capturar además $setting, $cufd. Dentro del callback eliminar L65-L66 y L78; conservar la decisión temporal L68-L70 y el rechazo L72-L76. Si no hay CUFD después de esas ramas, lanzar ValidationException con billing: «No existe CUFD vigente para emitir». Mantener la validación local de preparación y la generación de XML dentro del bloque local; CRIT-11 especifica el bloqueo de setting.
  Reemplazar L138 por return $invoice->refresh();. Después del cierre de DB::transaction insertar:
~~~php
return $invoice->status === SiatInvoice::STATUS_PENDING
    ? $this->send($invoice, $setting)
    : $invoice;
~~~
  Al comienzo de send y void insertar:
~~~php
if (DB::transactionLevel() !== 0) {
    throw new \LogicException('La comunicación SIAT no debe mantener una transacción abierta.');
}
~~~
  Mantener send síncrono en esta corrección para conservar su contrato de retorno. Un fallo de red deja la factura pending y su CUF/XML persistidos; reintentar esa MISMA factura, nunca llamar issueFromSale para generar otra. No añadir retries automáticos del SOAP ni afirmar exactly-once: una respuesta perdida exige consultar/reconciliar el estado remoto. La cola durable se especifica como fase posterior en lote 2, con contexto sandbox y recuperación explícitos.
- **Contratos Inmutables (NO ROMPER):** issueFromSale(Sale $sale, int $userId, bool $temporaryWhenOffline = false): SiatInvoice; send(SiatInvoice $invoice, ?SiatBranchSetting $setting = null): SiatInvoice; void(SiatInvoice $invoice, int $reasonCode, string $reason): SiatInvoice; CUF, XML, hash, estados existentes y estructura SOAP. Rechazar llamadas anidadas es una precondición explícita nueva; verificar llamadores adicionales antes de desplegar.
- **Criterio de Verificación:** php artisan test --filter=SiatBillingModuleTest. Doble SOAP debe afirmar transactionLevel()===0 al pedir CUFD/recepción/anulación. Si recepción lanza timeout, existe exactamente una factura pending con el XML/hash original. Si falla la validación de XML antes del commit, no se llama recepción. No contactar al SIN real.

- **ID de Tarea:** [CRIT-10] — [x] COMPLETADO
- **Archivo Objetivo:** app/Modules/Billing/Services/SiatInvoiceService.php.
- **Ubicación Exacta:** SiatInvoiceService::void L216-L224.
- **Categoría y Severidad:** [Transacciones] - [Crítica].
- **Diagnóstico Técnico Conciso:** Cualquier respuesta SOAP se convierte en STATUS_VOIDED. El rechazo remoto deja una factura válida en el SIN y anulada localmente.
- **Ancla de Código Actual:**
~~~php
$response = $this->soap->call($setting, 'ServicioFacturacionCompraVenta', 'anulacionFactura', $payload);
$invoice->update([
    'status' => SiatInvoice::STATUS_VOIDED,
~~~
- **Especificación / Código Objetivo (Diff Quirúrgico):** Insertar entre ambas instrucciones:
~~~php
$body = $response['RespuestaServicioFacturacion'] ?? $response['RespuestaServicio'] ?? [];
$state = (int) ($body['codigoEstado'] ?? 0);
if ($state !== 905) {
    $invoice->update(['siat_response' => $response]);
    throw ValidationException::withMessages([
        'billing' => 'El SIAT no confirmó la anulación. La factura conserva su estado.',
    ]);
}
~~~
  Añadir 'siat_state_code' => $state al update exitoso existente. No cambiar status/voided_at/void_reason cuando la respuesta es desconocida o rechazada. El estado 905 corresponde a anulación confirmada en el [catálogo técnico oficial del SIN](https://siatanexo.impuestos.gob.bo/index.php/emision-y-envio-de-facturas/codigos-error-siat); no se deduce del éxito HTTP.
- **Contratos Inmutables (NO ROMPER):** Firma void y estructura SolicitudServicioAnulacionFactura; error de validación billing; metadatos de anulación solo tras confirmación.
- **Criterio de Verificación:** php artisan test --filter=SiatBillingModuleTest. Respuestas 905, 906, 934, cuerpo vacío y excepción de transporte: solo 905 anula; los demás conservan estado y fecha originales. Registrar respuesta rechazada sin sustituir el documento fiscal.

- **ID de Tarea:** [CRIT-11] — [x] COMPLETADO
- **Archivo Objetivo:** app/Modules/Billing/Services/SiatInvoiceService.php.
- **Ubicación Exacta:** SiatInvoiceService::issueFromSale L48-L56/L65-L81; nextInvoiceNumber L289-L297; createTemporaryInvoice L229-L253.
- **Categoría y Severidad:** [Concurrencia] - [Alta].
- **Diagnóstico Técnico Conciso:** La exclusión de duplicados solo contempla pending/validated; temporal/observada/borrador permiten otro documento. MAX con lockForUpdate no proporciona una fila de secuencia estable en sucursal vacía y depende del motor/aislamiento.
- **Ancla de Código Actual:**
~~~php
->whereIn('status', [SiatInvoice::STATUS_PENDING, SiatInvoice::STATUS_VALIDATED])
->selectRaw('MAX(CAST(invoice_number AS UNSIGNED)) as max_number')
~~~
- **Especificación / Código Objetivo (Diff Quirúrgico):** Reemplazar únicamente whereIn por:
~~~php
->where('status', '!=', SiatInvoice::STATUS_VOIDED)
~~~
  Dentro de la transacción de issueFromSale, después de comprobar que no existe factura y antes de cualquier nextInvoiceNumber/createTemporaryInvoice, adquirir:
~~~php
$setting = SiatBranchSetting::query()
    ->whereKey($setting->id)->lockForUpdate()->firstOrFail();
~~~
  Esta fila estable serializa emisores de una sucursal; mantenerla bloqueada hasta crear la factura. Eliminar lockForUpdate de la consulta agregada de nextInvoiceNumber, conservar MAX bajo el bloqueo anterior. La asignación de readiness L79 no elimina el bloqueo adquirido. No reiniciar numeración ni reciclar números de facturas anuladas.
  Antes del despliegue verificar que todo emisor usa este servicio y que cada sucursal tiene una única configuración; ese esquema no está dentro de los 25 archivos. No afirmar ausencia de índices únicos que no se inspeccionaron.
- **Contratos Inmutables (NO ROMPER):** nextInvoiceNumber sigue devolviendo int; invoice_number se persiste como string; reenvío usa send con mismo CUF; createTemporaryInvoice permanece privado; se permite nueva emisión tras VOIDED confirmado.
- **Criterio de Verificación:** php artisan test --filter=SiatBillingModuleTest. Dos ventas en la misma sucursal sin facturas previas: números distintos; repetir contra una venta con factura temporary/observed: no insertar otra. Prueba de concurrencia con el motor real.

- **ID de Tarea:** [CRIT-12] — [x] COMPLETADO
- **Archivo Objetivo:** app/Modules/Sales/Http/Controllers/SaleController.php; app/Modules/Finance/Services/FinancialLedgerService.php; nuevo app/Modules/Finance/Support/Decimal.php.
- **Ubicación Exacta:** SaleController::store L147-L182; FinancialLedgerService::registerSalePayment L26-L55 y registerPurchasePayment/applyPurchasePaymentBalance L81-L98/L168-L177; nuevo Decimal, sin líneas previas.
- **Categoría y Severidad:** [Aritmética] - [Alta].
- **Diagnóstico Técnico Conciso:** Las operaciones usan float pese a casts decimal de persistencia. Los máximos admitidos de cantidad/precio permiten pérdida de precisión y productos fuera de rango; redondear después no recupera dígitos perdidos.
- **Ancla de Código Actual:**
~~~php
$lineGross = (float) $item['meters'] * (float) $item['unit_price'];
$subtotal = round($items->sum(fn ($item) => (float) $item['meters'] * (float) $item['unit_price']), 2);
'amount_bob' => round($amount * (float) $sale->exchange_rate_to_bob, 2),
~~~
- **Especificación / Código Objetivo (Diff Quirúrgico):** Usar bcmath (disponible en PHP CLI local; exigirlo también en web/worker/despliegue). Añadir clase final Decimal en Finance/Support con strict_types=1 y solo método público static round(string $value, int $scale): string. Cuerpo exacto:
~~~php
if ($scale < 0 || $scale > 12 || ! preg_match('/^-?\d+(?:\.\d+)?$/D', $value)) {
    throw new \InvalidArgumentException('Decimal o escala inválidos.');
}
$negative = str_starts_with($value, '-');
$magnitude = ltrim($value, '-');
$half = $scale === 0 ? '0.5' : '0.'.str_repeat('0', $scale).'5';
$result = bcadd($magnitude, $half, $scale);
return $negative && bccomp($result, '0', $scale) !== 0 ? '-'.$result : $result;
~~~
  Importar Decimal en los archivos objetivo. En store, antes de map inicializar acumuladores strings $grossTotal='0', $discountTotalExact='0', $totalExact='0'; capturarlos por referencia en el callback. Sustituir L147-L148:
~~~php
$quantity = Decimal::round((string) $item['meters'], 3);
$unitPrice = Decimal::round((string) $item['unit_price'], 4);
$discountExact = Decimal::round((string) $item['discount_amount'], 2);
$grossExact = bcmul($quantity, $unitPrice, 7);
$lineGross = (float) $grossExact;
$discount = (float) $discountExact;
~~~
  Los dos floats anteriores son adaptadores temporales de CommercialPolicy, cuya implementación no se auditó; no emplearlos para persistir ni sumar. Reemplazar L162-L163:
~~~php
if (bccomp($discountExact, $grossExact, 7) > 0) {
    throw ValidationException::withMessages(['items' => 'El descuento supera el importe del ítem.']);
}
$item['meters'] = $quantity;
$item['unit_price'] = $unitPrice;
$item['discount_amount'] = $discountExact;
$item['total'] = Decimal::round(bcsub($grossExact, $discountExact, 7), 2);
$grossTotal = bcadd($grossTotal, $grossExact, 7);
$discountTotalExact = bcadd($discountTotalExact, $discountExact, 2);
$totalExact = bcadd($totalExact, $item['total'], 2);
~~~
  Sustituir L170-L172 por:
~~~php
$subtotal = Decimal::round($grossTotal, 2);
$discountTotal = $discountTotalExact;
$total = $totalExact;
~~~
  Validar total <= 999999999999.99 mediante bccomp antes del create y lanzar ValidationException en items si lo supera. En la comparación POS L174-L176 usar Decimal::round((string)$request->input('pos_payment_amount'), 2) y bccomp($posPayment, $total, 2) !== 0. No aceptar tolerancia de un centavo para «cobro completo».
  Mantener advanceValues(float...) como adaptador público interno legado; en store sustituir SU LLAMADA por cálculo exacto: mode=none devuelve ['0','0.00']; amount limita con bccomp entre 0 y total, redondea a 2; percentage usa el string percentage de AdvanceOption, bcmul(total, percentage, 8), bcdiv(...,'100',8) y Decimal::round(...,2). No cambiar el significado informativo del anticipo ni descontarlo dos veces.

  Para no romper firmas públicas float del ledger, añadir registerSalePaymentExact y registerPurchasePaymentExact con los mismos argumentos y retorno de sus métodos existentes, cambiando SOLO amount a string. Mover allí el cuerpo de los métodos originales. Los métodos originales quedan como adaptadores que llaman al Exact con number_format($amount, 2, '.', ''). Este adaptador conserva compatibilidad, NO recupera precisión ya perdida; los caminos HTTP auditados deben usar Exact con el string original.
  En Exact: normalizar amount con Decimal::round(...,2), bloquear/refrescar el modelo raíz en la transacción vigente antes de comprobar balance/status; copiar los atributos frescos al mismo objeto mediante setRawAttributes($locked->getAttributes(), true). Usar bccomp para monto>0 y monto<=balance. El cálculo amount_bob pasa a:
~~~php
Decimal::round(bcmul($amount, (string) $sale->exchange_rate_to_bob, 8), 2)
~~~
  Añadir applySalePaymentBalanceExact(Sale $sale, string $amount): void y applyPurchasePaymentBalanceExact(Purchase $purchase, string $amount): void; los métodos públicos float originales delegan con number_format. En las variantes Exact sustituir resta/suma por bcsub/bcadd escala 2 y determinar status con bccomp a cero/total, sin llamar a helpers float. La compra usa paid_amount + amount y total_amount - paid_amount; rechazar si pago supera balance. Los métodos register*Exact llaman a apply*Exact.

  PaymentController::store L72/L74 pasa string amount y llama registerSalePaymentExact. Añadir SalePaymentService::registerPosPaymentExact con amount:string, mismo resto de firma y flujo; registerPosPayment(float...) delega. ProcessSaleNoteIssued L55 llama a Exact y no convierte amount a float; SaleController L226 pone el string normalizado en posPayment. Registrar todas estas sustituciones como parte de esta tarea, no eliminar los adaptadores.
  Los reversos float de voidSalePayment/voidPurchasePayment y resúmenes del ledger permanecen como deuda identificada: en la misma entrega reemplazar sus operaciones L68-L77/L190-L196 por strings bcadd/bcsub y min/max mediante bccomp; mantener suma SQL como string y elegir estados con bccomp. No cambiar notas ni soft delete. No migrar columnas ni reducir escalas del esquema sin inspeccionarlo primero.
- **Contratos Inmutables (NO ROMPER):** Todas las firmas públicas anteriores float y argumentos con nombre siguen disponibles; se añaden variantes Exact. Columnas y casts existentes; no convertir payloads JSON numéricos a strings sin pruebas de frontend. Las cantidades persistidas siguen a escala 3, precio/costo unitario a 4, monto a 2, cambio a 6. CommercialPolicy/anticipo no se rediseñan.
- **Criterio de Verificación:** php -l app/Modules/Finance/Support/Decimal.php; php artisan test --filter=SalesModuleTest; php artisan test --filter=PaymentsModuleTest; php artisan test --filter=PurchasesModuleTest. Pruebas nuevas: round('1.005',2)='1.01'; round('-1.005',2)='-1.01'; precio 0.1000*cantidad 0.100=0.01; total agregado de líneas redondeadas; pago '999999999999.99' conserva centavos; sobrepago 0.01 rechazado; rollback deja saldo/pagos/banco iguales. Ejecutar además todos los llamadores legados float encontrados antes de despliegue.

- **ID de Tarea:** [CRIT-13] — [x] COMPLETADO
- **Archivo Objetivo:** app/Modules/Production/Http/Controllers/ProductionOrderController.php.
- **Ubicación Exacta:** ProductionOrderController::store L69-L75/L95-L108/L125-L133/L166-L170.
- **Categoría y Severidad:** [Aritmética] - [Alta].
- **Diagnóstico Técnico Conciso:** Costo de insumos, mano de obra, indirectos y costo unitario se multiplican y prorratean con float. Además se costea quantity a 4 mientras el stock se registra a 3, generando diferencias entre consumo valorizado y kardex.
- **Ancla de Código Actual:**
~~~php
$lineCost = round($quantity * $unitCost, 4);
$inputCost += $lineCost;
$totalCost = round($inputCost + $laborCost + $overheadCost, 4);
~~~
- **Especificación / Código Objetivo (Diff Quirúrgico):** Depende de Decimal de CRIT-12. Importarlo. Normalizar outputMeters/inputMeters a strings escala 3, laborCost/overheadCost a 4, inputCost='0.0000'. Para fórmula sustituir la división artificial con max(...,0.0001): si bccomp(yield_quantity,'0',4)<=0 lanzar ValidationException en production_formula_id. Calcular cada cantidad directamente, sin redondear un factor intermedio:
~~~php
$quantity = Decimal::round(
    bcdiv(bcmul((string) $item->quantity, $outputMeters, 12), (string) $formula->yield_quantity, 12),
    3
);
$unitCost = Decimal::round((string) ($item->inputProduct->purchase_price ?? '0'), 4);
$lineCost = Decimal::round(bcmul($quantity, $unitCost, 7), 4);
$inputCost = bcadd($inputCost, $lineCost, 4);
~~~
  En consumeInput pasar (float)$quantity únicamente por compatibilidad de la API actual de inventario; persistir quantity y costos como strings. Recalcular inputMeters de la primera línea usando la misma expresión y precisión. En rama manual inputCost = Decimal::round(bcmul($inputMeters, (string)($inputProduct->purchase_price ?? '0'), 7),4); unit_cost usa Decimal::round(...,4). El costo final se sustituye por:
~~~php
$totalCost = bcadd(bcadd($inputCost, $laborCost, 4), $overheadCost, 4);
~~~
  En el update, unit_cost = Decimal::round(bcdiv($totalCost,$outputMeters,12),4), después de rechazar outputMeters<=0 con error output_meters. Usar actual_output_quantity normalizado; conservar planned_output_quantity de fórmula a 4. No redistribuir residuos de redondeo entre insumos: total_cost es suma de costos de línea ya redondeados más labor e indirectos.
- **Contratos Inmutables (NO ROMPER):** store, production.index, input_cost/labor_cost/overhead_cost/total_cost/unit_cost, relaciones inputs/stages y estado completed. No cambiar regla de desperdicio ni adoptar FIFO/promedio ponderado sin otro diseño: el costo fuente sigue siendo purchase_price.
- **Criterio de Verificación:** php artisan test --filter=ProductionModuleTest. Fórmula 0.3333 unidades, rendimiento 3, producción 1: cantidad efectiva 0.111 y costo basado exactamente en ella; total_cost = suma(inputs.total_cost)+labor_cost+overhead_cost. Costo unitario multiplicado puede diferir por redondeo: no sobrescribir total_cost con ese producto.

### Reportes, reversos y trazabilidad

- **ID de Tarea:** [CRIT-14] — [x] COMPLETADO
- **Archivo Objetivo:** app/Modules/CashFlow/Services/CashFlowReportService.php.
- **Ubicación Exacta:** CashFlowReportService::build L27-L40; seis fuentes L121-L295; basePaymentQuery L299-L306; paginate L363-L376.
- **Categoría y Severidad:** [SQL Crítico] - [Alta].
- **Diagnóstico Técnico Conciso:** Se hidratan seis colecciones completas, se fusionan/ordenan y se calcula todo en memoria antes de cortar una página. per_page=25 no limita la consulta ni el uso de memoria; sin fechas se recorre el histórico.
- **Ancla de Código Actual:**
~~~php
$movements = $this->movements($request, $filters);
$filtered = $this->applyCollectionFilters($movements, $filters);
'movements' => $this->paginate($filtered, $filters['per_page'], $request),
~~~
- **Especificación / Código Objetivo (Diff Quirúrgico):** Contención inmediata, antes de la sustitución SQL permanente especificada en OPT-01 del segundo plano: añadir ->limit(10001) al final de basePaymentQuery. En CADA una de las seis fuentes sustituir ->get() por:
~~~php
->get()
->tap(function (Collection $rows): void {
    if ($rows->count() > 10000) {
        throw \Illuminate\Validation\ValidationException::withMessages([
            'from' => 'El rango supera el límite del informe interactivo. Reduce las fechas.',
        ]);
    }
})
~~~
  El tap debe preceder a map. No devolver resúmenes parciales ni truncar silenciosamente. El límite es por fuente: cota total <60001 movimientos si el informe llega a renderizarse. Agregar validación en filters antes de request->date: from/to nullable,date_format:Y-m-d; to after_or_equal:from solo cuando from esté presente; search nullable,string,max:200; per_page integer,min:10,max:100 cuando esté presente.
  Esta contención sacrifica consultas históricas amplias, incluso si search reduciría el resultado después: debe reemplazarse por OPT-01 para cerrar el problema de escalabilidad. No declararla equivalente a paginación SQL. No cambiar a cursorPaginate porque la UI actual recibe LengthAwarePaginator con total y enlaces.
- **Contratos Inmutables (NO ROMPER):** build(Request):array y filters(Request):array; CashFlow/Index; movements/summary/bySource/byBranch/byMethod/filters; ocho claves actuales del summary; filtros de sucursal y exclusión de pagos bancarios automáticos; paginator con data/total/current_page/last_page/links.
- **Criterio de Verificación:** php artisan test --filter=CashFlowModuleTest. Sembrar 10001 pagos de una fuente: error de validación, jamás total=10000. Sembrar 10000: resúmenes completos y página 25. Medir memoria con 10k/100k/1M y demostrar cota de hidratación; el límite no garantiza por sí solo tiempo SQL constante.

- **ID de Tarea:** [CRIT-15] — [x] COMPLETADO
- **Archivo Objetivo:** app/Modules/Finance/Services/FinancialLedgerService.php.
- **Ubicación Exacta:** FinancialLedgerService::voidBankTransaction L239-L258.
- **Categoría y Severidad:** [Concurrencia] - [Alta].
- **Diagnóstico Técnico Conciso:** La guarda consulta el estado del objeto recibido; solo se bloquea la cuenta. Invocaciones con objetos desactualizados pueden revertir dos veces el mismo movimiento aunque current_balance se actualice atómicamente. Los llamadores externos no se auditaron; el servicio no protege su propia precondición.
- **Ancla de Código Actual:**
~~~php
if ($transaction->status === BankTransaction::STATUS_VOID) {
    return;
}
$account = BankAccount::query()
~~~
- **Especificación / Código Objetivo (Diff Quirúrgico):** Envolver L241-L258 en la transacción de la conexión del modelo:
~~~php
$transaction->getConnection()->transaction(function () use ($transaction, $reason): void {
    // Aquí se mantiene el bloque existente, con la relectura indicada abajo.
});
~~~
  Al principio del callback, ANTES de la guarda:
~~~php
$locked = BankTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
$transaction->setRawAttributes($locked->getAttributes(), true);
~~~
  Mantener luego el bloqueo de cuenta. Sustituir el argumento de decrement por:
~~~php
$transaction->type === BankTransaction::TYPE_WITHDRAWAL
    ? bcsub('0', (string) $transaction->amount, 2)
    : (string) $transaction->amount
~~~
  Reutilizar la misma conexión del entorno activo; no cambiar a una conexión central al ejecutar en sandbox. La transacción anidada preserva el rollback de voidPurchase; conservar bumpFinancialCaches y su outbox existente.
- **Contratos Inmutables (NO ROMPER):** voidBankTransaction(BankTransaction $transaction, string $reason): void; signedBankAmount permanece disponible; status VOID, void_reason truncado a 255, voided_at. Un segundo reverso es no-op.
- **Criterio de Verificación:** php artisan test --filter=BanksModuleTest; php artisan test --filter=PurchaseVoidConsistencyTest. Dos objetos del mismo movimiento cargados antes del primer reverso: el saldo cambia una sola vez. Excepción después del decrement y antes del update: ambos efectos revierten.

- **ID de Tarea:** [CRIT-16] — [x] COMPLETADO
- **Archivo Objetivo:** app/Modules/Inventory/Models/InventoryMovement.php; config/audit.php.
- **Ubicación Exacta:** InventoryMovement L8-L16; audit.php L65-L70/L187-L203; contraste con AuditableModel L9-L11.
- **Categoría y Severidad:** [Seguridad] - [Alta].
- **Diagnóstico Técnico Conciso:** El kardex extiende Model, no AuditableModel, y no impide update/delete. Es una carencia de defensa de integridad; no se encontró en la muestra un endpoint que modifique asientos ya emitidos. Además la auditoría de consola está desactivada.
- **Ancla de Código Actual:**
~~~php
use Illuminate\Database\Eloquent\Model;
class InventoryMovement extends Model
'console' => false,
~~~
- **Especificación / Código Objetivo (Diff Quirúrgico):** Sustituir import y herencia por:
~~~php
use App\Modules\Shared\Models\AuditableModel;
class InventoryMovement extends AuditableModel
~~~
  Añadir protected static function booted(): void con el siguiente bloque:
~~~php
static::updating(static function (self $movement): void {
    throw new \LogicException('El kardex es inmutable; registra un movimiento compensatorio.');
});
static::deleting(static function (self $movement): void {
    throw new \LogicException('No se puede eliminar un movimiento de kardex.');
});
~~~
  En config/audit.php sustituir console por true y mantener events, strict, threshold=0, driver database, connection=null y queue.enable=false. No mover la auditoría a una cola separada: preservar registro en la transacción vigente. No incluir importes nuevos ni campos ajenos en fillable.
  Los eventos de modelo no protegen DB::table(), actualizaciones masivas, SQL directo ni administradores de BD. No presentar este parche como log inmutable frente a esas vías. Para garantía en BD se requiere una revisión de permisos y del flujo de purga/restore no comprendida aquí; no bloquear o alterar esos procedimientos sin sus pruebas. En ejecución normal las correcciones se hacen con otro InventoryMovement y su source/reason, como ya hacen los reversos auditados.
- **Contratos Inmutables (NO ROMPER):** Tabla y timestamps=false, fillable, casts, appends quantity_*, relaciones branch/product/coil/user/source. La creación sigue permitida; los caminos normales de venta/compra/producción conservan sus tipos.
- **Criterio de Verificación:** php artisan test --filter=InventoryMovementsModuleTest; php artisan test --filter=AuditModuleTest; php artisan test --filter=PurchaseVoidConsistencyTest. Crear movimiento genera auditoría en HTTP y consola; intentar save de un cambio/delete produce excepción y conserva fila; rollback revierte movimiento y audit. Validar explícitamente mantenimiento en su BD aislada antes de desplegar.

- **ID de Tarea:** [CRIT-17] — [x] COMPLETADO
- **Archivo Objetivo:** app/Modules/CashFlow/Services/CashFlowReportService.php.
- **Ubicación Exacta:** CashFlowReportService::legacySaleCollections L175-L206; contraste FinancialLedgerService::voidSalePayment L68-L73.
- **Categoría y Severidad:** [Aritmética] - [Alta].
- **Diagnóstico Técnico Conciso:** total-balance_due no demuestra cobro: las notas de crédito también reducen el importe exigible. Cuando no hay pagos activos, esa diferencia puede aparecer como entrada ficticia en caja; doesntHave además excluye pagos anulados por soft delete.
- **Ancla de Código Actual:**
~~~php
->doesntHave('payments')
$amount = max(round(((float) $sale->total - (float) $sale->balance_due) * (float) $sale->exchange_rate_to_bob, 2), 0);
~~~
- **Especificación / Código Objetivo (Diff Quirúrgico):** En legacySaleCollections cambiar la ausencia de pagos a:
~~~php
->whereDoesntHave('payments', fn (Builder $payments) => $payments->withTrashed())
~~~
  Añadir antes de with la subconsulta escalar, conservando scopes del modelo CreditNote:
~~~php
->addSelect(['cash_flow_credits' => \App\Modules\Payments\Models\CreditNote::query()
    ->selectRaw('COALESCE(SUM(amount), 0)')
    ->whereColumn('sale_id', 'sales.id')])
~~~
  Para que addSelect no elimine las columnas de Sale, anteponer ->select('sales.*') al addSelect. Reemplazar el cálculo amount en map por:
~~~php
$collected = bcsub(
    bcsub((string) $sale->total, (string) $sale->balance_due, 2),
    (string) ($sale->cash_flow_credits ?? '0'),
    2
);
$amount = bccomp($collected, '0', 2) > 0
    ? (float) \App\Modules\Finance\Support\Decimal::round(
        bcmul($collected, (string) $sale->exchange_rate_to_bob, 8), 2
    )
    : 0.0;
~~~
  Es una inferencia histórica explícita, no un asiento probatorio. No migrar datos antiguos ni crear pagos a partir de esta fórmula. En legacyPurchasePayments aplicar también whereDoesntHave('payments', ...withTrashed()) para no «resucitar» pagos borrados; su fórmula sigue paid_amount. Mantener estas reglas en OPT-01.
- **Contratos Inmutables (NO ROMPER):** Prefijos legacy-sale-/legacy-purchase-, etiquetas «heredado», notes explicando inferencia, moneda de reporte actual y claves de salida. Nunca convertir una nota de crédito en movimiento de efectivo.
- **Criterio de Verificación:** php artisan test --filter=CashFlowModuleTest. Venta 100, saldo 60, crédito activo 40, sin pagos: ingreso heredado 0; venta 100, saldo 30, crédito 20: ingreso inferido 50 al cambio aplicable. Pago soft-deleted: no generar ingreso heredado. Sin crédito y sin historial de pago: conservar comportamiento legado.

### Validación estructural antes de consultar

- **ID de Tarea:** [CRIT-18] — [x] COMPLETADO
- **Archivo Objetivo:** app/Modules/Sales/Http/Requests/StoreSaleDocumentRequest.php.
- **Ubicación Exacta:** StoreSaleDocumentRequest::prepareForValidation L30-L70; rules L135-L151; withValidator L197-L200.
- **Categoría y Severidad:** [Seguridad] - [Alta].
- **Diagnóstico Técnico Conciso:** prepareForValidation se ejecuta antes de rules y su map exige array; items=[1] produce TypeError antes de generar errores de formulario. No existe cota de ítems; el input puede amplificar consultas, validaciones y memoria.
- **Ancla de Código Actual:**
~~~php
$products = Product::query()
$items = collect($this->input('items', []))
    ->map(function (array $item) use ($products): array {
~~~
- **Especificación / Código Objetivo (Diff Quirúrgico):** Al comenzar prepareForValidation, antes de consultar productos, insertar una validación SOLO de la forma que necesita la normalización:
~~~php
\Illuminate\Support\Facades\Validator::make($this->all(), [
    'items' => ['required', 'array', 'min:1', 'max:500'],
    'items.*' => ['array'],
    'items.*.product_id' => ['required', 'integer'],
    'items.*.calculation_mode' => ['nullable', 'string'],
    'items.*.display_unit_label' => ['nullable', 'string'],
    'items.*.unit_label' => ['nullable', 'string'],
    'items.*.meters' => ['nullable', 'numeric'],
    'items.*.display_quantity' => ['nullable', 'numeric'],
    'items.*.base_quantity' => ['nullable', 'numeric'],
    'items.*.length' => ['nullable', 'numeric'],
])->validate();
~~~
  En rules cambiar items por ['required','array','min:1','max:500'] y añadir 'items.*' => ['array']. Al comienzo del callback after de withValidator insertar:
~~~php
if ($validator->errors()->isNotEmpty()) {
    return;
}
~~~
  CRIT-12 añade además comprobación de decimal plano antes de bcmath. No ejecutar validaciones de negocio duplicadas en prepareForValidation ni exigir meters antes de calcularlo. El máximo 500 es una decisión de protección del flujo interactivo: documentos mayores deben dividirse o ir por un proceso de importación diseñado aparte; no truncar ítems ni descartarlos.
- **Contratos Inmutables (NO ROMPER):** authorize/rules/messages/attributes; claves items y items.N.campo, tres modos de cálculo, unidades y campos actualmente aceptados. La nueva cota es explícita; las solicitudes válidas de hasta 500 ítems mantienen su forma HTTP.
- **Criterio de Verificación:** php artisan test --filter=SalesModuleTest. Enviar items escalar, [1], [{product_id:1,unit_label:[]}], 501 ítems y una cantidad no numérica: error de validación, cero ventas/pagos/stock, nunca TypeError/500. Un ítem length sin meters pero con display_quantity y length válidos sigue normalizándose.


## Condiciones de cierre del plano

1. Las correcciones de la muestra no certifican los otros módulos ni los archivos de formulario no seleccionados.
2. La exactitud decimal requiere que la normalización no convierta antes a float. Como parte de CRIT-12, en StoreSaleDocumentRequest::prepareForValidation L43-L63 reemplazar los productos/redondeos de cantidad por bcmul + Decimal::round a 3; conservar los mismos tres modos. Añadir unitFactorToBaseExact(?Product $product, ?string $unitSymbol): string con el mismo filtro L75-L82, pero devolver (string)$conversion->factor_to_base o '1'. El método float anterior puede delegar para compatibilidad interna. Validar formato decimal plano en los valores usados antes de llamar bcmath y responder ValidationException; no dejar que texto o exponente produzcan 500. Para campos JSON numéricos ya convertidos por PHP a float, mantener compatibilidad dentro del rango representable y documentar la limitación: en UI enviar importes como strings decimales; no prometer precisión perdida en el transporte.
3. En config/audit.php no se inspeccionó la clase Audit ni todos sus consumidores; activar console no demuestra almacenamiento inviolable. Asimismo, los índices examinados no son un inventario completo del esquema desplegado.
4. La política blockSaleIfInvoiceFails se invoca DESPUÉS del commit comercial en SaleController L236/L419/L435-L439. Por tanto hoy una excepción fiscal no revierte la venta. Mantener el documento confirmado y mostrar su identidad al usuario; el trabajo UI-02 del segundo plano evita invitar a recrearlo. Una garantía de «venta solo si SIN acepta» necesita una máquina de estados y conciliación externa; no simularla envolviendo SOAP en una transacción.
5. Laravel documenta la frontera posterior al commit para eventos/colas: [API de eventos 12.x](https://api.laravel.com/docs/12.x/Illuminate/Events/Dispatcher.html) y [conexión/transacciones 12.x](https://api.laravel.com/docs/12.x/Illuminate/Database/Connection.html). Aquí esa frontera no sustituye una transacción distribuida.
