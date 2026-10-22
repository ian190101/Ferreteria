# Plano de errores funcionales y no funcionales — Fabrica_Calmina_Aroma

Fecha: 22 de octubre de 2026. Destinatario: Sol 6.1. Auditoría estática del árbol local sobre HEAD `28c3ea35df5ca52d5adbb58cded8202c8a4d8ca3`, incluidos los cambios previos sin commit. Los números de línea corresponden a esta captura; aplicar por clase, método y ancla cuando los primeros cambios desplacen líneas.

## Alcance, exclusiones y lotes

Se inspeccionaron **20 archivos de entrada: tres documentos de orientación y 17 fuentes**. No se abrió `graphify-out/graph.json`. Se excluyeron los problemas [CRIT-01] a [CRIT-18], todos marcados `[x] COMPLETADO`; no se propone repetir esas tareas. La única escritura de esta auditoría es este documento. No se ejecutaron PHPUnit, Artisan, migraciones, colas ni llamadas a SIAT.

Los mapas orientaron la selección hacia despachos/devoluciones (comunidades 37, 102 y 136), producción y existencias, cobros/créditos/caja, reversos de compras y transporte de paquetes fiscales (comunidades 84, 154 y 155). La evidencia de cada hallazgo procede del código actual, no de asumir que el grafo refleja los cambios locales.

| N.º | Archivo inspeccionado | Propósito |
|---|---|---|
| 1 | `docs/BLUEPRINT_BACKEND_CRITICO.md` | Exclusión del trabajo completado y conservación de sus garantías. |
| 2 | `docs/arbol-nodos.md` | Navegación entre módulos y localización de pruebas. |
| 3 | `graphify-out/GRAPH_REPORT.md` | Selección de cruces entre componentes. |
| 4 | `app/Modules/Sales/routes/web.php` | Rutas, permisos y contratos de despachos/devoluciones. |
| 5 | `app/Modules/Sales/Http/Controllers/SaleReturnController.php` | Devolución → existencias e importe disponible para crédito. |
| 6 | `app/Modules/Sales/Http/Requests/StoreSaleReturnRequest.php` | Validación agrupada previa a la transacción. |
| 7 | `app/Modules/Sales/Http/Controllers/DeliveryNoteController.php` | Despacho → cantidades base y estados de venta. |
| 8 | `app/Modules/Sales/Http/Requests/StoreDeliveryNoteRequest.php` | Contrato de cantidades comerciales/base. |
| 9 | `app/Modules/Production/Http/Controllers/ProductionOrderController.php` | Orden → etapas, bobinas, insumos y kardex. |
| 10 | `app/Modules/Production/Http/Requests/StoreProductionOrderRequest.php` | Condiciones de selección de fórmula/bobina. |
| 11 | `app/Modules/Production/Resources/Pages/Production/Index.jsx` | Estado del formulario y selección residual de bobina. |
| 12 | `app/Modules/Billing/Services/SiatSoapClient.php` | Transporte, respuestas simuladas, errores y timeout. |
| 13 | `app/Modules/Billing/Services/SiatPackageService.php` | Recepción de paquetes → estados de facturas. |
| 14 | `app/Modules/Billing/Models/SiatInvoice.php` | Estados y persistencia fiscal. |
| 15 | `app/Modules/Finance/Services/FinancialLedgerService.php` | Cobros, reversos y resumen de caja. |
| 16 | `app/Modules/Payments/Http/Controllers/CreditNotes/CreditNoteController.php` | Crédito → saldo y listado. |
| 17 | `app/Modules/Payments/Http/Requests/CreditNotes/StoreCreditNoteRequest.php` | Permisos, sucursal y límites del crédito. |
| 18 | `app/Modules/Cash/Http/Controllers/CashRegisterController.php` | Cierre protegido y consulta de sesiones. |
| 19 | `app/Modules/Purchases/Services/PurchaseService.php` | Reverso coordinado de compras, pagos e inventario. |
| 20 | `app/Modules/Purchases/Http/Requests/StorePurchaseRequest.php` | Normalización anterior a la validación y costo de entrada. |

