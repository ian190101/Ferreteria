# Plano de optimización, arquitectura y UI — Fabrica_Calmina_Aroma

Fecha: 21 de octubre de 2026. Ejecutor: Sol 6.1. Base: HEAD `28c3ea35df5ca52d5adbb58cded8202c8a4d8ca3` más cambios locales. Complementa BLUEPRINT_BACKEND_CRITICO.md.

## Inventario cerrado de lectura: 25 archivos

1. `app/Modules/CashFlow/Services/CashFlowReportService.php`
2. `app/Modules/Finance/Services/FinancialLedgerService.php`
3. `app/Modules/Cash/Http/Controllers/CashRegisterController.php`
4. `app/Modules/Cash/Support/CashSessionGuard.php`
5. `app/Modules/Payments/Http/Controllers/PaymentController.php`
6. `app/Modules/Sales/Http/Controllers/SaleController.php`
7. `app/Modules/Sales/Services/SaleInventoryService.php`
8. `app/Modules/Sales/Services/SalePaymentService.php`
9. `app/Modules/Sales/Listeners/ProcessSaleNoteIssued.php`
10. `app/Modules/Sales/Events/SaleNoteIssued.php`
11. `app/Modules/Production/Http/Controllers/ProductionOrderController.php`
12. `app/Modules/Purchases/Services/PurchaseService.php`
13. `app/Modules/Inventory/Services/InventoryQuantityService.php`
14. `app/Modules/Billing/Services/SiatInvoiceService.php`
15. `app/Modules/Sales/Http/Requests/StoreSaleDocumentRequest.php`
16. `app/Modules/Shared/Models/AuditableModel.php`
17. `app/Modules/Inventory/Models/InventoryMovement.php`
18. `app/Modules/Payments/Models/SalePayment.php`
19. `config/audit.php`
20. `routes/web.php`
21. `app/Modules/Sales/routes/web.php`
22. `app/Modules/Cash/routes/web.php`
23. `database/migrations/2026_07_24_100000_add_operational_performance_indexes.php`
24. `database/migrations/2026_07_29_151836_add_composite_indexes_for_operational_performance.php`
25. `app/Modules/CashFlow/Http/Controllers/CashFlowController.php`

Se leyeron además los mapas docs/arbol-nodos.md y graphify-out/GRAPH_REPORT.md. No se cargó graph.json. Las consultas de metadatos, hashes y estado Git no son una auditoría de los otros archivos.

La topología enumera 29 módulos. No hay módulo Costos separado: los costos operativos examinados están en ProductionOrderController::store L69-L170; Finance centraliza aritmética y resúmenes; Inventory contiene existencias/kardex; Sales, Payments, Cash, Purchases y Billing forman el circuito comercial. El mapa declara Laravel 12, PHP 8.2+, Inertia 2 y React 18; el PHP CLI observado es 8.2.12 con bcmath. No se validó el runtime del servidor.

El reporte AST destaca User (327 conexiones), AuditableModel (214), Product (176) y Sale (130), L334-L344. Comunidades 38, 57, 80 y 84 conectan inventario, ventas, reportes y facturación. No detecta ciclos de imports (L358-L359); eso no prueba respeto de fronteras. Evidencia local de escrituras cruzadas:

| Origen | Destino directo | Evidencia | Decisión |
|---|---|---|---|
| ProductionOrderController | Inventory: ProductBranchStock/ProductCoil/InventoryMovement | L138-L163, L269-L311 | Extraer aplicación de inventario al módulo propietario conservando transacción |
| Sales/SaleInventoryService | Inventory: stock, bobinas, reservas, kardex | L109-L134, L138-L224, L257-L307 | Frontera de aplicación síncrona; no propagar modelos mutables a UI |
| Finance/FinancialLedgerService | Payments, Sales, Purchases, Banks, Expenses, HumanResources | L26-L198, L239-L258 | Fachada transversal ya existente; reforzar contratos, no duplicar un ledger por módulo |
| Purchases/PurchaseService | Inventory y Payments/Banks | L82-L123 | Conservar su orquestación y orden de bloqueos; delegación futura sin cambiar orden |
| CashFlowReportService | Lectura de seis orígenes | L121-L295 | Lectura cruzada legítima; no crear eventos solo para consultar |

No hay evidencia suficiente para eliminar métodos «huérfanos» por ausencia en este reporte: omite 153 comunidades pequeñas y contiene enlaces inferidos. Borrar solo los imports/variables demostrados abajo. No regenerar el grafo ni modificar configuración durante esta auditoría.

## Lotes: los 29 módulos, una sola vez

Ejecutar una tarea verificable por vez, con un máximo de 5 archivos abiertos de implementación por paso. Antes de editar, cotejar anclas contra el árbol de trabajo. No revertir modificaciones preexistentes. «Pendiente» significa ruta ubicada en el mapa pero código no leído: la primera acción es verificar; no es una vulnerabilidad confirmada. En esas rutas L≈1–fin designa el archivo que debe inspeccionarse, no un rango de defecto inventado.

### Lote 1 — Core financiero e inventario (9 módulos)

| Orden | Módulo | Entrada y localización | Entrega |
|---|---|---|---|
| 1 | Finance | app/Modules/Finance/Services/FinancialLedgerService.php L26-L198/L239-L258/L333-L347 | CRIT-01/12/15; OPT-02 |
| 2 | Cash | app/Modules/Cash/Http/Controllers/CashRegisterController.php L26-L59/L69-L123; Support/CashSessionGuard.php L11-L26 | CRIT-06/07/08; OPT-03 |
| 3 | Inventory | app/Modules/Inventory/Services/InventoryQuantityService.php L25-L57; Models/InventoryMovement.php L8-L35 | CRIT-03/16; ARQ-01 |
| 4 | Payments | app/Modules/Payments/Http/Controllers/PaymentController.php L33-L112 | CRIT-01/02/08/12; OPT-04; UI-01 |
| 5 | Banks | app/Modules/Banks/Services/BankReconciliationService.php L≈1–fin (pendiente) | Comprobar mismo protocolo de caja y bloqueo de movimiento antes de cuenta; no aplicar cambios ciegos |
| 6 | Expenses | app/Modules/Expenses/Http/Controllers/ExpenseController.php L≈1–fin (pendiente) | Comprobar escritor de caja con CRIT-08 y conservar outbox/rollback existentes |
| 7 | CashFlow | app/Modules/CashFlow/Services/CashFlowReportService.php L27-L376; Http/Controllers/CashFlowController.php L15-L26 | CRIT-14/17; OPT-01 |
| 8 | Audit | app/Modules/Audit/Http/Controllers/AuditLogController.php L≈1–fin (pendiente); config/audit.php L65-L203 (auditado) | Verificar permisos, visibilidad y retención; CRIT-16 |
| 9 | Shared | app/Modules/Shared/Models/AuditableModel.php L9-L11; Resources/Components/Pagination.jsx L≈1–fin (pendiente) | Mantener concern de auditoría; conservar contrato LengthAwarePaginator |

**Eje consultas/BD:** completar OPT-01 a OPT-05. No convertir each(update) auditado a actualización masiva; no afirmar N+1 en listados que ya usan with. En Banks/Expenses/PurchasePayments comprobar que filtros y nuevos índices usan la conexión activa y el alcance de sucursales antes de tocar SQL.