Lotes de implementación: **A:** FUNC-01 y FUNC-02; **B:** FUNC-03 y FUNC-04; **C:** FUNC-05, FUNC-06 y NFR-03; **D:** NFR-01 y NFR-02. Ejecutar las verificaciones externas de cada lote antes del siguiente. Añadir los casos descritos a las suites indicadas: un filtro que no encuentre pruebas no constituye validación.

Preservar transacciones, bloqueos, auditoría Eloquent, outbox y aritmética decimal ya incorporados. Los fragmentos no reemplazan cabeceras ni eliminan `declare(strict_types=1)` donde exista; no introducir conversiones implícitas incompatibles con tipado estricto. Los cambios siguientes son instrucciones de implementación, **no modificaciones aplicadas al código**.

Control de entrega: los reemplazos se aplicaron únicamente a cadenas en memoria; sus anclas fueron únicas y los nueve archivos PHP resultantes pasaron `php -l` por entrada estándar, sin escribirlos. Se verificaron los nueve campos de la plantilla en los nueve hallazgos y la conservación de las huellas SHA-256 de las 17 fuentes. Esto comprueba aplicabilidad y sintaxis, no sustituye las pruebas funcionales externas pendientes.

No se confirmó IDOR en las rutas de Sales examinadas: existen autenticación, permisos y controles de sucursal. `SiatSoapClient` ya declara timeout HTTP de 20 segundos; no se diagnostica su ausencia. La muestra no permite certificar permisos de todas las rutas del ERP, límites efectivos de toda la infraestructura ni ausencia de otros defectos.

## PARTE 1: ERRORES FUNCIONALES

### Despachos con unidades comerciales

- **ID de Tarea:** [FUNC-01] [x] COMPLETADO
- **Severidad e Impacto:** Alta — La validación puede rechazar despachos válidos porque interpreta cantidades comerciales como cantidades base; el controlador sí utiliza la conversión correcta.
- **Archivo Objetivo:** `app/Modules/Sales/Http/Requests/StoreDeliveryNoteRequest.php`.
- **Ubicación Exacta:** `StoreDeliveryNoteRequest::withValidator`, L98-L102; consumidor `quantityToBase`, L180-L187. Contraste: `DeliveryNoteController::store`, L180-L184 y L204-L207.
- **Escenario de Fallo (Cómo se reproduce):** Vender un ítem con `meters=10` y `display_quantity=100`; solicitar despacho de `quantity=20`, sin `meters` explícito.
  Deben despacharse 2 unidades base, pero el request compara 20 contra las 10 disponibles y rechaza.
- **Ancla de Código Actual:**

```php
            $saleItems = SaleItem::query()
                ->where('sale_id', $sale->id)
                ->whereIn('id', $itemIds)
                ->get(['id', 'meters'])
                ->keyBy('id');
```

- **Especificación / Código Objetivo (Diff Quirúrgico):** Sustituir exclusivamente la proyección de L101:

```diff
-                ->get(['id', 'meters'])
+                ->get(['id', 'meters', 'display_quantity'])
```

- **Contratos Inmutables (NO ROMPER):** `sales.deliveries.store`; `items.*.quantity` y `items.*.meters`; prioridad de `meters` explícito; firmas públicas y bloqueos del controlador.
- **Criterio de Verificación:** `php -l app/Modules/Sales/Http/Requests/StoreDeliveryNoteRequest.php`. Consola externa: `php artisan test --filter=DeliveryNotesModuleTest`. Añadir caso 10 base/100 comerciales, envío de 20 → despacho y movimiento de 2 base; pendiente base de 1 → rechazo; envío con `meters` explícito conserva prioridad y resultado.

### Sobredevolución por líneas repetidas