**Eje limpieza:** ejecutar LIM-01; tipar los métodos nuevos y documentar array-shapes. No extraer una interfaz para cada clase ni crear repositorios vacíos alrededor de Eloquent. CashFlowController ya delega a un servicio y poda columnas del catálogo: conservar esa separación.

**Eje modularidad:** ARQ-01; la escritura del saldo, el movimiento y su reverso siguen siendo síncronos/atómicos. No cachear decisiones de suficiencia de stock/saldo. El módulo Finance es una fachada de aplicación explícita, no un motivo para redistribuir todas sus tablas.

**Eje UI/UX:** UI-01 en Cash/Index, Payments/Index y Banks/Index; UI-03 en CashFlow/Index. Para rutas no leídas localizar el submit real con rg antes de editar. Puerta de salida: concurrencia de caja/cobro validada en BD real y totales SQL equivalentes al informe anterior corregido.

### Lote 2 — Operaciones, producción y ventas (9 módulos)

| Orden | Módulo | Entrada y localización | Entrega |
|---|---|---|---|
| 10 | Sales | app/Modules/Sales/Http/Controllers/SaleController.php L114-L238/L312-L475; Services/SaleInventoryService.php L27-L335; Listeners/ProcessSaleNoteIssued.php L20-L57 | CRIT-02/04/05/08/12; LIM-02; UI-02 |
| 11 | Purchases | app/Modules/Purchases/Services/PurchaseService.php L38-L149/L153-L210 | Mantener garantías existentes; verificar compatibilidad CRIT-12/15/16 |
| 12 | Production | app/Modules/Production/Http/Controllers/ProductionOrderController.php L61-L176/L179-L266/L269-L311 | CRIT-03/13; LIM-03 y ARQ-01 |
| 13 | Billing | app/Modules/Billing/Services/SiatInvoiceService.php L27-L297 | CRIT-09/10/11; ARQ-02 |
| 14 | Pos | app/Modules/Pos/Http/Controllers/PosController.php y Resources/Pages/Pos/Index.jsx L≈1–fin (pendientes) | Verificar checkout contra SaleController; misma identidad de operación al reintentar |
| 15 | Restaurant | app/Modules/Restaurant/Services/RecipeInventoryService.php L≈1–fin (pendiente) | Verificar stock/reservas/costos y orden de bloqueo antes de conectar ARQ-01 |
| 16 | ServiceOrders | app/Modules/ServiceOrders/Services/ServiceOrderInventoryService.php L≈1–fin (pendiente) | Verificar consumo de repuestos separado de servicio is_inventory_item=false |
| 17 | Reservations | app/Modules/Reservations/Services/ReservationAvailabilityService.php L≈1–fin (pendiente) | Diferenciar reserva de recurso/agenda de InventoryReservation; no intercambiar modelos |
| 18 | HumanResources | app/Modules/HumanResources/Http/Controllers/SalaryPaymentController.php L≈1–fin (pendiente) | Comprobar nómina/gasto en una transacción, sin doble cómputo de egreso |

**Eje consultas/BD:** OPT-06 reduce la consulta de usuario por ítem sin eliminar auditoría de movimientos. Los bucles de create de ítems/etapas pueden disparar observers: no hacer insert masivo hasta conocerlos. Ordenar bloqueos de recursos existentes por tipo e id en todos los escritores antes de añadir reintentos de deadlock.

**Eje limpieza:** LIM-02/LIM-03 extraen responsabilidades dentro del módulo. No mover clases a otra estructura arquitectónica ni añadir DTOs que repliquen modelos sin necesidad. Mantener FormRequests actuales; extraer la validación inline de fórmulas/etapas, no duplicarla.

**Eje modularidad:** ARQ-01 y ARQ-02. Un servicio de Inventory ejecuta el movimiento dentro de la transacción del caso de uso; un evento posterior al commit sirve para notificaciones o documentos derivados. No despachar «stock actualizado» antes del commit.

**Eje UI/UX:** UI-01 en formularios de operaciones; UI-02 en ventas/facturas/POS; conservar cantidades e importes como cadenas decimales hasta enviar. Las comprobaciones pendientes son casos concretos por módulo, no órdenes para corregir fallos que no se observaron. Salida: no hay facturas borradas por timeout ni doble consumo al repetir/concurrir.

### Lote 3 — Catálogos, soporte y reportes (11 módulos)

| Orden | Módulo | Entrada y localización (pendiente salvo indicación) | Verificación/entrega concreta |
|---|---|---|---|
| 19 | Customers | app/Modules/Customers/Http/Controllers/CustomerController.php L≈1–fin | Estado de cuenta: comparar saldos con pagos/créditos activos; listas con sucursal autorizada |
| 20 | Branches | app/Modules/Branches/Http/Controllers/BranchController.php L≈1–fin | No retirar columnas requeridas por UiCatalogCache; verificar invalidación al editar |
| 21 | Users | app/Modules/Users/Http/Controllers/UserController.php L≈1–fin | Paginación acotada, roles eager y autorización de sucursales en escritura |
| 22 | Dashboard | app/Modules/Dashboard/Http/Controllers/DashboardController.php L≈1–fin | Sumas SQL sobre fuentes contables corregidas; medir consultas sin inventar N+1 |
| 23 | Reports | app/Modules/Reports/Http/Controllers/ReportController.php L≈1–fin | Extraer únicamente la consulta pesada demostrada; mantener props de Reports/Index |
| 24 | Exports | app/Modules/Exports/Http/Controllers/ExportController.php y Services/ExportDatasetService.php L≈1–fin | Medir volumen; exportación completa por lotes o trabajo durable ARQ-03 |
| 25 | Printing | app/Modules/Printing/Services/PrintRenderService.php L≈1–fin | No marcar como impreso antes de confirmar resultado; respetar PrintJob existente |
| 26 | Alerts | app/Modules/Alerts/Http/Controllers/AlertController.php L≈1–fin | Contadores SQL, deduplicación de avisos, enlaces autorizados |
| 27 | Settings | app/Modules/Settings/Services/ProductionUpdateService.php L≈1–fin | Revisar tareas largas y Jobs existentes antes de añadir colas; no tocar restore/deploy en este refactor |
| 28 | SystemSuperadmin | app/Modules/SystemSuperadmin/Services/CustomerQrOrderConversionService.php L≈1–fin | Conversión QR reutiliza reglas de venta/caja/idempotencia; mantener aislamiento sandbox |
| 29 | AiAssistant | app/Modules/AiAssistant/Services/AiAssistantActionService.php L≈1–fin | Escrituras de herramientas deben pasar por autorización/casos de uso; no confiar en precios del modelo |

**Eje consultas/BD:** heredar únicamente cambios respaldados por trazas; no aplicar un select genérico a catálogos sin verificar qué props usa el consumidor. Para reportes/exportaciones reutilizar la proyección normalizada de OPT-01 cuando el dominio sea flujo de efectivo, sin extrapolarla a utilidad/costo devengado.

**Eje limpieza:** quitar imports o privados solo tras comprobar referencias en el módulo, rutas, eventos y contenedor. No ejecutar un «borrado global» basado en el grafo. Extraer funciones de consulta/transformación si mezclan responsabilidades; conservar firmas de controladores, nombres de rutas y claves de respuesta detectadas antes de editar.