- **ID de Tarea:** [FUNC-02] [x] COMPLETADO
- **Severidad e Impacto:** Alta — Dos solicitudes previamente validadas pueden devolver más de lo vendido, inflando stock y el importe de devolución utilizable por notas de crédito.
- **Archivo Objetivo:** `app/Modules/Sales/Http/Controllers/SaleReturnController.php`.
- **Ubicación Exacta:** `SaleReturnController::store`, L105-L137; inserción después de L135. El FormRequest agrupa cantidades en L49-L88, antes de adquirir los bloqueos del controlador.
- **Escenario de Fallo (Cómo se reproduce):** Sobre venta de 10, validar A con dos filas del mismo ítem, de 4 cada una; confirmar B de 4 antes de que A entre en su transacción.
  A lee 4 ya devueltas, compara cada fila contra el mismo saldo 6 y confirma otras 8: total devuelto 12.
- **Ancla de Código Actual:**

```php
                $this->restoreInventory($sale, $saleReturn, $saleItem, $saleReturnItem, $request->user()->id, $quantities);
                $totalAmount += $total;
            }
```

- **Especificación / Código Objetivo (Diff Quirúrgico):** Acumular dentro del bucle las cantidades ya restituidas por esta solicitud:

```diff
                 $this->restoreInventory($sale, $saleReturn, $saleItem, $saleReturnItem, $request->user()->id, $quantities);
+                $returnedByItem[$saleItem->id] = round(
+                    (float) ($returnedByItem[$saleItem->id] ?? 0) + $meters,
+                    3
+                );
                 $totalAmount += $total;
```

- **Contratos Inmutables (NO ROMPER):** `sales.returns.store`, `sales.returns.index`; firma pública `store`; error `items`; filas repetidas admitidas cuando su suma cabe; transacción y bloqueo de venta/ítems, tipos de movimientos y restitución existentes.
- **Criterio de Verificación:** `php -l app/Modules/Sales/Http/Controllers/SaleReturnController.php`. Consola externa: `php artisan test --filter=SalesReturnsModuleTest`. Preparar A y B antes del primer commit; confirmar B y ejecutar A ya validada. A debe revertir cabecera, líneas y movimientos; solo permanece la devolución B de 4. Sin B, ambas filas de A suman 8 y confirman. Usar conexiones independientes sobre BD de pruebas para la intercalación.

### Trazabilidad de bobina residual

- **ID de Tarea:** [FUNC-03] [x] COMPLETADO
- **Severidad e Impacto:** Media — Una producción que consume stock global puede quedar vinculada a una bobina de otro insumo que nunca consumió, falseando la trazabilidad del lote.
- **Archivo Objetivo:** `app/Modules/Production/Http/Controllers/ProductionOrderController.php`.
- **Ubicación Exacta:** `ProductionOrderController::store`, L91-L96 y L139-L145. Contrato cruzado: `StoreProductionOrderRequest` L27 y L75-L118; frontend `Index.jsx` L76-L86 y L110-L115.
- **Escenario de Fallo (Cómo se reproduce):** Seleccionar insumo por bobina y una bobina; cambiar luego a insumo global o fórmula global.
  El formulario conserva `input_product_coil_id`; el request acepta su existencia y el controlador lo guarda aunque el consumo sea global.
- **Ancla de Código Actual:**

```php
            $order = ProductionOrder::query()->create([
                ...$request->safe()->except(['output_coil_barcode', 'output_lot_number']),
                'user_id' => $request->user()->id,
                'production_formula_id' => $formula?->id,
                'input_product_id' => $inputProduct->id,
```

- **Especificación / Código Objetivo (Diff Quirúrgico):** Insertar inmediatamente antes de L91:

```php
            $inputCoilId = ! $formula && $inputProduct->inventory_tracking_mode === Product::TRACKING_COIL
                ? ($request->integer('input_product_coil_id') ?: null)
                : null;
```

Aplicar estos reemplazos locales y la inserción contigua a `input_product_id`:

```diff
-                ...$request->safe()->except(['output_coil_barcode', 'output_lot_number']),
+                ...$request->safe()->except(['output_coil_barcode', 'output_lot_number', 'input_product_coil_id']),
                 'user_id' => $request->user()->id,
                 'production_formula_id' => $formula?->id,
                 'input_product_id' => $inputProduct->id,
+                'input_product_coil_id' => $inputCoilId,
```

```diff
-                $this->consumeInput($order, $inputProduct, $request->integer('input_product_coil_id') ?: null, (float) $inputMeters, $request->user()->id, $quantities);
+                $this->consumeInput($order, $inputProduct, $inputCoilId, (float) $inputMeters, $request->user()->id, $quantities);
```

```diff
-                    'product_coil_id' => $request->integer('input_product_coil_id') ?: null,
+                    'product_coil_id' => $inputCoilId,
```

- **Contratos Inmutables (NO ROMPER):** Firma `store`; nombres del payload; producción manual por bobina conserva su ID; rechazo previo de fórmulas con bobinas, aritmética decimal, bloqueos y comprobaciones de stock de CRIT-03/13.
- **Criterio de Verificación:** `php -l app/Modules/Production/Http/Controllers/ProductionOrderController.php`. Consola externa: `php artisan test --filter=ProductionModuleTest`. Insumo global con ID residual existente: bobina nula en orden e input, movimiento global, bobina original intacta. Fórmula global: orden con bobina nula. Consumo manual por bobina: mantiene ID y movimiento correctos.

### Reapertura de etapas contabilizadas

- **ID de Tarea:** [FUNC-04] [x] COMPLETADO
- **Severidad e Impacto:** Alta — Una orden terminada, con consumo y salida contabilizados, puede mostrar etapas pendientes sin reversar inventario ni el estado final de la orden.
- **Archivo Objetivo:** `app/Modules/Production/Http/Controllers/ProductionOrderController.php`.
- **Ubicación Exacta:** `ProductionOrderController::updateStage`, L258-L277; evidencia de contabilización y cierre en `store`, L104, L128-L137 y L153-L178.
- **Escenario de Fallo (Cómo se reproduce):** Registrar una orden terminada con fórmula y etapas; enviar al actualizador de una etapa `status=pending`.
  Actualmente borra su fecha de terminación y confirma el cambio aunque orden, insumos y producto terminado ya estén contabilizados.
- **Ancla de Código Actual:**

```php
        $stage->update([
            ...$data,
            'completed_at' => $data['status'] === ProductionOrderStage::STATUS_COMPLETED ? now() : null,
            'started_at' => $stage->started_at ?? now(),
        ]);
```

- **Especificación / Código Objetivo (Diff Quirúrgico):** Reemplazar exclusivamente L273-L277 por el siguiente bloque. Permitir corregir minutos/notas conservando `completed`; no redefinir la contabilización inmediata del módulo.

```php
        DB::transaction(function () use ($order, $stage, $data): void {
            $lockedOrder = ProductionOrder::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedOrder->status === ProductionOrder::STATUS_COMPLETED
                && $data['status'] !== ProductionOrderStage::STATUS_COMPLETED) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'status' => 'No se puede reabrir una etapa de una producción terminada.',
                ]);
            }

            $lockedStage = ProductionOrderStage::query()
                ->where('production_order_id', $lockedOrder->id)
                ->whereKey($stage->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedStage->update([
                ...$data,
                'completed_at' => $data['status'] === ProductionOrderStage::STATUS_COMPLETED
                    ? ($lockedStage->completed_at ?? now())
                    : null,
                'started_at' => $lockedStage->started_at ?? now(),
            ]);
        });
```

- **Contratos Inmutables (NO ROMPER):** Firma `updateStage`, controles de pertenencia/sucursal L260-L261; campos `status`, `actual_minutes`, `notes`; redirección, caché y flash existentes tras éxito. Conservar edición administrativa de minutos/notas sin alterar fechas históricas. Una reapertura real exigiría un flujo explícito de reverso, fuera de esta tarea.
- **Criterio de Verificación:** `php -l app/Modules/Production/Http/Controllers/ProductionOrderController.php`. Consola externa: `php artisan test --filter=ProductionModuleTest`. Orden completada + `pending` → error `status` sin cambios de fechas, stock ni kardex. Misma orden + `completed` y nuevas notas/minutos → éxito conservando `completed_at`. Etapa ajena → 404 como antes.