**Eje modularidad:** ARQ-03; colas con autorización en dispatch y en ejecución, contexto de conexión explícito, salida privada y recuperación. Settings ya contiene Jobs de mantenimiento en el mapa: no crear una segunda infraestructura.

**Eje UI/UX:** UI-03 para filtros/tablas; trabajos largos con estado consultable y descarga autorizada. Catálogos conservan formulario y errores al fallar. Salida: todos los módulos tienen su verificación registrada; ausencia de evidencia produce «sin cambio», no una refactorización especulativa.

## Especificaciones de consultas y base de datos

### OPT-01 — CashFlow: paginación y agregados SQL sin cambiar la respuesta

**Archivos:** app/Modules/CashFlow/Services/CashFlowReportService.php L27-L40/L88-L376. Especificación definitiva que reemplaza la contención CRIT-14. Aplicar después de CRIT-17. No crear una tabla materializada ni otro ledger.

**Forma de implementación:** conservar build(Request):array, filters(Request):array y sourceLabel(string):string. Añadir privados movementQuery(Request $request, array $filters): \Illuminate\Database\Query\Builder, sourceQuery(string $source, Request $request, array $filters): \Illuminate\Database\Query\Builder y presentMovement(object $row): array. La segunda función acepta solo las seis fuentes de la tabla siguiente; cualquier otro valor es LogicException. No aceptar nombres de tabla/columna desde request.

Cada rama comienza con el Eloquent builder del modelo actual, incluyendo sus global scopes, BranchAccess y fechas. Convertirlo a una tabla derivada m con DB::query()->fromSub($eloquent->toBase(), 'm'). No sustituirlo por DB::table sin scopes. Las relaciones branch/user/method/sale/purchase/category/account se convierten en LEFT JOIN a subconsultas del modelo relacionado con sus scopes y las columnas ya usadas en los with actuales. Preservar también los scopes específicos de las relaciones: obtener el builder mediante Relation::noConstraints(fn () => $model->{$relation}())->getQuery(), no por una tabla inferida. Usar id como clave única; no unir relaciones hasMany en la proyección de movimientos. Así cada movimiento sigue produciendo una fila.

| Rama | Modelo y fecha | Predicados extra, antes del UNION | Relaciones, FK de m |
|---|---|---|---|
| sale_payment | SalePayment / paid_at | Los de basePaymentQuery | branch/branch_id; user/user_id; method/payment_method_id; sale/sale_id |
| purchase_payment | PurchasePayment / paid_at | Los de basePaymentQuery | branch; user; method; purchase/purchase_id |
| legacy_sale | Sale / sold_at | document_type=sale_note; total>0; balance_due<total; status null o !=void; NO pagos incluyendo withTrashed; subconsulta cash_flow_credits de CRIT-17 | branch; user |
| legacy_purchase | Purchase / purchase_date | paid_amount>0; status null o !=void; NO pagos incluyendo withTrashed | branch; user |
| expense | Expense / spent_at | status=Expense::STATUS_REGISTERED | branch; user; paymentMethod/payment_method_id; category/expense_category_id |
| bank | BankTransaction / transacted_at | status=STATUS_REGISTERED; source_type IS NULL; type in TYPE_DEPOSIT/TYPE_WITHDRAWAL | branch; user; account/bank_account_id |

En ramas sin FK explícita en la tabla, branch usa branch_id y user usa user_id. Mantener las reglas de activación de movements L92-L114: legacy y bank solo cuando no existe payment_method_id; source controla qué ramas participan. No filtrar los pagos por estado de venta anulada: representa efectivo histórico hasta su reverso; cualquier cambio contable requiere otro diseño.

Todas las ramas proyectan exactamente estas columnas, en el mismo orden:

~~~
key, date, type, source, source_label, document, description,
branch_id, branch_name, method_id, method_name, user_name,
reference, notes, amount, signed_amount, related_kind, related_id
~~~

Usar literales con selectRaw y bindings; columnas de aliases internos fijos. date es el datetime original; presentMovement lo convierte con Carbon::parse(...)->toIso8601String(), no con una cadena UTC inventada. Los importes SQL se castearían a DECIMAL(30,2), conservando la escala de salida actual. Tabla de expresiones: m refiere a la fila raíz; s/p/b/u/pm/c/a a sale/purchase/branch/user/paymentMethod/category/account.

| Columna | sale_payment | purchase_payment | legacy_sale |
|---|---|---|---|
| key | CONCAT('sale-',m.id) | CONCAT('purchase-',m.id) | CONCAT('legacy-sale-',m.id) |
| type/source/source_label | income / sales / Cobro cliente | expense / purchases / Pago proveedor | income / sales / Cobro cliente heredado |
| document | COALESCE(s.receipt_number,CONCAT('Pago ',m.id)) | COALESCE(p.document_number,CONCAT('Compra ',m.purchase_id)) | m.receipt_number |
| description | COALESCE(s.customer_name,'Cliente ocasional') | Pago de compra | CONCAT(COALESCE(NULLIF(m.customer_name,''),'Cliente ocasional'),' - cobro registrado en la nota') |
| amount | COALESCE(m.amount_bob,m.amount) | m.amount | GREATEST(ROUND((m.total-m.balance_due-COALESCE(m.cash_flow_credits,0))*m.exchange_rate_to_bob,2),0) |
| related_kind/id | sale / m.sale_id | purchase / m.purchase_id | sale / m.id |
| method_id/name | m.payment_method_id / COALESCE(pm.name,'Sin metodo') | igual | NULL / Registrado en venta |
| reference/notes | m.reference / m.notes | igual | NULL / Movimiento inferido desde venta sin registro detallado de pago. |

Para amount_bob preservar cero decimal válido; no usar NULLIF(amount_bob,0). Si el esquema permite cadenas vacías, caracterizarlas antes y normalizar datos inválidos en una tarea separada, no convertirlas silenciosamente a otra moneda.

| Columna | legacy_purchase | expense | bank |
|---|---|---|---|
| key | CONCAT('legacy-purchase-',m.id) | CONCAT('expense-',m.id) | CONCAT('bank-',m.id) |
| type | expense | expense | CASE type=TYPE_DEPOSIT THEN income ELSE expense END |
| source | purchases | expenses | banks |
| source_label | Pago proveedor heredado | COALESCE(c.name,'Gasto') | Deposito bancario manual / Retiro bancario manual según tipo |
| document | COALESCE(m.document_number,CONCAT('Compra ',m.id)) | COALESCE(NULLIF(m.reference,''),CONCAT('Gasto ',m.id)) | COALESCE(NULLIF(m.reference,''),CONCAT('Movimiento banco ',m.id)) |
| description | Pago registrado en la compra | m.description | COALESCE(NULLIF(m.description,''),a.name,'Banco') |
| amount | m.paid_amount | m.amount | m.amount |
| related_kind/id | purchase / m.id | expense_category / m.expense_category_id | bank_account / m.bank_account_id |
| method_id/name | NULL / Registrado en compra | m.payment_method_id / COALESCE(pm.name,'Sin metodo') | NULL / COALESCE(a.name,'Banco') |
| reference | NULL | m.reference | m.reference |
| notes | Movimiento inferido desde compra sin registro detallado de pago. | m.notes | m.void_reason |