### Recepción de paquetes rechazada

- **ID de Tarea:** [FUNC-05] [x] COMPLETADO
- **Severidad e Impacto:** Alta — Una respuesta de rechazo fiscal deja el paquete marcado como enviado; sin código de recepción, la operación posterior de validación no puede continuar.
- **Archivo Objetivo:** `app/Modules/Billing/Services/SiatPackageService.php`.
- **Ubicación Exacta:** `SiatPackageService::buildAndSend`, L87-L95; requisito de recepción en `validateReception`, L106-L109.
- **Escenario de Fallo (Cómo se reproduce):** Enviar un paquete y recibir `transaccion=false`, o una respuesta sin `codigoRecepcion`.
  El servicio registra `sent` y `sent_at` sin comprobar aceptación; posteriormente no puede validar un paquete sin código.
- **Ancla de Código Actual:**

```php
        $response = $this->soap->call($setting, 'ServicioFacturacionCompraVenta', 'recepcionPaqueteFactura', $payload);
        $body = $response['RespuestaServicioFacturacion'] ?? $response['RespuestaServicio'] ?? [];

        $package->update([
```

- **Especificación / Código Objetivo (Diff Quirúrgico):** Insertar después de L88, antes de actualizar a `sent`:

```php
        $accepted = filter_var($body['transaccion'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $receptionCode = $body['codigoRecepcion'] ?? null;

        if (! $accepted || ! is_scalar($receptionCode) || blank((string) $receptionCode)) {
            $package->update(['siat_response' => $response]);

            throw ValidationException::withMessages([
                'billing' => 'SIAT no confirmó la recepción del paquete. Revisa la respuesta registrada antes de reenviar.',
            ]);
        }
```

Mantener la actualización existente para respuestas aceptadas. Conservar `pending` y la respuesta recibida cuando no exista confirmación; no añadir reenvíos automáticos ni interpretar un resultado incierto como permiso para duplicar el envío. Este cambio no implementa una conciliación histórica ni reemplaza la idempotencia de facturas individuales de CRIT-11.

- **Contratos Inmutables (NO ROMPER):** `buildAndSend(SiatSignificantEvent $event, Collection $invoices): SiatPackage`; payload `SolicitudServicioRecepcionPaquete`, campo de error `billing`, constantes y almacenamiento de evidencia existentes. No envolver la red en una nueva transacción.
- **Criterio de Verificación:** `php -l app/Modules/Billing/Services/SiatPackageService.php`. Consola externa: `php artisan test --filter=SiatBillingModuleTest`. Simular rechazo, respuesta vacía y aceptación sin código: error, paquete pendiente, respuesta conservada y `sent_at` nulo. Aceptación con código: comportamiento actual. Sustituir el cliente SOAP por un doble; no llamar al SIN.

### Validación fiscal que sobrescribe estados terminales

- **ID de Tarea:** [FUNC-06] [x] COMPLETADO
- **Severidad e Impacto:** Alta — Una consulta tardía degrada un paquete validado a enviado; una nueva respuesta 908 puede reactivar localmente facturas anuladas. Además, el cambio de paquete y facturas no es atómico.
- **Archivo Objetivo:** `app/Modules/Billing/Services/SiatPackageService.php`.
- **Ubicación Exacta:** `SiatPackageService::validateReception`, L129-L142.
- **Escenario de Fallo (Cómo se reproduce):** Validar el paquete y repetir la consulta con respuesta distinta de 908: se borra `validated_at` y se vuelve a `sent`.
  Si una factura del paquete fue anulada, otra respuesta 908 la cambia indiscriminadamente de `voided` a `validated`.
- **Ancla de Código Actual:**

```php
        $package->update([
            'status' => $state === 908 ? SiatPackage::STATUS_VALIDATED : SiatPackage::STATUS_SENT,
            'siat_response' => $response,
            'validated_at' => $state === 908 ? now() : null,
        ]);
```

- **Especificación / Código Objetivo (Diff Quirúrgico):** Sustituir L132-L142, desde la actualización anclada hasta `return $package->refresh();`, por:

```php
        return \Illuminate\Support\Facades\DB::transaction(
            function () use ($package, $response, $state, $body): SiatPackage {
                $locked = SiatPackage::query()
                    ->lockForUpdate()
                    ->findOrFail($package->id);

                // Una respuesta tardía no debe degradar una validación ya confirmada.
                if ($locked->status === SiatPackage::STATUS_VALIDATED) {
                    return $locked;
                }

                $validated = $state === 908
                    && filter_var($body['transaccion'] ?? false, FILTER_VALIDATE_BOOLEAN);

                $locked->update([
                    'status' => $validated ? SiatPackage::STATUS_VALIDATED : SiatPackage::STATUS_SENT,
                    'siat_response' => $response,
                    'validated_at' => $validated ? now() : null,
                ]);

                if ($validated) {
                    $locked->invoices()
                        ->orderBy('siat_invoices.id')
                        ->lockForUpdate()
                        ->get()
                        ->each(function (SiatInvoice $invoice): void {
                            if (in_array($invoice->status, [
                                SiatInvoice::STATUS_VOIDED,
                                SiatInvoice::STATUS_VALIDATED,
                            ], true)) {
                                return;
                            }

                            $invoice->update([
                                'status' => SiatInvoice::STATUS_VALIDATED,
                                'validated_at' => now(),
                            ]);
                        });
                }

                return $locked->refresh();
            },
            3,
        );
```

La llamada SOAP conserva su posición anterior a esta transacción. Se conserva el último estado validado y su evidencia; las respuestas posteriores siguen teniendo el registro de transporte del cliente. Las actualizaciones individuales conservan eventos/auditoría. **Costo:** hasta 1.000 modelos y actualizaciones por paquete, límite ya presente en `buildAndSend`; una escritura masiva solo sería alternativa con auditoría equivalente. No reparar datos históricos silenciosamente en esta operación.

- **Contratos Inmutables (NO ROMPER):** Firma `validateReception(SiatPackage $package): SiatPackage`; payload SOAP; estados existentes; `voided_at` y fechas originales de facturas terminales. No trasladar la red al interior de la transacción ni modificar la anulación individual ya corregida en CRIT-10.
- **Criterio de Verificación:** `php -l app/Modules/Billing/Services/SiatPackageService.php`. Consola externa: `php artisan test --filter=SiatBillingModuleTest`. Paquete validado + respuesta tardía conserva estado/fecha; factura anulada en paquete pendiente conserva anulación al recibir 908; 908 con `transaccion=false` no valida; fallo al actualizar una factura revierte también el paquete. Intercalar dos consultas con respuestas en orden inverso y comprobar que ninguna degrada la validación confirmada.

## PARTE 2: ERRORES NO FUNCIONALES

### Entrada malformada antes de validar compras

- **ID de Tarea:** [NFR-01] [x] COMPLETADO
- **Severidad e Impacto:** Media — Entradas inválidas provocan excepciones antes de producir errores de formulario y pueden generar consultas proporcionales a un número ilimitado de ítems. El operador recibe fallo de sistema en lugar de validación corregible.
- **Archivo Objetivo:** `app/Modules/Purchases/Http/Requests/StorePurchaseRequest.php`.
- **Ubicación Exacta:** `StorePurchaseRequest::prepareForValidation`, L20-L60; `rules`, L92-L93; `withValidator`, L181-L190. Es un request distinto del de Sales corregido en CRIT-18; no reabrir aquella tarea.
- **Escenario de Fallo (Cómo se reproduce):** Enviar una compra con `items=[1]` o `display_unit_label=[]` en modo directo: el normalizador alcanza argumentos tipados antes de `rules()`.
  Enviar miles de ítems también amplifica la consulta inicial y las validaciones porque no existe cota de cantidad.
- **Ancla de Código Actual:**

```php
        $items = collect($this->input('items', []))
            ->map(function (array $item) use ($products): array {
                $mode = $item['calculation_mode'] ?? 'direct';
                $product = $products->get($item['product_id'] ?? null);
```

- **Especificación / Código Objetivo (Diff Quirúrgico):** Insertar al entrar en `prepareForValidation`, **antes** de `$products = Product::query()` en L22. Validar forma y rango antes de convertir; permitir cero provisional para no impedir normalizaciones que luego calculen un valor positivo.

```php
        \Illuminate\Support\Facades\Validator::make($this->all(), [
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*' => ['required', 'array'],
            'items.*.product_id' => ['nullable', 'integer'],
            'items.*.calculation_mode' => ['nullable', 'in:direct,length,weight'],
            'items.*.display_unit_label' => ['nullable', 'string', 'max:24'],
            'items.*.display_quantity' => ['nullable', 'numeric', 'between:0,999999999999.999'],
            'items.*.meters' => ['nullable', 'numeric', 'between:0,999999999999.999'],
            'items.*.base_quantity' => ['nullable', 'numeric', 'between:0,999999999999.999'],
            'items.*.length' => ['nullable', 'numeric', 'between:0,999999999999.999'],
        ])->validate();
```

Las cotas numéricas corresponden a la cantidad base ya admitida por `items.*.meters`; las reglas posteriores siguen comprobando el resultado calculado y su positividad. Fijar 200 líneas como límite operativo de esta tarea; documentos mayores deberán dividirse.

```diff
-            'items' => ['required', 'array', 'min:1'],
+            'items' => ['required', 'array', 'min:1', 'max:200'],
+            'items.*' => ['required', 'array'],
```

Insertar al inicio del callback de `withValidator`, L183, antes de llamar a `BranchAccess::validate`:

```php
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
```

- **Contratos Inmutables (NO ROMPER):** `authorize()` y permiso `purchases.manage`; nombres de campos; modos `direct`, `length`, `weight`; conversiones y productos nuevos válidos. Mantener errores de validación por campo; para JSON usar 422 y para Inertia su redirección/error bag habitual, sin inventar una respuesta JSON manual. **Costo de UX:** máximo 200 líneas y dos pasadas de validación acotadas; una importación masiva necesitaría un flujo separado.
- **Criterio de Verificación:** `php -l app/Modules/Purchases/Http/Requests/StorePurchaseRequest.php`. Consola externa: `php artisan test --filter=PurchasesModuleTest`. Agregar `items=[1]`, unidad array, `product_id` array, `new_product` escalar, números fuera de rango y 201 filas: errores de campo sin 500 ni escrituras; la guarda inicial rechaza forma/tamaño antes de consultar productos. Con 200 filas válidas, normalización directa, longitud, peso y alta de producto siguen operativas. Probar cero provisional sustituido por una cantidad derivada válida.

### Páginas operativas sin cota de servidor

- **ID de Tarea:** [NFR-02] [x] COMPLETADO
- **Severidad e Impacto:** Media — Un usuario con permiso de consulta puede solicitar el histórico con sus relaciones en una sola página, elevando memoria, tamaño de respuesta y ocupación de workers PHP.
- **Archivo Objetivo:** `app/Modules/Sales/Http/Controllers/SaleReturnController.php`; `app/Modules/Sales/Http/Controllers/DeliveryNoteController.php`; `app/Modules/Production/Http/Controllers/ProductionOrderController.php`; `app/Modules/Cash/Http/Controllers/CashRegisterController.php`; `app/Modules/Payments/Http/Controllers/CreditNotes/CreditNoteController.php`.
- **Ubicación Exacta:** Respectivamente, `SaleReturnController::index` L52-L54; `DeliveryNoteController::index` L61-L63; `ProductionOrderController::index` L35-L37; `CashRegisterController::index` L36-L38; `CreditNoteController::index` L48-L50.
- **Escenario de Fallo (Cómo se reproduce):** Abrir cualquiera de estos listados con `per_page=1000000` usando una cuenta autorizada y una base con histórico voluminoso.
  El valor llega directamente a `paginate()`; la restricción visual del formulario no limita la petición HTTP.
- **Ancla de Código Actual:** Las cinco anclas, en el orden de archivos indicado:

```php
            ->latest('returned_at')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();
```

```php
            ->latest('delivered_at')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();
```

```php
            ->latest('produced_at')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();
```

```php
            ->latest('opened_at')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();
```

```php
            ->latest('issued_at')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();
```

- **Especificación / Código Objetivo (Diff Quirúrgico):** Aplicar una vez en cada `index` identificado:

```diff
-            ->paginate($request->integer('per_page', 15))
+            ->paginate(max(1, min(100, $request->integer('per_page', 15))))
```

- **Contratos Inmutables (NO ROMPER):** Rutas, permisos, alcance por sucursal/usuario, propiedades Inertia `returns`, `deliveries`, `orders`, `sessions`, `creditNotes`, filtros, enlaces y estructura de paginación; valor predeterminado 15. La cota protege la página principal, no las colecciones auxiliares ni el costo de `COUNT(*)`. No migrar a cursor en este lote porque cambiaría el contrato del cliente.
- **Criterio de Verificación:** Ejecutar externamente `php -l app/Modules/Sales/Http/Controllers/SaleReturnController.php`, `php -l app/Modules/Sales/Http/Controllers/DeliveryNoteController.php`, `php -l app/Modules/Production/Http/Controllers/ProductionOrderController.php`, `php -l app/Modules/Cash/Http/Controllers/CashRegisterController.php` y `php -l app/Modules/Payments/Http/Controllers/CreditNotes/CreditNoteController.php`. PHPUnit externo: `php artisan test --filter="SalesReturnsModuleTest|DeliveryNotesModuleTest|ProductionModuleTest|CashModuleTest|CreditNotesModuleTest"`. Con 101 registros accesibles por módulo: tamaño enorme → máximo 100 y página siguiente; 0/negativo → 1; omitido → 15; sucursales ajenas siguen excluidas. No confundir resultados de catálogos auxiliares con el paginador verificado.

### Detalles internos en errores de SIAT

- **ID de Tarea:** [NFR-03] [x] COMPLETADO
- **Severidad e Impacto:** Media — La excepción de infraestructura se incorpora literalmente al error de validación `billing`, exponiendo al consumidor detalles internos que deberían quedar en el registro técnico.
- **Archivo Objetivo:** `app/Modules/Billing/Services/SiatSoapClient.php`.
- **Ubicación Exacta:** `SiatSoapClient::call`, L42-L47; registro técnico en L43.
- **Escenario de Fallo (Cómo se reproduce):** Simular un fallo SOAP o de construcción del cliente con un mensaje que contenga una ruta interna o marcador privado.
  El mensaje completo termina dentro de `ValidationException::errors()['billing']`, listo para ser presentado como error de formulario.
- **Ancla de Código Actual:**

```php
            throw ValidationException::withMessages([
                'billing' => "No se pudo comunicar con SIAT en {$method}. Verifica token, ambiente, conexion y codigos fiscales. Detalle tecnico: {$exception->getMessage()}",
            ]);
```

- **Especificación / Código Objetivo (Diff Quirúrgico):** Reemplazar las tres líneas ancladas por:

```php
            throw ValidationException::withMessages([
                'billing' => 'No se pudo confirmar la comunicación con SIAT. Solicita al administrador revisar el registro de facturación antes de reintentar.',
            ]);
```

- **Contratos Inmutables (NO ROMPER):** Firma pública `call`, excepción `ValidationException`, clave `billing`, timeout y registro técnico existentes. No convertir el fallo en éxito ni añadir reintentos ciegos de operaciones fiscales.
- **Criterio de Verificación:** `php -l app/Modules/Billing/Services/SiatSoapClient.php`. Consola externa: `php artisan test --filter=SiatBillingModuleTest`. Agregar una prueba con fallo controlado antes de iniciar red, mensaje privado y persistencia del log disponible: el marcador no aparece en el error de validación y sí permanece en el registro técnico. El modo `testing` devuelve respuestas simuladas en este cliente: configurar el doble de la prueba para alcanzar el `catch`, no dar por cubierto ese camino con el mock exitoso predeterminado.