Común: branch_id=m.branch_id; branch_name=COALESCE(b.name,'Sin sucursal'); user_name=COALESCE(u.name,'-'); signed_amount=amount para income y -amount para expense. Literales de estado/tipo bancario se obtienen de constantes del modelo y se bindean. En la rama legacy_sale excluir amount<=0 en una subconsulta exterior, después de calcular la expresión.

Combinar mediante unionAll, NO union (no deduplicar movimientos diferentes). Encapsularlo:
~~~php
$query = DB::query()->fromSub($union, 'cash_movements');
~~~
Aplicar aquí type, search, high_value y without_reference ANTES de clonar para página, resumen y grupos. source ya seleccionó ramas. No limitar filas antes de filtros/totales.

- type: where('type', $filters['type']) solo si !=all.
- high_value: where('amount','>=','1000.00').
- without_reference: whereNull('reference') OR TRIM(reference)=''.
- search: búsqueda literal sin distinguir mayúsculas, no un LIKE donde %/_ cambien de significado. En MySQL/TiDB utilizar por cada una de document/description/reference el fragmento fijo LOCATE(CAST(? AS BINARY), CAST(LOWER(COALESCE(columna, '')) AS BINARY)) > 0 con mb_strtolower(search) como binding. Agrupar tres OR dentro de un único where. Para otro driver detener esta tarea y adaptar únicamente esta expresión con pruebas de equivalencia; no desplegar SQL incompatible por suponer MySQL. El código ya usa CAST AS UNSIGNED en Billing, pero eso no demuestra el motor activo de todos los entornos.

Resumen: una consulta selectRaw sobre (clone $query), con expresiones exactas:
~~~
income = COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE 0 END),0)
expense = COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END),0)
count = COUNT(*)
income_count = COALESCE(SUM(CASE WHEN type='income' THEN 1 ELSE 0 END),0)
expense_count = COALESCE(SUM(CASE WHEN type='expense' THEN 1 ELSE 0 END),0)
average_income = COALESCE(ROUND(AVG(CASE WHEN type='income' THEN amount END),2),0)
average_expense = COALESCE(ROUND(AVG(CASE WHEN type='expense' THEN amount END),2),0)
~~~
net = income-expense, en decimal; convertir únicamente los valores presentados al tipo numérico que recibe hoy la UI. El resumen no debe sumar la página. No implementar «total aproximado».

Los tres grupos usan clones independientes, columnas permitidas source/branch_name/method_name; agrupar COALESCE(NULLIF(columna,''),'Sin dato'), SUM condicional de income/expense, COUNT(*); ordenar net DESC y label ASC como desempate. Aplicar sourceLabel al label para mantener el comportamiento previo. No agrupar por branch_id sin una migración de contrato: hoy agrupa sucursales con el mismo nombre juntas.

Página:
~~~php
$page = max($request->integer('page', 1), 1);
$rows = (clone $query)->orderByDesc('date')->orderBy('key')
    ->forPage($page, $filters['per_page'])->get();
~~~
Construir el mismo Paginator con $rows->map(presentMovement), total=$summary['count'], perPage/page y options path/query actuales. Orden secundario key garantiza resultado estable con fechas iguales; no altera el conjunto ni totales. presentMovement conserva las primeras 16 columnas públicas, convierte amount/signed_amount a float SOLO al presentar y añade related_url:
- sale: route('sales.show', related_id) o null si no existe id.
- purchase: route('purchases.show', related_id) o null.
- expense_category: route('expenses.index', ['expense_category_id'=>related_id]).
- bank_account: route('banks.index', ['bank_account_id'=>related_id]).
Eliminar related_kind/related_id de la respuesta.

Al terminar sustituir build L30-L38 para utilizar las consultas anteriores; eliminar movements, las seis funciones Collection, applyCollectionFilters, summary(Collection), groupBy(Collection) y paginate(Collection), pero SOLO después de sustituir todos sus usos. Conservar filters/sourceLabel y el alcance de basePaymentQuery; retirar el limit/tap provisional de CRIT-14. No conservar dos implementaciones activas.

**Pruebas de aceptación:** fixture con las seis fuentes, soft deletes, créditos, fechas límite, sucursales homónimas y filtro autorizado; comparar todas las claves y cifras, no solo conteos. Incluir búsquedas con ñ/á/%/_ y referencia vacía. Verificar casts/global scopes/relaciones eliminadas con los mismos resultados de Eloquent. Usar dos usuarios de diferentes sucursales. Con 1M movimientos, solo per_page filas de detalle se hidratan en PHP; resumen y grupos son SQL. Capturar EXPLAIN del motor real y memoria máxima. No prometer menor tiempo de agregación sin medir: el motor aún debe procesar las filas del rango.

### OPT-02 — Conciliación bancaria sin crecimiento ilimitado de memoria

**Archivo:** app/Modules/Finance/Services/FinancialLedgerService.php, attachBankTransactionsToCashSession L333-L347.

En ambas consultas reemplazar get()->each(...) por chunkById(500, function ($transactions) use ($session) { foreach (...) { update existente; } });. Segunda consulta no necesita capturar session si no se usa en el cuerpo. Mantener los predicados whereNull/where de cada consulta y los dos updates originales. No usar chunk() por OFFSET porque el update elimina filas del conjunto whereNull y produciría saltos. No podar columnas de estos modelos auditados: el audit puede necesitar valores anteriores. Es una mejora de memoria; el número de updates sigue siendo N para conservar eventos.

Prueba: 1201 transacciones no conciliadas, ejecutar cierre y contar 1201 vínculos, sin saltos, con auditorías; rollback conserva todos los registros. php artisan test --filter=CashModuleTest.

### OPT-03 — Caja: N consultas por sesión abierta

**Archivo:** CashRegisterController::index L40-L59; FinancialLedgerService::cashSessionSummary L200-L220 y cashIncome/cashExpense/bankIncome/bankExpense L289-L330.

Evidencia: cinco agregaciones SQL por cada sesión abierta, además de sus relaciones; no es N+1 de eager loading. No eliminar openSessions ni cortar la lista silenciosamente.

Añadir FinancialLedgerService::cashSessionSummaries(Collection $sessions, Carbon $endAt): array, indexado por id. Implementar cinco consultas agrupadas por id de sesión usando como base CashRegisterSession::query()->whereIn('id',$sessions->modelKeys()); cada consulta une SOLO una fuente (sale_payments, expenses, purchase_payments, depósitos o retiros bancarios) mediante subconsulta Eloquent con scopes. Condiciones de join exactas:
- caja: branch_id de sesión=movimiento.branch_id, opened_by=user_id, fecha entre opened_at y $endAt, método con code=cash; expenses además status=REGISTERED.
- bancos: status=REGISTERED y (cash_register_session_id=session.id OR (cash_register_session_id IS NULL y branch/user/período coinciden)).
Sumar amount_bob en cobros; amount en las otras cuatro. No unir todas las fuentes simultáneamente: multiplicaría filas y saldos.
En index obtener un solo now() para todas las sesiones, generar el mapa y usarlo en el map actual. Conservar seis current_* attributes y el cálculo expected=opening+income-expense. No llamar attachBankTransactions desde GET.
Prueba con 1 y 30 sesiones: cantidad de consultas de sumas constante (5); resultados iguales a cashSessionSummary por sesión. Este refactor solo se implementa tras revisar los modelos/relaciones adicionales; no desactivar scopes para facilitar joins.

### OPT-04 — Paginación acotada y filtros aprovechables por índices

**Archivos y líneas:** PaymentController L38-L41/L62; CashRegisterController L37; SaleController L66; ProductionOrderController L35.

En los cuatro paginate(per_page) usar max(1,min($request->integer('per_page',15),100)); en methods_per_page usar default 10 y mismo rango. Mantener paginate, withQueryString y parámetro methods_page. Añadir orderByDesc('id') después de latest(fecha) para orden total estable. No adoptar cursorPaginate en contratos que exponen last_page/total.

PaymentController::index: validar from/to con date_format:Y-m-d (to posterior/igual a from cuando exista); sustituir whereDate por rango de timestamps:
~~~php
->when($request->filled('from'), fn ($query) => $query->where('paid_at', '>=', $request->date('from')->startOfDay()))
->when($request->filled('to'), fn ($query) => $query->where('paid_at', '<', $request->date('to')->addDay()->startOfDay()))
~~~
Conservar timezone de aplicación y alcance de BranchAccess. No concatenar input en SQL. Verificación: per_page 0, -1 y 1000000 nunca provocan consulta ilimitada; movimiento justo a medianoche del día siguiente excluido. Ejecutar pruebas de cada módulo.

### OPT-05 — Índices aditivos con verificación previa obligatoria

**Evidencia:** migraciones examinadas 2026_07_24_100000 L11-L30 y 2026_07_29_151836 L11-L22 ya contienen índices operativos. No se revisó el esquema desplegado ni todas las migraciones; por tanto «ausente» NO está demostrado.

**Nuevo archivo de implementación:** database/migrations/2026_10_21_000001_add_audit_targeted_query_indexes.php. No modificar las dos migraciones históricas. Candidatos exactos:

| Tabla | Columnas ordenadas | Nombre nuevo | Consulta |
|---|---|---|---|
| sale_payments | branch_id,user_id,paid_at | sp_branch_user_paid_audit_idx | FinancialLedgerService L291-L296 |
| purchase_payments | branch_id,user_id,paid_at | pp_branch_user_paid_audit_idx | FinancialLedgerService L309-L314 |
| expenses | branch_id,user_id,status,spent_at | ex_branch_user_status_spent_audit_idx | FinancialLedgerService L301-L307 |
| bank_transactions | branch_id,user_id,status,transacted_at | bt_branch_user_status_date_audit_idx | FinancialLedgerService L364-L371 |
| inventory_movements | source_type,source_id,type | im_source_type_audit_idx | SaleInventoryService::alreadyMoved L313-L317 |

Procedimiento determinista para el ejecutor: consultar Schema::getIndexes($table); si un índice existente tiene como prefijo ordenado exactamente las columnas candidatas, omitir candidato. Si existe el nombre nuevo con otras columnas, detener migración con RuntimeException; no borrar ese índice. Medir EXPLAIN con filtros reales en una réplica/BD de pruebas: crear solo candidatos sin cobertura y con escaneo evitable. No decir que branch_id,paid_at cubre branch_id,user_id,paid_at.

Bloque de creación una vez satisfechas esas comprobaciones:
~~~php
Schema::table('sale_payments', function (\Illuminate\Database\Schema\Blueprint $table): void {
    $table->index(['branch_id', 'user_id', 'paid_at'], 'sp_branch_user_paid_audit_idx');
});
~~~
Repetir mecánicamente con cada fila aceptada de la tabla. up():void; down():void deliberadamente sin eliminación automática, para no borrar índices preexistentes compartidos cuando up omitió su creación. Documentar esa decisión en comentario español; la retirada requiere otra migración explícita. No indexar notas/textos de búsqueda con B-tree esperando acelerar contains.

im_source_type_audit_idx es NO único. No poner unique(source_type,source_id,type): compras globales y producción pueden registrar varios ítems bajo un mismo documento/tipo. Para idempotencia de una operación usar un identificador propio, no una unicidad incompatible con el historial.

Comandos futuros: php artisan migrate --pretend --env=testing; luego pruebas en BD desechable. Un EXPLAIN y una migración no son evidencia de aceleración hasta comparar lecturas, latencia y costo de escritura.

### OPT-06 — Evitar consulta repetida de usuario en descuentos de stock

**Archivo:** app/Modules/Sales/Services/SaleInventoryService.php L27-L45/L138-L146/L320-L334.

En decrementForSale resolver una sola vez User::query()->with('roles:id,name')->find($userId) SI workflow->allowsNegativeStock(). Precargar items.product.productCategory con FK product_category_id disponible (CRIT-02). Añadir parámetro privado ?User $actor = null al final de decrementGlobalStock y pasarlo desde decrementForSale. Cambiar allowsNegativeStockFor(SaleItem $item, int $userId) a (SaleItem $item, ?User $actor) y usarlo, sin consulta interna. En decrementForDeliveryItem resolver su actor una vez y pasarlo a decrementGlobalStock. Conservar el early return si la política no permite negativos; un actor ausente no autoriza.

No memoizar User en una propiedad singleton: Octane/colas podrían reutilizar permisos de otro usuario. No reemplazar la regla CommercialPolicy por «permite negativo global». Prueba 50 ítems: como máximo una lectura de usuario y una de roles en ese servicio, misma autorización por categoría. Las consultas de movimientos/stock por ítem se mantienen para su trazabilidad.

## Limpieza y límites arquitectónicos

### LIM-01 — Poda demostrada y tipado localizado

- FinancialLedgerService.php L16/L19: quitar imports App\Support\UiCatalogCache e Illuminate\Support\Facades\Cache; no se usan en el cuerpo examinado.
- SiatInvoiceService.php L7: quitar import SiatInvoiceItem; se crean ítems mediante la relación.
- SaleController.php L871: cambiar foreach ($layout['item_columns'] as $index => $column) a foreach (... as $column); index no se usa.
- ProductionOrderController.php L76: quitar $outputCoil = null; solo se lee después de asignar el modelo en la rama de bobina. Corregir la indentación L294.
- Añadir declare(strict_types=1) a clases NUEVAS. En existentes hacerlo únicamente después de adaptar argumentos normalizados en las pruebas; una declaración global automática puede romper llamadas con strings numéricos.
- Tipos pendientes demostrados: SaleController::availableCoilsByProduct L617 retorna Illuminate\Support\Collection de grupos; reservedMetersByCoil L632, reservedMetersForQuotationByCoil L646 y reservedMetersForQuotationByProduct L661 retornan Illuminate\Support\Collection. availableCoils L710 y convertibleQuotations L720 retornan Illuminate\Database\Eloquent\Collection. allowedPaymentMethods L898 depende del tipo real de UiCatalogCache no leído: verificarlo antes de fijar tipo. No deducir el retorno por el nombre.
- No eliminar advanceValues, signedBankAmount, métodos públicos de modelos ni listeners por una búsqueda local sin coincidencias: los llama el contenedor, Eloquent o código fuera de la muestra. No hay evidencia suficiente para declarar otros privados huérfanos.

Verificación mínima para poda: php -l de cada PHP editado y las pruebas del módulo solo si hubo cambio de comportamiento. No escribir tests que solo comprueben que desapareció un import.

### LIM-02 — Extraer presentación de ventas sin mover su transacción

**Archivo origen:** SaleController.php L801-L841/L848-L895.
**Nuevo:** app/Modules/Sales/Services/SaleTemplateService.php.
**API nueva:** public function forSale(Sale $sale): array.

Mover templateFor como forSale; mover privados templateCacheSeconds, layoutWithProfileColumns y nextItemColumnOrder sin modificar algoritmos. Dependencias: Sale, ReceiptTemplate, SalesDocumentPolicy, SystemCacheInvalidator y Cache. El método principal continúa devolviendo paper_type, thermal_width_mm, use_branding, layout. En show L300 sustituir:
~~~php
$template = app(\App\Modules\Sales\Services\SaleTemplateService::class)->forSale($sale);
~~~
Eliminar los cuatro métodos originales una vez migrados sus usos; no tocar coilsCacheSeconds. Conservar cache key, versión, branch_id, document_type y perfil de columnas. No añadir Resource/DTO que repita este array estable.

El controlador sigue conteniendo orquestación de emisión; se reconoce SRP pendiente, pero no mezclar una reescritura completa de store/convert con parches financieros. La primera extracción es independiente y determinista. Un servicio transaccional posterior debe ser diseñado después de fijar idempotencia y reglas de crédito; no hacer un «move» que oculte dependencias de Request.

### LIM-03 — Producción: separar validación y ejecución

**Origen:** ProductionOrderController.php L61-L176/L179-L266/L269-L311.
**Nuevos archivos:**
- app/Modules/Production/Services/ProductionOrderService.php.
- app/Modules/Production/Http/Requests/StoreProductionFormulaRequest.php.
- app/Modules/Production/Http/Requests/UpdateProductionStageRequest.php.

Service: complete(array $data, \App\Models\User $actor, InventoryQuantityService $quantities): void; documentar $data como array validado y conservar DB::transaction con todo el cuerpo corregido por CRIT-03/13. Mover consumeInput/movement como privados hasta completar ARQ-01. Sustituciones exactas de transporte en ese cuerpo:
- $request->integer($key) → (int)($data[$key] ?? 0).
- $request->input($key, $default) → $data[$key] ?? $default.
- $request->filled($key) → filled($data[$key] ?? null).
- $request->string($key)->toString() → (string)($data[$key] ?? '').
- $request->safe()->except([...]) → \Illuminate\Support\Arr::except($data,[...]).
- $request->user() → $actor.
El controlador store conserva firma, llama complete($request->validated(),$request->user(),$quantities), invalida caché y devuelve el mismo redirect. No trasladar invalidación fuera/después del flujo sin conservar la garantía existente de outbox si se encuentra aplicada por trabajo concurrente.

StoreProductionFormulaRequest: trasladar literalmente rules, messages vacíos y attributes de L183-L211; authorize equivale a can('production.manage'); validación de branch_id con BranchAccess L213-L215 se conserva en el controlador antes de crear hasta que haya prueba equivalente del request. UpdateProductionStageRequest: trasladar las reglas/attributes L248-L256, authorize devuelve usuario autenticado; mantener las dos guardas de pertenencia/autorización L245-L246 en el controlador, junto con el middleware existente. Cambiar únicamente type-hints de esos dos métodos a los nuevos FormRequests (misma ruta y parámetros order/stage); es un cambio de tipo concreto interno, no de HTTP.

Pruebas: ProductionModuleTest, creación de fórmula sin permiso y etapa de otra orden/sucursal. Mantener completamente las props orders/branches/products/formulas/coils/filters de index.

### ARQ-01 — Inventory como propietario de las mutaciones

Primero corregir comportamientos. Después añadir app/Modules/Inventory/Services/InventoryPostingService.php con SOLO dos operaciones de aplicación: retirar de stock global y retirar de bobina; no diseñar un bus de comandos ni un repositorio abstracto.

API elegida:
~~~
withdrawGlobal(int $branchId, int $productId, string $quantity, int $userId,
               string $sourceType, int $sourceId, string $movementType,
               string $reason, bool $allowNegative = false): InventoryMovement
withdrawCoil(int $branchId, int $productId, int $coilId, string $quantity, int $userId,
             string $sourceType, int $sourceId, string $movementType,
             string $reason): InventoryMovement
~~~
Decisiones fijas:
1. Exigir transacción activa; no confirmar un subpaso independiente. El caso de uso propietario coordina venta/producción/cobro y rollback.
2. quantity decimal positivo a escala 3. Bloquear la fila de recurso, comprobar correspondencia de sucursal/producto y existencias libres. Solo global acepta allowNegative, calculado previamente por CommercialPolicy; Producción pasa false.
3. Guardar antes/después y crear InventoryMovement con los campos existentes en la misma conexión. Mantener sourceType/sourceId y movementType del llamador.
4. No detectar idempotencia por ese triple genérico: hay múltiples movimientos válidos por fuente (OPT-05). El caso de uso mantiene su bloqueo de entidad raíz y la identidad propia del ítem.
5. No eliminar los métodos públicos de SaleInventoryService; conservan sus firmas como orquestación específica de venta/reservas y delegan la mutación. No crear un evento asíncrono para el decremento.
6. Antes de migrar PurchasesService revisar su contrato de «stock ya bloqueado, plan completo antes del primer reverso». No volver a ordenar sus bloqueos después de adquirirlos. La primera entrega solo migra las salidas auditadas de Sales y Production; entradas/reversos quedan en sus flujos probados.

Prueba: rollback de creación de movimiento deja stock igual; mover código entre módulos no cambia estados/tipos ni cantidad de registros. El mapa AST sugería este foco; las escrituras directas confirmadas motivan la extracción.

### ARQ-02 — Envío fiscal durable, fase posterior al parche síncrono

CRIT-09 elimina la red dentro de la transacción sin alterar el retorno síncrono. No basta con añadir SendInvoiceJob::dispatch(...)->afterCommit(): un proceso que termina entre commit y dispatch puede perder el trabajo. Antes de habilitar cola, inspeccionar config/queue.php y los servicios de contexto sandbox (no auditados) y fijar su mecanismo existente.

Contrato de la fase asíncrona: app/Modules/Billing/Jobs/SendSiatInvoiceJob.php implementa ShouldQueue; payload solo invoiceId y descriptor de contexto autorizado por servidor, sin credenciales ni XML. El worker carga la MISMA factura, CUF, gzip_hash y XML; estado validated/voided produce no-op. No ejecutar issueFromSale en un retry. Usar afterCommit para despacho y una fila durable de intención en la misma transacción fiscal para recuperar despachos perdidos. Estado del intento: pending/running/unknown/completed/failed; timeout de red implica unknown, no rechazo ni nueva factura. Un reconciliador consulta estado remoto antes de reenviar.

Esta fase queda expresamente pendiente de verificar el contrato de contexto y el cliente SOAP de consulta; no inventar un método del proveedor ni reutilizar OperationalCacheOutbox para facturas. Sol 6.1 debe finalizar primero CRIT-09/10/11. El cambio de UI a «pendiente» exige contrato/adaptación de controlador de Billing todavía no leído; no entregarlo como sustitución mecánica validada. La implementación síncrona corregida sigue operativa mientras tanto.

### ARQ-03 — Exportaciones y documentos pesados

Rutas de inspección: app/Modules/Exports/Http/Controllers/ExportController.php, Services/ExportDatasetService.php y app/Modules/Printing/Services/PrintRenderService.php, L≈1–fin (pendientes).

Puerta determinista: medir ejecución con 100k registros autorizados y RAM máxima. Si procesa un array completo o supera el presupuesto operativo documentado del servidor, convertir la lectura a lazyById(1000)/chunkById(1000), preservar filas/columnas y stream del escritor. No suponer que un PDF puede escribirse por bloques sin comprobar su librería.

Para trabajo que deba seguir fuera de HTTP, usar un Job ShouldQueue con identidad del solicitante y filtros normalizados, archivo en almacenamiento privado y estado consultable. Reautorizar sucursales al ejecutar y al descargar; la revocación de permisos impide obtener el archivo. Un job fallido no deja un enlace de descarga válido. Revisar los modelos PrintJob existentes antes de añadir una tabla duplicada. Como no se leyeron estos controladores/escritores, aquí se define la verificación y el contrato, no un diagnóstico ni un diff falso.

## UI/UX e idempotencia

El mensaje original se truncó en «estados de carga/spin». Se desarrolla el requisito visible de bloquear envíos, mostrar progreso y prevenir errores. Ningún JSX fue abierto: los siguientes puntos son especificaciones nuevas y verificaciones, no afirmaciones de defectos encontrados.

### UI-01 — Exclusión inmediata de envíos

**Rutas para localizar el submit (L≈1–fin, pendiente):**
- app/Modules/Cash/Resources/Pages/Cash/Index.jsx.
- app/Modules/Payments/Resources/Pages/Payments/Index.jsx.
- app/Modules/Banks/Resources/Pages/Banks/Index.jsx.
- app/Modules/Production/Resources/Pages/Production/Index.jsx.
- app/Modules/Purchases/Resources/Pages/Purchases/Form.jsx.
- app/Modules/Sales/Resources/Pages/Sales/Form.jsx.
- app/Modules/Pos/Resources/Pages/Pos/Index.jsx.

Usar rg -n 'useForm|onSubmit|router\.(post|put|patch|delete)|processing|disabled' en un archivo por vez. Si ya existe exclusión equivalente, no duplicar estado ni crear otro hook. Para formularios Inertia useForm, mantener processing y añadir un useRef(false) local como cerrojo síncrono si actualmente solo existe estado React. Patrón para el inicio del handler:
~~~jsx
event.preventDefault();
if (sending.current || processing) return;
sending.current = true;
~~~
El request conserva su método, ruta, payload, callbacks y manejo de errores. En onFinish:
~~~jsx
sending.current = false;
~~~
Para router.* sin processing, usar además estado isSubmitting para render y el mismo ref para exclusión; no derivar la exclusión únicamente de un render pendiente. Si la construcción del payload lanza sin iniciar request, restablecer ambos en finally/catch de ese tramo. No usar finally que libere el ref inmediatamente después de router.post: la solicitud es asíncrona.

Botón submit: disabled={processing} o disabled={isSubmitting}; el handler cubre la ventana anterior al render. Añadir aria-busy al formulario, texto «Guardando…»/«Procesando…» y región role=status para progreso. El spinner es decorativo aria-hidden. Conservar etiqueta accesible y foco; errores con aria-describedby y role=alert, enfocar primer campo inválido. No depender solo de color; respetar prefers-reduced-motion.

En timeout conservar datos y mostrar «No se pudo confirmar el resultado. Consulta el documento antes de repetir». No limpiar el carrito o formulario en onFinish: limpiar únicamente en éxito confirmado. Enter, doble clic y click+Enter deben disparar una solicitud. Deshabilitar el botón NO evita duplicados entre pestañas, reintentos HTTP o recargas: usar UI-04.

### UI-02 — Una venta confirmada sobrevive al fallo fiscal

**Backend observado:** SaleController::store L236-L238, convert L419-L421 e issueFiscalInvoiceIfRequired L424-L443.
**UI a verificar:** app/Modules/Sales/Resources/Pages/Sales/Show.jsx; app/Modules/Billing/Resources/Pages/Billing/Invoices/Show.jsx; app/Modules/Pos/Resources/Pages/Pos/Index.jsx, L≈1–fin.

No indicar «venta no guardada» si el commit comercial ya ocurrió. Para el flujo HTTP, cambiar issueFiscalInvoiceIfRequired a devolver ?string (privado, sin contrato externo): null en éxito/no requerido; en catch reportar el error y devolver «La venta fue guardada; la facturación requiere revisión». Eliminar el throw posterior al commit incluso si blockSaleIfInvoiceFails=true: ese flag no puede deshacer una venta ya confirmada. En store/convert tomar el aviso, mantener el redirect sales.show del documento existente y añadir flash warning cuando no sea null. El texto success comercial puede conservarse; warning informa el subproceso. Esto es una decisión de comportamiento explícita: se prioriza mostrar el estado real; para bloquear comercialmente por falla fiscal hace falta el nuevo flujo de estados descrito en el primer plano.

La UI de Sales/Show muestra recibo/id de venta, estado fiscal real y enlace a la factura persistida. Acciones «Consultar estado» o «Reintentar envío» actúan sobre la misma factura mediante rutas existentes verificadas en Billing; no inventar rutas ni enviar otra venta. Pending/observed/temporary no se presentan como validación fiscal. Error SOAP no implica que el SIN no haya recibido el documento.

Verificación: simular timeout después del commit y confirmar que el usuario llega a su venta existente y conserva enlace, sin reabrir creación. Al refrescar, saldo/stock/pago aparecen una sola vez. La política de bloqueo fiscal queda identificada como limitación funcional, no escondida en un try/catch.

### UI-03 — Tablas, precisión y accesibilidad

**Rutas:** app/Modules/CashFlow/Resources/Pages/CashFlow/Index.jsx; app/Modules/Reports/Resources/Pages/Reports/Index.jsx; app/Modules/Shared/Resources/Components/Pagination.jsx, L≈1–fin.

Mantener contratos de paginación. Filtros con debounce de 300 ms para búsqueda; cancelar/ignorar respuestas obsoletas; reiniciar page a 1 al cambiar filtros. Estado de carga conserva la altura de tabla para evitar saltos; no limpiar totales mientras llega la misma consulta. Si falla una solicitud, mostrar que los datos visibles corresponden al filtro anterior; no etiquetarlos como actuales.

No sumar importes financieros con Number en el navegador para decidir si el servidor aceptará un pago. La vista puede mostrar una estimación, pero presenta los montos del servidor después de guardar. Inputs guardan cadenas decimales; separador de miles no se envía y coma decimal se normaliza según el formato explícito del campo. No enviar resultados de parseFloat multiplicados. Mantener error de campo recibido del backend, sin redondearlo para ocultar discrepancias.

En listados grandes no duplicar una versión móvil y otra desktop con todos los nodos montados. Encabezados de tabla asociados, controles por teclado, botones con nombre, contraste AA y foco visible. Tablas horizontales en contenedor accesible en móvil. Validación de estas condiciones se hará sobre JSX real; no sustituir diseño/props por una biblioteca nueva.

### UI-04 — Idempotencia persistente para Sales y Payments

Esta es una mejora diseñada, no un hallazgo demostrado en middleware global (no leído). Primero verificar si el proyecto ya dispone de una clave idempotente persistida; si existe, adaptar el contrato siguiente a ella y no crear infraestructura paralela.

**Si no existe:** nuevo modelo app/Modules/Shared/Models/OperationIdempotency.php y migración database/migrations/2026_10_21_000002_create_operation_idempotencies.php. En la conexión operativa activa, tabla operation_idempotencies:
- id bigint autoincrement; user_id unsignedBigInteger; operation string(40); idempotency_key uuid; request_hash char(64).
- result_type string(40) nullable; result_id unsignedBigInteger nullable; completed_at timestamp nullable; timestamps.
- índice único [user_id,operation,idempotency_key], nombre operation_idempotency_unique. No FK polimórfica de result_id; no guardar respuestas completas ni credenciales.
- operation solo constantes servidor sales.store o payments.store. El contexto queda aislado por la misma conexión/BD operativa; no llevar esta tabla a una BD central compartida con sandboxes.

Añadir header opcional Idempotency-Key con UUID validado; opcional conserva compatibilidad de clientes actuales. Las nuevas UIs lo envían siempre. La falta de header conserva el flujo antiguo, sin prometer protección contra reintentos.

Algoritmo dentro de la MISMA transacción de negocio:
1. FormRequest autoriza/valida. Canonicalizar array validado de negocio: ordenar claves asociativas recursivamente, conservar orden de listas; JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR; sha256. Excluir solo la clave técnica si se transporta en body.
2. Insertar fila de identidad con campos generados/validados. Usar creación tolerante únicamente a conflicto del índice único (createOrFirst sobre modelo Eloquent en Laravel 12); no capturar cualquier QueryException como duplicado.
3. Recuperar la fila con lockForUpdate en la conexión activa. Si hash difiere, responder error de validación idempotency_key «La clave ya corresponde a otra operación».
4. Si completed_at existe, reautorizar acceso al recurso y devolver el mismo redirect/flash con result_id; no repetir pagos, inventario, evento, aprobación ni emisión fiscal. Nunca confiar en result_id suministrado por cliente.
5. Si no está completada, ejecutar el caso de uso; guardar result_type=sale/sale_payment, result_id real y completed_at antes del commit.
6. Un error revierte fila y negocio juntos. Dos solicitudes con la misma identidad esperan al unique/bloqueo; la segunda reutiliza el resultado. No confirmar una fila pending separada que quede huérfana al fallar.
7. Expiración: no borrar identidades completadas de operaciones financieras automáticamente; su eliminación volvería inseguro un retry tardío.

Sales: integrar alrededor del callback store L118-L234 y omitir issueFiscalInvoiceIfRequired en replay; el resultado original puede tener facturación pendiente, pero su venta no se recrea. Payments: integrar store L70-L92 y conservar redirect back + payment id original. No meter red SIAT dentro de este bloque.

Frontend: generar crypto.randomUUID() al empezar un nuevo intento lógico; conservar esa identidad al reintentar payload idéntico tras timeout. Cambiar clave después de éxito confirmado o de una corrección tras rechazo definitivo de validación. Tras respuesta de resultado incierto, consultar antes de cambiar payload; no generar una clave nueva solo porque terminó el spinner. La persistencia entre recargas debe usar el almacén de borrador ya existente si lo hay; almacenar solo identificador/contexto técnico, no introducir datos personales en localStorage.

Prueba: 10 solicitudes concurrentes, mismo usuario/key/hash → una venta/pago y un conjunto de efectos; misma key/payload distinto → error sin escritura; usuarios distintos no comparten resultados; replay después de revocar acceso → 403; rollback permite retry válido; cambio de sandbox nunca revela un recurso de otra conexión.

## Entrega y verificación por lote

1. Antes de implementar guardar estado Git; no sobrescribir trabajo ajeno. Cada lote lee solo lo necesario y confirma los contratos de archivos pendientes.
2. Lote 1: CashModuleTest, PaymentsModuleTest, BanksModuleTest, CashFlowModuleTest, InventoryMovementsModuleTest, AuditModuleTest y pruebas nuevas de concurrencia/decimal.
3. Lote 2: SalesModuleTest, ProductionModuleTest, PurchasesModuleTest, PurchaseVoidConsistencyTest, PurchaseVoidConcurrencyTest, SiatBillingModuleTest; sumar suites existentes del módulo pendiente solo cuando se modifique.
4. Lote 3: ejecutar tests del módulo intervenido y caracterizar props/respuestas antes/después. No afirmar éxito si el filtro selecciona cero pruebas.
5. Concurrencia: dos conexiones/procesos y barrera controlada, motor y aislamiento equivalentes al destino. No usar sleep arbitrario ni SQLite para demostrar bloqueo de filas. Verificar efecto final, no solo HTTP 200.
6. UI: pruebas con latencia simulada, doble clic/Enter, validación, 403, timeout ambiguo, recarga, dos pestañas y navegación móvil/teclado. UI no reemplaza autorización ni idempotencia backend.
7. No activar job asíncrono sin recuperar el contexto correcto, probar reintentos y demostrar que el trabajo pendiente sobrevive a una caída. No añadir Redis por defecto: no resuelve carreras de escritura contable.
8. Consultas: almacenar resultados de EXPLAIN, cantidad de queries y memoria/latencia antes/después en el registro de implementación del ejecutor. Esta auditoría no ejecutó benchmarks ni certifica índices ausentes.

## Comprobaciones realizadas durante la auditoría

- Los 25 archivos PHP seleccionados superaron php -l; esto valida la sintaxis del código existente, no los parches propuestos.
- Se comprobaron los nueve campos de plantilla en las 18 intervenciones críticas y la distribución única de los 29 módulos en 9 + 9 + 11.
- Comparación SHA-256 de 1576 archivos enumerados fuera de .git, vendor, node_modules y graph.json: sin modificaciones ni eliminaciones; únicamente aparecieron los dos planos autorizados. No se interpreta esta enumeración como lectura semántica de sus contenidos.
- No se ejecutaron pruebas de aplicación, migraciones, benchmarks ni comunicación SIAT. La verificación funcional queda prescrita para el ejecutor.

## Límites explícitos de la evidencia

Los 25 archivos permiten diffs concretos en el core. El inventario de 29 módulos permite ordenarlos y definir sus pruebas, pero no permite atribuir fallos, líneas exactas ni contratos ocultos a los restantes sin leerlos. Por eso las tareas de módulos pendientes son puertas de inspección, con alcance acotado y decisiones fijadas donde hay evidencia. ARQ-02/03 quedan condicionadas a esos contratos; no son parches listos para aplicar a archivos nunca auditados. El ejecutor no debe completar huecos inventando métodos, rutas, permisos, columnas o estados.

Los dos documentos constituyen la única salida escrita autorizada de esta auditoría. No se modificó código, configuración ni esquema, y no se probaron las correcciones propuestas en ejecución.
