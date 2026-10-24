# Guía de implementación de lotes para SOL MEDIO

Fecha: 23 de octubre de 2026. Proyecto: Fabrica_Calmina_Aroma. Stack: Laravel 12, PHP 8.2+, React 18, Inertia 2, JSX, Tailwind CSS 3 y MySQL/MariaDB.

Este documento prescribe la implementación; no contiene cambios de negocio ejecutados. Las rutas son relativas a la raíz del repositorio. Los archivos identificados como «nuevo» todavía deben crearse.

**Aclaración del propietario que prevalece sobre cualquier interpretación anterior: cada bobina tiene su propio lote y otra bobina tiene otro lote distinto. Una bobina equivale a un lote físico; nunca se agrupan varias bobinas bajo un lote.**

## 1. Diagnóstico del Estado Actual

### 1.1. Reconocimiento y decisiones de reutilización

| Área | Código y esquema inspeccionado | Reutilización y brecha comprobada |
| --- | --- | --- |
| Perfil | app/Modules/SystemSuperadmin/Services/BusinessProfileConfiguration.php, ActiveBusinessProfile.php y BusinessProfilePresetFactory.php | configuration tiene schema_version=2, capabilities.uses_lots y inventory.lot_tracking_optional. normalized() mezcla defaults, pero no valida tipos. Varios presets ya tienen uses_lots=true; eso no significa que exista contabilidad de lotes. |
| Gobierno | app/Modules/SystemSuperadmin/Http/Controllers/BusinessProfileController.php y routes/web.php | El panel usa auth, verified y system_superadmin. Aplican cambios reales saveAndApply(), updateActiveProfile(), apply() y restore(). Hay borradores, versiones, presets y previsualización. Todas las vías de activación necesitan la misma guarda de transición. |
| Producto | app/Modules/Inventory/Models/Product.php | Ya existe requires_lot, con cast boolean y persistencia permitida. No crear manages_lots, tracks_lots ni otra columna equivalente. inventory_tracking_mode distingue global y coil. |
| Existencias | app/Modules/Inventory/Models/ProductBranchStock.php | available_meters y reserved_meters son DECIMAL(18,3), con UNIQUE(branch_id, product_id). Los nombres históricos también representan cantidades de la unidad base de productos no medidos en metros. El modelo actualmente extiende Model y sus accessors usan float. |
| Bobina/lote físico | app/Modules/Inventory/Models/ProductCoil.php | Ya contiene producto, sucursal, barcode, lot_number, initial_meters, available_meters, initial_kg, status y SoftDeletes. Es AuditableModel. lot_number es texto nullable, indexado pero no único; barcode es nullable y único. No tiene voided_at ni metadatos de anulación. |
| Kardex | app/Modules/Inventory/Models/InventoryMovement.php | AuditableModel, inmutable, con product_coil_id, fuente polimórfica y delta/antes/después. Reutilizarlo; nunca editar movimientos históricos para corregir códigos o saldos. |
| Reservas | app/Modules/Inventory/Models/InventoryReservation.php | AuditableModel; product_coil_id, sale_id, consumed_sale_id, customer_qr_order_id, meters, status y fechas. Reutilizar esta tabla, no crear reservas paralelas. |
| Productos/UI | ProductWorkflowPolicy.php, StoreProductRequest.php, UpdateProductRequest.php, ProductController.php y Products/ProductFormFields.jsx | lotsEnabled() hace uses_lots OR lot_tracking_optional. El formulario ya contiene requires_lot, pero puede mostrarlo deshabilitado. No existe servicio de producto que proteja transiciones de trazabilidad con stock. |
| Compras | app/Modules/Purchases/Http/Controllers/PurchaseController.php y PurchaseOrderController.php | La recepción está en los controladores, no en PurchaseService. Hoy global incrementa ProductBranchStock y coil crea una ProductCoil nueva sin incrementar el agregado general. lotOptions reutiliza textos, no identidades de lote. |
| Anulación de compra | app/Modules/Purchases/Services/PurchaseService.php y PurchaseVoidAuthorization.php | PurchaseService anula compras con bloqueo y outbox. Para bobinas exige saldo íntegro, sin reservas ni consumos, luego marca status=voided y hace soft delete. Las compras y recepciones ya tienen voided_at. Su autorización operativa no equivale a la autorización administrativa de la entidad lote. |
| Ventas | app/Modules/Sales/Http/Controllers/SaleController.php, Services/SalesWorkflowPolicy.php y Listeners/ProcessSaleNoteIssued.php | El listener exige una transacción abierta y delega stock a SaleInventoryService. Hay descuento al emitir, al pagar o al despachar. Convertir cotización puede autoelegir bobinas; no debe hacerlo para los lotes de esta funcionalidad. |
| Reservas de cotización | app/Modules/Sales/Services/SaleInventoryService.php | Cotizar no reserva automáticamente hoy. consumeReservationsForQuotation() consume todas las reservas de la cotización sin correspondencia precisa con cada línea. Además, stock global y bobina son rutas excluyentes. |
| POS | app/Modules/Pos/Http/Controllers/PosController.php, Services/PosWorkflowPolicy.php y Resources/Pages/Pos/Index.jsx | Actualmente excluye productos coil y publica a sales.store. No hay un segundo motor de stock POS que deba crearse. |
| Roles | app/Support/SystemRoles.php, app/Support/AuthSessionCache.php, app/Providers/AppServiceProvider.php y app/Modules/Users | El rol oculto real es sistemasuperadmin. El administrador de negocio existente es superadmin, confirmado en DatabaseSeeder y ClientSetupService. No existe un rol canónico llamado Admin. Gate::before concede bypass a ambos. |
| Soporte | app/Support/BranchAccess.php, UiCatalogCache.php, SystemCacheInvalidator.php y OperationalCacheOutbox.php | Hay filtros por sucursal, catálogos versionados e invalidación durable tras commit. La caché nunca es autoridad para reservar o vender. |
| Precisión | app/Modules/Finance/Support/Decimal.php y app/Modules/Inventory/Services/InventoryQuantityService.php | Decimal solo ofrece round(string, int). InventoryQuantityService y varias rutas actuales usan float. La ruta nueva debe usar strings, BCMath y Decimal::round, sin inventar métodos inexistentes. |

Migraciones base revisadas: database/migrations/2026_06_25_210000_create_core_business_tables.php, 2026_06_25_210100_create_transaction_tables.php, 2026_06_26_011000_create_inventory_reservations_table.php, 2026_06_26_012000_create_sale_returns_table.php, 2026_06_26_014000_create_delivery_notes_table.php, 2026_06_27_001000_create_purchase_order_receipts_table.php, 2026_07_31_110000_extend_products_for_flexible_catalog_engine.php, 2026_08_01_000000_make_product_coil_identifiers_nullable.php y las dos migraciones de anulación de compras del 19 de octubre.

### 1.2. Arquitectura decidida después de la aclaración sobre bobinas

1. **Reutilizar ProductCoil y product_coils como la entidad persistente de lote/unidad física. No crear product_lots ni una relación de lote agrupador con múltiples bobinas.** Para productos global con requires_lot, esa misma estructura representa una partida de mercancía; no se exige peso ni barcode de bobina.
2. Conservar los IDs y las FK product_coil_id existentes. En el contrato HTTP/React, llamar a esa identidad lot_id y mapearla explícitamente a product_coil_id en un adaptador del módulo Inventory. lot_id es el ID primario de ProductCoil, no el código ni un ID de otra tabla. No guardar dos FK redundantes lot_id/product_coil_id.
3. Agregar lot_code como código canónico de los registros incorporados al control nuevo. lot_number queda como compatibilidad de la entidad legacy y como texto histórico en los documentos existentes. No convertir textos iguales en una misma identidad ni fusionar bobinas.
4. Una bobina nueva siempre crea un registro y un código nuevos. Seleccionar lote existente significa recibir cantidad de la misma identidad ya registrada; no significa registrar otra bobina bajo su código. Si la recepción identifica otra bobina/barcode, rechazar la reutilización y exigir lote nuevo.
5. En productos gestionados se actualizan juntos ProductBranchStock y ProductCoil. No hay un tercer saldo de bobina: **el saldo de la bobina es el saldo de su lote**. Las filas genéricas y las bobinas no se suman dos veces en informes.
6. Preservar la ruta legacy cuando la regla nueva está apagada. No activar trazabilidad real por el mero hecho de encontrar requires_lot=true en datos antiguos. Ese campo pudo usarse solo como metadato.

Esta reutilización requiere ampliar consultas que actualmente suponen que toda ProductCoil pertenece a un producto coil. A cambio, conserva historial y FK sin duplicar una entidad que el negocio identifica como la misma cosa.

### 1.3. Invariantes de cantidades

Usar unidad base y escala 3. Para un producto incorporado completamente al control:

~~~text
S = product_branch_stocks.available_meters
R = product_branch_stocks.reserved_meters
L_i = product_coils.available_meters del lote i
r_i = SUM(remaining_meters de reservas activas del lote i)

S = SUM(L_i), incluyendo saldo físico bloqueado de lotes anulados
R = SUM(r_i)
libre_lote_i = L_i - r_i
vendible = SUM(libre_lote_i de lotes activos)
~~~

available_meters significa saldo físico; no restar reservas dos veces. Una reserva vence operativamente cuando una transacción la libera, no simplemente porque pasó expires_at. Ningún lote admite saldo negativo, aunque el perfil permita negativos para productos no gestionados.

## 2. Cambios en Base de Datos, Modelos y Esquema de Perfil

### 2.1. Migraciones aditivas exactas

Crear las siguientes migraciones nuevas, en este orden. No reescribir migraciones ya aplicadas. Los nombres propuestos no existen en el reconocimiento.

**A. database/migrations/2026_10_23_000001_extend_product_coils_for_managed_lots.php**

En product_coils agregar:

| Campo/índice | Definición |
| --- | --- |
| lot_code | VARCHAR(80), nullable para registros legacy, charset ASCII/collation ascii_bin. Obligatorio en el servicio para todo lote gestionado nuevo. |
| notes | TEXT nullable. |
| voided_at | TIMESTAMP nullable. |
| voided_by | UNSIGNED BIGINT nullable, FK users.id, restrictOnDelete. |
| void_reason | TEXT nullable. |
| coils_product_branch_lot_code_uq | UNIQUE(product_id, branch_id, lot_code), incluyendo anulados y soft-deleted. |
| coils_branch_product_void_id_idx | INDEX(branch_id, product_id, voided_at, id). |

Reutilizar available_meters e initial_meters DECIMAL(18,3); no crear otro contador de stock ni otro contador reservado. Mantener initial_meters como cantidad de la primera entrada; las entradas posteriores se prueban con el kardex, sin sobrescribir ese dato.

Cambiar las FK de product_coils.product_id y branch_id de cascadeOnDelete a restrictOnDelete, conservando columnas y datos. Prohibir también soft delete y forceDelete para lotes gestionados desde aplicaciones, observers y servicios. La baja del lote nuevo se representa exclusivamente con voided_at.

No añadir UNIQUE a lot_number: contiene textos legacy nulos, libres y repetidos. lot_code nullable permite desplegar sin modificar esas identidades. No rellenarlo agrupando automáticamente lot_number. Al crear/editar un lote gestionado, escribir lot_code y su espejo de compatibilidad lot_number en la misma operación auditada; ningún endpoint puede modificar lot_number por separado en esos registros.

**B. database/migrations/2026_10_23_000002_preserve_lot_references_and_snapshots.php**

Conservar y cambiar a restrictOnDelete las FK product_coil_id existentes en purchase_items, purchase_order_receipt_items, sale_items, sale_return_items, delivery_note_items, inventory_movements, inventory_reservations e inventory_adjustments. No renombrarlas ni rellenarlas de nuevo. Inspeccionar el nombre efectivo de cada constraint antes de eliminarlo y recrearlo.

Agregar:

- purchase_order_items.product_coil_id: UNSIGNED BIGINT nullable, índice y FK restrictiva a product_coils.id. Es una elección propuesta; la recepción decide y persiste la elección definitiva.
- lot_code_snapshot VARCHAR(80) nullable en purchase_order_receipt_items, sale_items, sale_return_items, delivery_note_items e inventory_movements.
- purchase_items.lot_number y purchase_order_items.lot_number ya existen: utilizarlos como snapshot/propuesta, sin duplicarlos con otra columna.

En los documentos, el snapshot se fija al emitir y no cambia si el administrador corrige el código del lote. La relación por ID ofrece el código actual. Los detalles de devolución/despacho heredan el snapshot del documento original.

Las FK garantizan identidad, no coherencia producto/sucursal: el servicio debe comprobar la coincidencia con el producto de la línea y la sucursal del documento. No aceptar un branch_id independiente en detalles para sustituir el de la cabecera.

**C. database/migrations/2026_10_23_000003_extend_inventory_reservations_for_lot_commitments.php**

En inventory_reservations agregar:

- sale_item_id nullable, FK restrictiva a sale_items.id: línea que originó la reserva.
- committed_sale_item_id nullable, FK restrictiva a sale_items.id: línea de venta que conserva el compromiso hasta su salida física.
- remaining_meters DECIMAL(18,3), primero nullable durante la migración de representación y finalmente NOT NULL DEFAULT 0. Rellenar con meters si status=active y con cero en otro caso; no modificar meters ni el estado original.
- Índices reservations_lot_status_id_idx(product_coil_id, status, id) y reservations_commitment_status_idx(committed_sale_item_id, status).

meters conserva la cantidad original. remaining_meters permite consumo/liberación parcial. Una reserva activa debe tener remaining_meters>0; al agotarse pasa a consumed o released según la operación. Si parte se consumió y se libera el resto, la auditoría y los movimientos conservan ambas acciones; no reescribir la cantidad original.

**D. database/migrations/2026_10_23_000004_extend_inventory_movements_for_lot_balances.php**

En inventory_movements agregar:

- lot_quantity_before y lot_quantity_after: DECIMAL(18,3) nullable.
- reverses_movement_id nullable, FK restrictiva a inventory_movements.id, indexada.
- idempotency_key VARCHAR(191) nullable, índice UNIQUE movements_idempotency_key_uq.

En movimientos gestionados, meters_before/after representa el agregado de sucursal y lot_quantity_before/after el lote. meters_delta es un único delta físico aplicado a ambos. No insertar dos movimientos y luego sumarlos como dos entradas. En legacy no reinterpretar ni recalcular columnas históricas.

Las claves idempotentes se forman con IDs persistidos de evento/línea, por ejemplo lot-void:ID, sale-out:SALE_ITEM_ID, delivery-out:DELIVERY_ITEM_ID y return:RETURN_ITEM_ID:MOVEMENT_ID. Si una operación requiere varias líneas, cada movimiento tiene su clave propia. No usar solo product_id o lot_id para identificar todas las ventas.

**E. database/migrations/2026_10_23_000005_create_inventory_lot_rule_locks_table.php**

Crear inventory_lot_rule_locks con id UNSIGNED TINYINT PRIMARY KEY e insertar una única fila id=1. Es una fila de coordinación transaccional, no otra entidad de inventario ni otra configuración de negocio; no necesita modelo Eloquent.

Los escritores de inventario toman sharedLock sobre esta fila dentro de la transacción. Aplicar/restaurar/desactivar un perfil toma lockForUpdate sobre la misma fila antes de leer el perfil activo y comprobar saldos. Así una activación no compite con una entrada global sin lote ni depende de una fila de BusinessProfile que se archiva y sustituye. La migración debe dejar creada la fila; si falta, el servicio falla de forma cerrada, no intenta crearla después de bloquear inventario.

Los down eliminan primero índices/FK dependientes y después columnas/tablas propias, sin purgar documentos ni lotes. No ejecutar un rollback de estas migraciones sobre datos operativos ya gestionados: exige previamente un procedimiento de conservación de historial.

### 2.2. Modelos y contratos

- Extender app/Modules/Inventory/Models/ProductCoil.php con campos nuevos, casts datetime, relación voidedBy, relaciones movimientos/reservas y scopes de lotes gestionados. isVoided se deriva de voided_at; status=depleted no equivale a anulado.
- Extender Product.php con una relación descriptiva lots() hacia ProductCoil, manteniendo coils() por compatibilidad. No crear un segundo modelo ProductLot apuntando a los mismos datos.
- Añadir relación lot() en detalles/reservas/movimientos, utilizando explícitamente la FK product_coil_id. El serializador expone lot_id y lot_code. No hacer asignación masiva de lot_id sobre una columna inexistente.
- Convertir a app/Modules/Shared/Models/AuditableModel los modelos modificados que hoy extienden Model: Inventory/Models/ProductBranchStock.php; Purchases/Models/PurchaseItem.php, PurchaseOrderItem.php y PurchaseOrderReceiptItem.php; Sales/Models/SaleItem.php, DeliveryNoteItem.php y SaleReturnItem.php.
- Product, ProductCoil, InventoryMovement, InventoryReservation, InventoryAdjustment, BusinessProfile, BusinessProfileDraft, BusinessProfileVersion y BusinessProfilePreset ya son auditables. Mantener esa base y usar save()/update() de instancias para cambios de negocio, no actualizaciones masivas que omitan eventos/auditoría.
- No modificar User para cambiar su clase base: la autorización se centraliza en SystemRoles y servicios.

### 2.3. Configuración persistida y regla efectiva

Extender los defaults y la fábrica de presets con este fragmento, sin borrar el resto del JSON ni cambiar schema_version=2 para una extensión aditiva:

~~~json
{
  "inventory": {
    "lot_tracking_enabled": false
  },
  "sales": {
    "reserve_lots_on_quotation": false
  }
}
~~~

capabilities.uses_lots sigue expresando la capacidad disponible; inventory.lot_tracking_enabled es la activación explícita del control nuevo. **No son dos activaciones alternativas.** La resolución única es:

~~~text
enabled = modules.inventory
          AND capabilities.uses_inventory
          AND capabilities.uses_lots
          AND inventory.lot_tracking_enabled

required(product) = enabled AND product.is_inventory_item AND product.requires_lot
~~~

inventory.lot_tracking_optional se conserva únicamente por compatibilidad del JSON; deja de habilitar este flujo. Sustituir el OR actual de ProductWorkflowPolicy por la política canónica. Todos los presets existentes reciben false para el campo nuevo; no inferir true a partir de uses_lots, requires_lot o inventory_tracking_mode=coil.

Validar booleanos verdaderos/0/1 conforme a Form Request, sin convertir la cadena "false" mediante un cast PHP que resulte true. normalized() debe normalizar estas claves aun para versiones/presets antiguos. Un JSON inconsistente que active el control sin módulo/capacidades se rechaza al guardar/aplicar; no aceptar reglas divergentes de lotes en compras, ventas y POS.

reserve_lots_on_quotation es configurable exclusivamente por SystemSuperadmin. La selección del lote en una cotización gestionada siempre es obligatoria; la reserva automática es opcional. Si se activa, exigir módulo/capacidad de reservas. El control de compromisos de una venta con salida diferida es obligatorio, aunque la reserva opcional de cotizaciones esté apagada.

ActiveBusinessProfile::payload() y draftPayload() ya exponen inventory y sales. Agregar a navigationPayload() un contrato mínimo derivado lotTracking con enabled y reserveOnQuotation, sin exponer todo el JSON de sistema. Los summary() de ProductWorkflowPolicy, PurchaseWorkflowPolicy, SalesWorkflowPolicy y PosWorkflowPolicy deben delegar en la misma política.

## 3. Backend — Paso a Paso por Módulo

### 3.1. Orden de implementación y servicios comunes

Implementar primero migraciones/modelos, política y servicios transaccionales; después conectar controladores; finalmente JSX. No implementar descuentos separados en cada pantalla.

Crear estos archivos en app/Modules/Inventory:

| Archivo nuevo | Responsabilidad |
| --- | --- |
| Services/LotTrackingPolicy.php | Resolver la regla efectiva y las transiciones de trazabilidad. Lecturas de UI pueden usar ActiveBusinessProfile; escrituras deben usar configuración persistida y normalizada, releída bajo la guarda transaccional. |
| Support/LotPayloadMapper.php | Traducir lot_id HTTP a product_coil_id interno y serializar el contrato de lotes. Si un cliente manda ambos con IDs distintos, rechazar. |
| Services/LotInventoryService.php | Planificar bloqueos; validar identidad/estado/saldo; aplicar entradas, salidas, ajustes y restituciones simultáneamente al agregado y al lote; escribir el kardex una sola vez. |
| Services/InventoryReservationService.php | Reservar, transferir compromiso, consumir parcialmente, liberar y expirar reservas con el mismo protocolo de bloqueo. |
| Services/ProductLotService.php | Crear/consultar lotes y ejecutar edición/anulación administrativa; reutiliza LotInventoryService para cantidades. |
| Services/ProductInventoryConfigurationService.php | Proteger cambios de requires_lot, inventory_tracking_mode, is_inventory_item y sucursales del producto. Compartido por Inventory y alta rápida de compras. |
| Services/LotAuthorization.php | Autorización administrativa directa por SystemRoles y acceso a sucursal; reutilizable por requests y comandos. |

Agregar métodos exactos basados en strings a Inventory/Services/InventoryQuantityService.php y migrar todos los consumidores del flujo gestionado. No pasar resultados exactos por sus métodos float actuales. Usar bcadd, bcsub, bcmul, bcdiv, bccomp y Finance/Support/Decimal::round; escala de cantidad 3, costo/precio 4 e importes monetarios según el esquema existente. Mantener precisión intermedia suficiente en conversiones y redondear una sola vez en la frontera de persistencia. Validar rango DECIMAL(18,3)/(18,4) y entradas sin notación exponencial.

**Protocolo transaccional obligatorio:**

1. Entrar a DB::transaction, con reintentos acotados para deadlock cuando el cuerpo sea idempotente. No hacer llamadas externas dentro.
2. Bloquear la fila inventory_lot_rule_locks.id=1: compartida para operaciones y exclusiva para activar/cambiar perfil. Esto debe ocurrir antes de otros bloqueos, también en operaciones globales que podrían competir con una activación.
3. Leer el perfil activo persistido, sin confiar en caché ni en preview. Bloquear cabeceras/detalles de documentos relacionados en orden estable; cuando intervienen venta y cotización, bloquear ambas por ID ascendente antes de procesarlas.
4. Planificar y adquirir los bloqueos financieros necesarios antes del inventario, con el orden común de los servicios existentes. Adaptar PurchaseService, PaymentController y la orquestación POS para que una ruta no entre con caja/banco bloqueados y otra los solicite después del stock. Reentrar a un bloqueo ya adquirido es válido; adquirirlo por primera vez en orden inverso no lo es.
5. Bloquear productos por ID ascendente; filas ProductBranchStock por (branch_id, product_id); lotes ProductCoil por ID; reservas por ID, usando lockForUpdate. Agrupar todas las líneas antes de comprobar cantidades. No ordenar solamente dentro de cada línea de un foreach.
6. Para stock inexistente, crear la fila bajo el bloqueo del producto y su UNIQUE(branch_id, product_id), luego bloquearla. Para lote nuevo, normalizar el código antes; la fila de producto serializa la creación y el UNIQUE es la última defensa. Nunca atrapar una colisión para devolver éxito de una compra distinta.
7. Revalidar permisos, perfil, asociación producto/sucursal, is_enabled del stock, estado, reservas y compromisos. Aplicar cantidades, auditoría, movimientos y outbox en la misma transacción.
8. Después del commit se invalida la caché. Un fallo revierte stock, documentos, reservas, auditoría y pendiente de invalidación.

La misma regla de bloqueos aplica a liberación/expiración de reservas. El patrón actual reserva→stock no puede coexistir con stock→lote→reserva. Un servicio de stock no debe bloquear después una venta que otro flujo bloquea antes; el llamador aporta todos los documentos ya bloqueados. Los identificadores descubiertos preliminarmente se revalidan después de adquirir los bloqueos.

### 3.2. SystemSuperadmin: configuración y reglas

1. Modificar BusinessProfileConfiguration.php y BusinessProfilePresetFactory.php según 2.3. Mantener defaults seguros para los presets ya instalados y los que se creen desde la fábrica.
2. Modificar ActiveBusinessProfile.php para exponer la regla efectiva normalizada y el resumen mínimo de navegación. Inyectar LotTrackingPolicy en las políticas ProductWorkflowPolicy, PurchaseWorkflowPolicy, SalesWorkflowPolicy y PosWorkflowPolicy. Evitar copiar OR/AND en cada módulo o capturar un perfil obsoleto en un singleton.
3. En BusinessProfileController.php::validatedPayload() declarar explícitamente las nuevas claves booleanas. El wildcard actual configuration.inventory.* solo admite booleanos: esta propuesta utiliza campos planos y no requiere introducir un objeto inventory.lots.
4. Mantener routes/web.php bajo system_superadmin y agregar comprobación directa del rol oculto en la validación/controlador que guarda reglas. Usar hasRole(SystemRoles::SYSTEM_SUPERADMIN), no isSuperAdministrator() ni un permiso que pueda recibir el administrador de negocio.
5. Crear app/Modules/SystemSuperadmin/Services/BusinessLotRuleTransitionGuard.php. Invocarlo dentro de la transacción de saveAndApply(), updateActiveProfile(), apply() y restore(), después de bloquear la fila de coordinación y releer el perfil. También cubrir cambios de capabilities.uses_lots, modules.inventory y capabilities.uses_inventory que produzcan una desactivación efectiva.
6. En updateActiveProfile() dejar de depender del perfil capturado antes de la transacción. En restore() aplicar también normalización, compatibilidad y guarda de lotes. force=true jamás omite las invariantes.
7. Extender BusinessProfileCompatibilityValidator.php y BusinessProfileActivationChecklist.php con mensajes previos útiles, pero repetir las comprobaciones definitivas bajo bloqueo. Rechazar inventory_discount_timing=manual mientras el control de lotes esté activo: no hay una ruta de salida física manual completa en esta entrega. Soportar sale_note, payment y delivery según 3.5.
8. Borradores y presets pueden guardarse sin modificar existencias; comprobar preparación de datos al aplicarlos. Una previsualización de BusinessProfilePreviewContext no habilita escrituras reales bajo reglas de un borrador. Si hay preview y no sandbox completo aislado, rechazar la escritura de inventario con mensaje para salir de preview. En sandbox completo usar su conexión aislada y la misma guarda.
9. Los cambios efectivos de perfil/versiones deben seguir siendo auditables. Programar OperationalCacheOutbox con un scope nuevo business_profile dentro de la transacción, según sección 5.

### 3.3. Inventory: productos, consulta, edición y anulación

**Productos:**

- Modificar Http/Requests/StoreProductRequest.php y UpdateProductRequest.php. requires_lot solo puede activarse si la regla efectiva lo permite y el producto es inventariable. Revisar los defaults actuales de alquiler/vencimiento: no pueden forzar el checkbox mientras el control esté apagado.
- Con regla apagada, omitir el campo en la UI; en alta, su valor efectivo es false. Si llega true por un payload manipulado, error de validación. En edición con campo ausente, conservar metadatos históricos; no borrarlos por omisión del formulario.
- ProductController.php::store/update debe delegar las transiciones en ProductInventoryConfigurationService dentro de su transacción. Bloquear el producto y sus stocks/lotes/reservas en todas las sucursales, no solo la visible.
- Incorporación inicial: permitir activar requires_lot con stock/reservas cero, o si los saldos ya están íntegramente identificados y conciliados bajo la misma representación gestionada. No inventar lotes para stock global existente. Las bobinas legacy con stock y lot_code null bloquean la incorporación hasta su conciliación explícita.
- La migración de un negocio con stock previo requiere un inventario físico y asignación individual auditada de códigos a cada bobina; nunca fusionar IDs repetidos ni sumar otra entrada de mercancía. Esta guía no autoriza ejecutar esa conciliación sobre la base operativa. La alternativa inicial segura es incorporar productos nuevos o productos agotados.
- Bloquear desmarcar requires_lot, cambiar tracking/unidad base, quitar is_inventory_item o deshabilitar la sucursal si hay stock trazado, reservas o compromisos pendientes. No convertir una bobina con historia gestionada en producto global mediante una actualización del formulario.

**Autorización:**

Agregar SystemRoles::BUSINESS_ADMIN = 'superadmin' en app/Support/SystemRoles.php. LotAuthorization debe exigir hasAnyRole([SystemRoles::BUSINESS_ADMIN, SystemRoles::SYSTEM_SUPERADMIN]) para editar/anular. Aplicar BranchAccess, respetando su alcance ya definido para administradores. No crear ni asignar un nuevo rol llamado Admin y no modificar el rol oculto en Users.

Crear app/Modules/Inventory/Policies/ProductCoilPolicy.php y registrarla expresamente en AppServiceProvider. Exponer updateLot y voidLot. Como Gate::before da bypass, repetir el chequeo directo de rol y las validaciones de estado/sucursal en Form Request y servicio. El bypass de un administrador no debe omitir invariantes de stock o perfil.

**Rutas y archivos nuevos:**

- Http/Controllers/ProductLotController.php.
- Http/Requests/Lots/UpdateProductLotRequest.php.
- Http/Requests/Lots/VoidProductLotRequest.php.
- Http/Requests/Lots/ListProductLotOptionsRequest.php.
- Http/Middleware/EnsureLotAdministrator.php: comprobación directa de LotAuthorization para las dos mutaciones administrativas; registrar su alias lot_admin en bootstrap/app.php.

En app/Modules/Inventory/routes/web.php registrar:

| Método/ruta | Nombre y autorización |
| --- | --- |
| GET /inventory/products/{product}/lots | inventory.lots.index; permiso inventory.products.view; lista/historial por sucursales autorizadas. |
| GET /inventory/products/{product}/lots/options | inventory.lots.options; permiso del contexto de compra/venta/POS más regla activa. Registrar antes de rutas con parámetros genéricos. |
| PATCH /inventory/products/{product}/lots/{lot} | inventory.lots.update; administrador directo, Policy y Form Request. |
| POST /inventory/products/{product}/lots/{lot}/void | inventory.lots.void; administrador directo, Policy y Form Request. No crear ruta DELETE. |

Usar binding de ProductCoil y comprobar su product_id contra {product}; no confiar solo en el ID de ruta. Las rutas de operación usan business_feature:inventory y business_capability:uses_lots, además de la regla efectiva en servicio. Mantener una consulta de historial de solo lectura bajo permisos aunque la regla nueva esté apagada.

Para PATCH/POST void, ejecutar auth y lot_admin antes de los filtros de módulo/capacidad, de modo que un operador reciba 403 incluso si estos filtros responderían 404. Si hace falta, declarar ese grupo fuera del grupo inventory existente y volver a aplicar sus protecciones en ese orden. Para opciones, exigir purchases.manage en contexto compra/recepción y sales.manage en contexto cotización/venta/POS, junto al módulo correspondiente; un vendedor no necesita recibir inventory.coils.manage para elegir stock.

El selector debe aceptar branch_id y contexto validado. Aplicar BranchAccess antes de paginar, restringir producto/sucursal, excluir deleted_at, voided_at y status=voided. Para ventas: libre>0 y estado available. Para compras: permitir también lote agotado con estado depleted y voided_at null; agotado no significa anulado. Recuperar saldo reservado mediante agregado SQL de remaining_meters, sin una consulta por lote. Usar cursor por (lot_code, id) y límite máximo de página.

**Código alfanumérico:**

Normalizar trim + uppercase en prepareForValidation y repetir la normalización en el servicio. Máximo 80 caracteres ASCII. Convención decidida: letras A-Z, dígitos y guion; al menos una letra y un dígito, por lo que LT2026A01 y LT-2026-A01 son válidos. Usar el patrón base /^[A-Z0-9-]+$/i y comprobar adicionalmente la presencia de letra y dígito. Rechazar espacios internos, acentos, barras, cadenas vacías y códigos formados solo por guiones. Frontend aplica la misma convención; el backend es la autoridad.

UpdateProductLotRequest usa Rule::unique('product_coils', 'lot_code') restringida a product_id y branch_id obtenidos del registro, ignorando exclusivamente su ID. Incluir anulados/soft-deleted en unicidad. El índice compuesto resuelve la carrera; convertir su violación específica a error lot_code, sin ocultar otros errores SQL.

**Editar lote:**

Autorizar antes de validar el contenido. Bajo transacción y bloqueos, rechazar voided_at no nulo o deleted_at no nulo. Solo aceptar lot_code y notes. No permitir por este endpoint stock, producto, sucursal, barcode de otra bobina, initial_meters, estado ni reservas. Conservar el ID, actualizar el espejo lot_number y generar auditoría con valores anterior/nuevo. No modificar snapshots de compras/ventas ni movimientos.

**Anular lote:**

1. Exigir motivo, rol administrador y acceso a sucursal. Bloquear agregado/lote/reservas con el protocolo común.
2. Rechazar si ya está anulado, tiene alguna reserva activa con saldo pendiente, compromiso de venta no consumido, o documento aceptado pendiente de salida física. Una cotización sin reserva no compromete stock; su posterior conversión deberá rechazar el lote anulado.
3. Si L>0, comprobar S>=L y que el retiro no invada reservas de otros lotes. Crear InventoryAdjustment de disminución con motivo y actor; descontar exactamente L de S, dejar el lote en 0.000 y emitir un solo movimiento tipo lot_void_adjustment con delta negativo, snapshots y clave idempotente.
4. Si L=0, no crear un movimiento ficticio de cantidad cero; registrar igualmente la anulación y auditoría.
5. Establecer voided_at, voided_by, void_reason y status=voided, sin delete(). No alterar otras bobinas del producto.
6. Registrar outbox dentro de la transacción. Si falla cualquier comprobación, ningún saldo ni fecha debe cambiar. Un segundo envío nunca vuelve a descontar.

La regla de devolución posterior a un lote anulado se define expresamente en 5.3; no reabrir el lote como efecto lateral.

### 3.4. Purchases: compra, recepción y reversión

Modificar estos archivos existentes:

- Http/Requests/StorePurchaseRequest.php, StorePurchaseOrderRequest.php y StorePurchaseOrderReceiptRequest.php.
- Http/Controllers/PurchaseController.php y PurchaseOrderController.php.
- Services/PurchaseWorkflowPolicy.php, PurchaseService.php y PurchaseVoidAuthorization.php.
- Los modelos de detalle indicados en sección 2.

Crear Services/PurchaseReceiptService.php para extraer la afectación común de stock hoy duplicada entre compra directa y createReceipt(). Mantener numeración, pagos y recepción parcial existentes.

**Contrato por línea gestionada:**

~~~text
lote existente: lot_id = ID; new_lot_code ausente
lote nuevo:     lot_id ausente; new_lot_code = código normalizado
~~~

Rechazar ambos campos simultáneos o ambos ausentes. Con regla apagada o producto no gestionado, rechazar campos de lotes nuevos enviados indebidamente; no activarlos por el payload. El tracking_mode enviado por el comprador no puede evitar requires_lot: releer el producto.

Para existente, verificar producto/sucursal/estado; reutilizar exactamente su ID. Para nuevo, crear una ProductCoil con lot_code/lot_number y saldo inicial controlado por el servicio. **Si es otra bobina física, siempre usar la opción nueva y otro código.** Un barcode nuevo o distinto no puede acompañar lot_id existente. Para partidas genéricas, barcode y initial_kg pueden ser null.

Calcular cantidades/costos/total del documento con Decimal/BCMath, incluyendo líneas repetidas. Dentro de una sola transacción, aumentar stock general y saldo del lote, escribir PurchaseItem.product_coil_id y snapshot lot_number y emitir movimiento con ambos saldos antes/después. Una línea legacy no debe pasar además por la rama gestionada. Cambiar depleted a available al reingresar al mismo lote activo; jamás reactivar voided.

En PurchaseOrderController::createReceipt():

- La orden no incrementa stock ni obliga a reservar un lote para toda su vida. product_coil_id/lot_number de la orden son una propuesta opcional.
- Cada recepción exige la elección definitiva de lote para la cantidad que ingresa y la persiste tanto en PurchaseItem como en PurchaseOrderReceiptItem.
- Recepciones parciales pueden utilizar lotes distintos. Para dos bobinas, separar las líneas/recepciones con códigos diferentes; no consolidarlas por producto.
- Conservar los bloqueos de orden/detalles y límites de recibido, integrándolos con el protocolo de inventario.

PurchaseController::resolveProductForItem() hoy omite requires_lot. Corregir alta rápida para usar la misma validación/servicio de Inventory, incluyendo reglas apagadas y condición de producto inventariable. No dejar un alta alternativa menos estricta.

**Anulación de compra en PurchaseService:**

1. Conservar PurchaseVoidAuthorization: permisos purchases.manage, payments.manage cuando corresponda y sucursal. No transformar la anulación de compra en una autorización para editar/anular la entidad lote.
2. Bloquear compra/orden/recepciones/detalles y recursos financieros/inventario en orden común. Verificar lotes desde los IDs persistidos, no desde códigos.
3. Para cada lote gestionado, sumar todas las cantidades de esa compra. Exigir lote activo, saldo físico/libre suficiente y ausencia de reservas/compromisos pendientes sobre ese lote.
4. Rechazar consumos posteriores incompatibles. Sin asignación por capas de entrada, adoptar política conservadora: si después de la entrada existe una salida física del lote todavía no restituida, bloquear la reversión aunque otra compra haya repuesto su saldo. Reversiones de otras compras, ya contabilizadas, no son consumos. Usar el vínculo reverses_movement_id para determinar restituciones; no inferirlas del texto del motivo.
5. Descontar solo la contribución de la compra en lote y agregado; conservar entradas de otras compras. No exigir available==initial==cantidad de esta compra para los lotes reutilizables. No anular ni borrar todo el lote como consecuencia de anular una compra; si queda en cero pasa a depleted y sigue activo.
6. Registrar movimientos de reversión idempotentes; después anular pagos/recepciones/compra con sus metadatos existentes. No añadir nuevamente voided_at a purchases o purchase_order_receipts.
7. En lotes gestionados, eliminar la llamada heredada a delete() de la bobina. En el flujo legacy no alterar su historial durante esta entrega.

La política conservadora puede rechazar la anulación de una entrada que contablemente pudiera atribuirse a stock no consumido. La alternativa es diseñar asignación de consumos por recepción; no inventarla mediante FIFO implícito en esta tarea.

### 3.5. Sales y Pos: elección, reservas y descuento exacto

**Validación y persistencia:**

1. En Sales/Http/Requests/StoreSaleDocumentRequest.php validar items.*.lot_id antes de los retornos tempranos actuales para cotización y despacho diferido. Lote obligatorio para required(product), activo, sucursal/producto correctos y cantidad suficiente. Revalidar dentro de la transacción, sumando las líneas repetidas del mismo lote.
2. No permitir negativos por lote. Una cotización sin reserva verifica disponibilidad al cotizar, pero no la garantiza para una conversión futura.
3. En SaleController.php::store/convert guardar product_coil_id mediante LotPayloadMapper, además del snapshot. Al copiar cotización, conservar exactamente la identidad; no autoelegir otro lote. Cotizaciones históricas sin identidad válida requieren corrección explícita auditada o nueva cotización antes de convertir.
4. Si store recibe source_quotation_id, bloquear origen/destino y exigir correspondencia de producto, lote, unidad y cantidades. No permitir enviar líneas distintas y consumir todas las reservas del origen. En esta entrega la conversión es completa; no implementar conversión parcial de cotización de manera implícita.
5. Incluir requires_lot y campos necesarios en todas las proyecciones selectivas, especialmente ProcessSaleNoteIssued.php, SaleController.php, PosController.php y SaleInventoryService.php. Un select que omita requires_lot puede desactivar accidentalmente la validación.
6. En DocumentItemMergePolicy y en ambas UIs comparar identidad de lote antes de cualquier regla de fusión. El mismo producto con lotes diferentes son líneas distintas.

**Reservas y listener:**

- Crear reservas automáticas de cotización solo cuando reserve_lots_on_quotation sea true. Guardar sale_item_id, sale_id, product_coil_id y remaining_meters. Incrementar reserved_meters general; el reservado de lote se obtiene de esas mismas filas.
- Adaptar Inventory/Http/Controllers/Reservations/InventoryReservationController.php y Requests/Reservations/StoreInventoryReservationRequest.php al servicio común. Para una reserva de cotización gestionada exigir correspondencia de línea, producto, sucursal y lote; no reservar por un código textual.
- En ProcessSaleNoteIssued mantener la exigencia de transacción abierta y el procesamiento síncrono. Adquirir los bloqueos de origen/destino en orden estable antes del listener; no convertirlo en trabajo asíncrono que pueda confirmar venta sin stock.
- Si descuenta al emitir, consumir exclusivamente la reserva asociada al lote/línea convertidos, reducir remaining_meters y reserved_meters, descontar cantidad física en ProductBranchStock y ProductCoil, y crear movimiento en la misma transacción.
- La comprobación de libre puede reconocer la reserva propia que se está consumiendo, nunca reservas de otras cotizaciones. Si hay líneas duplicadas, hacer la comparación sobre el total antes de mutar.
- Anular cotización libera sus reservas pendientes; anular una venta sin salida física libera sus compromisos, sin crear entrada de stock.
- No excluir reservas vencidas solo con where expires_at>now: liberarlas transaccionalmente. Aplicar también el proceso de expiración con el mismo servicio.

**Descuento diferido y POS:**

- Para timing payment o delivery, al convertir cotización transferir su reserva a committed_sale_item_id sin marcarla consumed ni liberar stock. La nueva venta mantiene un compromiso activo hasta su salida física.
- Si la venta directa o la cotización no tenía reserva, crear ese compromiso al emitir la venta diferida, bajo validación de libre. La reserva automática opcional de cotizaciones no permite omitir compromisos de ventas confirmadas.
- El primer pago que según las reglas actuales dispara descuento consume el compromiso y descuenta una sola vez. Pagos siguientes no repiten salida. Adaptar Sales/Services/SalePaymentService.php y Payments/Http/Controllers/PaymentController.php.
- En despacho parcial, DeliveryNoteController.php copia identidad/snapshot de SaleItem y consume únicamente la cantidad despachada del compromiso. remaining_meters conserva lo pendiente. Modificar también StoreDeliveryNoteRequest.php y DeliveryNoteItem.php.
- Para timing delivery, stock no sale por emitir ni por pagar. Para timing sale_note, el pago/despacho no vuelve a descontar. Usar claves idempotentes y documentos bloqueados, no solo exists() sin serialización.
- PosController debe incluir productos global gestionados; habilitar productos coil gestionados solo con elección explícita de su bobina/lote y cantidades válidas. Eliminar su exclusión automática para esos casos. Los coil legacy conservan su alcance anterior.
- POS continúa enviando a sales.store. PosWorkflowPolicy comparte LotTrackingPolicy; no crear un motor de stock alternativo. El servidor verifica perfil y sucursal aun si el carrito quedó abierto antes de un cambio de configuración.

**Devoluciones y anulación de venta:**

Modificar SaleReturnController.php, StoreSaleReturnRequest.php y SaleInventoryService::returnForVoidedSale(). El lot_id se obtiene exclusivamente de SaleItem/movimiento original; ignorar cualquier intento del cliente de elegir otro.

Calcular la cantidad restituible como salida física real menos devoluciones/reversiones previas, no simplemente cantidad vendida. Una venta pendiente de descuento no puede generar stock al devolverse. Al anular después de una devolución parcial, devolver solo el remanente.

Vincular cada movimiento de entrada al de salida mediante reverses_movement_id, asignando devoluciones a salidas pendientes en orden de ID si hubo varios despachos. La suma restituida nunca supera la salida. Bloquear la venta y recursos antes de calcularla. Guardar el mismo ID de lote, reponer agregado/lote y registrar evento idempotente. Las reglas sobre lote anulado y perfil apagado están en 5.3.

### 3.6. Cerrar rutas alternativas que eluden el lote

La revisión encontró otros escritores. No entregar una solución que solo funcione desde compras y ventas:

- **Adaptar dentro de esta entrega** Inventory/Http/Controllers/Adjustments/InventoryAdjustmentController.php y Requests/Adjustments/StoreInventoryAdjustmentRequest.php: exigir lote gestionado, modificar ambos saldos y respetar reservas; no modificar directamente available_meters. Las bajas de saldo bloqueado en lote anulado son administrativas.
- ProductCoilController::store debe delegar la entrada gestionada al servicio común, con código/rol operativo válido; no crear una bobina gestionada sin agregado general. El formulario de edición de producto no puede sobrescribir stock.
- **Bloquear explícitamente para productos gestionados en esta entrega**, con ValidationException y antes de cualquier mutación: Inventory/Http/Controllers/Transfers/InventoryTransferController.php; Production/Http/Controllers/ProductionOrderController.php; SystemSuperadmin/Services/CustomerQrOrderInventoryService.php y su conversión de pedidos; ServiceOrders/Services/ServiceOrderInventoryService.php; Restaurant/Services/RecipeInventoryService.php. Incluir entradas/salidas y productos ingredientes, no solo el producto final.
- Settings/Services/BulkImportService.php actualmente sobrescribe stock global y tracking. Bloquear importaciones que modifiquen cantidades/trazabilidad de productos gestionados o con saldos identificados, antes de updateOrCreate.
- Los escritores anteriores también deben respetar la guarda de perfil y no consumir saldo histórico asignado a lotes cuando la regla esté apagada. Si no están adaptados para calcular saldo no trazado libre, bloquear ese producto en esa ruta.
- Revisar StockOverviewController.php, Dashboard/Http/Controllers/DashboardController.php, Reports/Http/Controllers/ReportController.php y Exports/Services/ExportDatasetService.php para que no sumen el agregado general y otra vez sus bobinas gestionadas. Mostrar detalle de lote como desglose, no como stock adicional.

El bloqueo de transferencias, producción, recetas, servicios e ingreso por QR para productos gestionados es una limitación deliberada y visible; protege la contabilidad sin ampliar esta tarea a esos documentos. La alternativa completa requiere transportar lot_id en esos documentos y diseñar sus reversiones. No dejar un TODO que permita continuar por la rama global ni deshabilitar módulos enteros para los productos legacy.

## 4. Frontend (React 18 / Inertia 2 / JSX) — Paso a Paso

Implementar archivos .jsx, no TypeScript. Mantener componentes y estilos existentes; no instalar otra biblioteca para formularios o tablas. Los saldos viajan como strings decimales; la UI puede formatearlos, pero no autoriza inventario a partir de cálculos Number.

### 4.1. SystemSuperadmin

Modificar exactamente app/Modules/SystemSuperadmin/Resources/Pages/SystemSuperadmin/BusinessProfiles/Index.jsx:

1. En el paso inventory de configuración, agregar «Controlar lotes por producto», enlazado a configuration.inventory.lot_tracking_enabled.
2. Explicar que habilita el checkbox de producto y la elección obligatoria en compras, cotizaciones, ventas y POS para productos marcados. Agregar «Reservar el lote al cotizar» en las reglas de ventas, enlazado a reserve_lots_on_quotation.
3. Diferenciar la capacidad uses_lots del control efectivo; mostrar dependencias y errores de compatibilidad. No ofrecer una segunda activación OR mediante lot_tracking_optional.
4. Mantener visible el resultado de preflight: productos/sucursales que impiden activar o desactivar, sin exponer información fuera del panel oculto.
5. Cubrir guardar activo, guardar/aplicar, aplicar borrador y restaurar versión. Actualizar props tras éxito; no mostrar una regla aplicada cuando el servidor la rechazó.

### 4.2. Inventory

| Archivo exacto | Cambio |
| --- | --- |
| app/Modules/Inventory/Resources/Pages/Inventory/Products/ProductFormFields.jsx | En CatalogTraceability, mostrar un checkbox accesible «Este producto maneja lotes» únicamente si productPolicy.lotsEnabled. Usar requires_lot existente; ocultarlo completamente con regla apagada. |
| app/Modules/Inventory/Resources/Pages/Inventory/Products/Form.jsx | Inicialización/envío condicional del checkbox y mensajes de transición. No enviar false automáticamente al editar si el campo está oculto. |
| app/Modules/Inventory/Resources/Pages/Inventory/Products/Index.jsx | Enlace «Gestionar lotes» según acceso; lectura de historial disponible conforme a política. |
| app/Modules/Inventory/Resources/Pages/Inventory/Lots/Index.jsx (nuevo) | Filtros por sucursal/estado; código, producto, saldo físico, reservado, libre, bloqueado y estado. Paginación por cursor y detalle de movimientos/auditoría. |
| app/Modules/Inventory/Resources/Pages/Inventory/Lots/LotEditorModal.jsx (nuevo) | Editar código/observaciones o anular con motivo y saldo que se ajustará. Formularios separados para no enviar cantidades al editar metadatos. |
| app/Modules/Inventory/Resources/Pages/Inventory/Coils/Form.jsx e Index.jsx | Reutilizar el ingreso común si es bobina gestionada. Explicar «Cada bobina tiene un lote distinto». No presentar una nueva bobina como reposición de otra. |
| app/Modules/Inventory/Resources/Pages/Inventory/Reservations/Index.jsx | Lote obligatorio para reserva gestionada y visualización de cantidad original/pendiente. |
| app/Modules/Inventory/Resources/Pages/Inventory/Adjustments/Index.jsx | Selección de lote en ajuste gestionado; rechazo de ajuste que afecte reservas y explicación de saldo bloqueado. |
| app/Modules/Inventory/Resources/Pages/Inventory/Stock/Index.jsx | Mostrar el agregado una vez y desglose por lote; separar físico, libre y bloqueado. |
| app/Modules/Inventory/Resources/Pages/Inventory/Movements/Index.jsx | Mostrar código histórico/actual, lote y saldos antes/después de agregado y lote. |

Los botones Editar y Anular se muestran solo con canManageLots calculado por backend mediante LotAuthorization, además de las condiciones de estado. No deducir administración a partir del permiso inventory.coils.manage ni duplicar literales de roles en JSX. Un vendedor puede ver/seleccionar un lote autorizado sin poder modificarlo.

Los modales deben tener labels, errores ligados a campos, foco inicial y retorno de foco, cierre con Escape y navegación por teclado. Confirmar la anulación dentro de la interacción normal del producto mostrando el saldo afectado; no basta un icono sin nombre accesible. Deshabilitar envíos repetidos mientras se procesa.

### 4.3. Purchases

Modificar exactamente:

- app/Modules/Purchases/Resources/Pages/Purchases/Form.jsx.
- app/Modules/Purchases/Resources/Pages/Purchases/Orders/Form.jsx.
- app/Modules/Purchases/Resources/Pages/Purchases/Orders/Receive.jsx.
- app/Modules/Purchases/Resources/Pages/Purchases/Show.jsx.
- app/Modules/Purchases/Resources/Pages/Purchases/Suppliers/Statement.jsx.

Por línea gestionada mostrar dos opciones excluyentes: «Lote existente» y «Nuevo lote». El selector utiliza IDs y código visible, nunca lotOptions de textos globales. La creación rápida solicita new_lot_code; normaliza al salir del campo y antes de enviar, conservando errores del servidor.

Una bobina nueva requiere código distinto. Si se elige lote existente, ocultar/deshabilitar la creación de otra bobina/barcode y explicar que se recibe sobre esa misma identidad. Para bobinas diferentes, agregar líneas independientes.

En Receive.jsx la selección es por cantidad recibida, no una imposición de la orden completa. Mostrar la propuesta de orden como sugerencia y revalidar el lote elegido. En altas rápidas de producto, reutilizar ProductFormFields y enviar requires_lot conforme a política.

Limpiar lot_id/new_lot_code al cambiar producto o sucursal. Al fusionar líneas incluir lot_id o el código nuevo normalizado; no combinar bobinas distintas ni mezclar existente/nuevo. Las vistas históricas muestran el snapshot documental y pueden indicar el código actual corregido.

### 4.4. Sales y Pos

Modificar exactamente:

- app/Modules/Sales/Resources/Pages/Sales/Form.jsx: DEFAULT_ITEM, prepareSaleItem(), saleItemFromQuotation(), cambios de producto/sucursal y serialización deben preservar lot_id.
- app/Modules/Sales/Resources/Pages/Sales/Show.jsx: mostrar lote histórico/actual y mensajes de conversión; sustituir la dependencia exclusiva de item.coil?.lot_number.
- app/Modules/Pos/Resources/Pages/Pos/Index.jsx: normalización del carrito, selector por fila, cart_key y payload de checkout con lot_id.
- app/Modules/Sales/Resources/Pages/Sales/Deliveries/Index.jsx y Returns/Index.jsx: mostrar lote original y cantidad pendiente; no ofrecer elegir otro lote.

Crear app/Modules/Inventory/Resources/Components/LotSelector.jsx, compartido por los formularios. Consulta diferida por producto/sucursal/contexto con búsqueda paginada; no descargar todos los lotes de todas las sucursales. Cancelar respuestas antiguas cuando cambia el contexto. Mostrar físico, reservado y libre con su unidad base.

En Sales/Form.jsx, canMergeChangedItem() actualmente puede devolver true antes de comparar bobina: comparar siempre lot_id primero, incluso con same_product_and_unit. En POS incluir el lote en cart_key y permitir el mismo producto en varias filas con lotes diferentes.

Para una cotización con reserva propia, el selector puede mostrar esa reserva como «reservado para este documento». El servidor calcula el disponible específico del contexto a partir de una cotización autorizada; nunca aceptar un reservation_id arbitrario para incrementar disponibilidad.

No preseleccionar silenciosamente «el primer lote» ni cambiar el lote al quedarse sin stock. Mantener la selección, mostrar el error y permitir elegir expresamente otro. Antes de confirmar, el backend vuelve a validar. Si el perfil cambió, refrescar reglas y explicar el rechazo sin confirmar la venta.

Con regla apagada, no renderizar ni exigir los nuevos campos/códigos de lote en producto, compras, cotización, venta o POS, y limpiar su payload operativo. No borrar las referencias históricas. La compatibilidad con documentos/bobinas legacy se mantiene en su adaptador existente; no activar el nuevo control por etiquetas antiguas «lote/unidad física».

### 4.5. Errores y datos compartidos

Usar las props compartidas de app/Http/Middleware/HandleInertiaRequests.php, ActiveBusinessProfile::navigationPayload() y los resúmenes de políticas por página. Exponer canManageLots desde el controlador; auth.roles sigue disponible desde AuthSessionCache.

Mantener el circuito app/Support/SystemErrorResponder.php → system_error/errores por campo → resources/js/Components/SystemErrorFeedback.jsx. Los JSON de falta de permisos deben conservar HTTP 403; validaciones/stock insuficiente, HTTP 422. Form Requests pueden usar el flujo de errores de sesión de Inertia, sin convertir un 403 en éxito/flash genérico. Mostrar los errores items.N.lot_id, new_lot_code, lot_code y void_reason en la línea/modal correspondiente, además del resumen global.

## 5. Caché, Permisos y Casos Borde

### 5.1. Invalidación durable

1. Extender app/Support/UiCatalogCache.php: incluir requires_lot en proyecciones de productos y exponer opciones con clave que incorpore usuario/alcance, sucursal, producto, contexto, búsqueda/cursor y versión operativa. No compartir resultados de una sucursal con otra.
2. El catálogo usa solo lotes permitidos por estado, perfil y BranchAccess; información de saldo cacheada es orientativa. No cachear decisiones finales de autorización o disponibilidad.
3. Extender app/Support/OperationalCacheOutbox.php con scopes business_profile y lot_inventory. Ambos deben invalidar catálogos de productos/ventas, ejecutar SystemCacheInvalidator::bumpOperational() y AuthSessionCache::bump(). No reutilizar recovery: ese scope también reinicia colas, lo cual no corresponde a estas operaciones.
4. Programar lot_inventory dentro de cada transacción que crea, compra, reserva, libera, vende, devuelve, ajusta, edita o anula un lote; programar business_profile al aplicar cambios reales de regla. No basta un callback en memoria: debe persistir el pendiente.
5. Los bumps versionan también ActiveBusinessProfile::cacheKey() y las listas disponibles. forgetProductCatalogs() por sí solo no elimina todas las claves versionadas. No llamar a un método forget de perfil que no existe.
6. Revisar app/Providers/AppServiceProvider.php::registerCacheInvalidationHooks(): los modelos reutilizados ya tienen hooks saved/deleted afterCommit. Mantenerlos como protección, pero evitar multiplicar invalidaciones por cada línea cuando el outbox puede agrupar la operación.
7. AuthSessionCache se invalida para refrescar props/reglas compartidas. Si además se cambian permisos/roles, invalidar PermissionRegistrar; comprar/vender no debe alterar roles ni permisos.
8. Si la caché falla tras commit, la operación queda confirmada y el outbox pendiente se reintenta; no repetir la compra/venta. Un rollback no debe publicar una versión nueva como si se hubiera confirmado el cambio.

El bump de autenticación por operaciones de lote es más amplio que una invalidación exclusivamente de catálogo y aumenta recomputación de props; se conserva aquí para cumplir la actualización coordinada exigida. Una optimización posterior puede separar versiones de reglas y permisos con pruebas que garanticen el mismo contrato.

### 5.2. Activación, desactivación y permisos

| Situación | Regla obligatoria |
| --- | --- |
| Rol sistemasuperadmin cambia reglas | Permitido con guarda transaccional, auditoría y outbox. Su bypass no permite saltarse consistencia de inventario. |
| Rol superadmin de negocio cambia reglas | 403, aunque Gate le conceda permisos generales. Puede editar/anular lotes, no gobernar esta configuración. |
| Operador con inventory.coils.manage o todos los permisos | Sigue recibiendo 403 al editar/anular lote si no posee uno de los dos roles administrativos. |
| Nueva activación sobre datos legacy | Rechazar si productos requires_lot tienen stock no identificado, bobinas con lot_code null, reservas incompatibles o diferencias de saldos. No asignar códigos ni trasladar cantidades silenciosamente. |
| Desactivar regla, capacidad o módulo que la habilita | Rechazar si queda saldo físico gestionado, reserva activa, compromiso pendiente o documentos confirmados pendientes de afectación física. Aplicar en todas las sucursales y vías de guardar/restaurar. |
| Desmarcar requires_lot | Rechazar con stock identificado o reservas/compromisos; aun agotado, conservar IDs y documentos históricos. |
| Cambiar modo coil/global o unidad base con historia gestionada | Rechazar en el formulario; requiere un procedimiento de migración explícito fuera de esta entrega. |
| Lote agotado | Puede volver a recibir mercancía de la misma identidad; no está anulado. Otra bobina siempre requiere otro lote. |
| Anular lote reservado | Rechazar sin modificar saldo, reservas ni voided_at. Primero liberar/cancelar el compromiso por su flujo autorizado. |
| Código ya usado por lote anulado | Rechazar; la unicidad no excluye anulados ni permite reutilizar su identidad. |
| Lote de otro producto/sucursal | Rechazar en servidor; filtrar por BranchAccess no sustituye la coincidencia con la sucursal de la operación. |
| Regla apagada y payload forzado con lote nuevo | Rechazar aunque el actor sea sistemasuperadmin. Los middlewares tienen bypass para ese rol; el servicio no lo tiene. |
| Cambio concurrente de perfil/checkbox y compra | Guarda compartida/exclusiva más bloqueo de producto; gana una transición consistente, nunca una compra sin lote en medio de activar la obligación. |

La desactivación no borra requires_lot, códigos, reservas históricas ni FK. Los formularios nuevos se comportan sin lotes cuando corresponde, pero los procesos históricos siguen usando la identidad registrada. El usuario obtiene un error accionable si intenta desactivar antes de resolver existencias/compromisos; no se transforma stock automáticamente.

### 5.3. Devolución a lote anulado y operaciones históricas con regla apagada

Decisión explícita para compatibilizar trazabilidad y conservación física: **anular deja el saldo del lote en cero en el momento de la anulación**. Una devolución posterior legítima se registra como entrada al mismo ID original, aunque esté anulado, sin borrar voided_at/voided_by/void_reason ni editar su código.

Esa devolución crea **stock físico bloqueado**, no disponible para venta ni para reposición de compra. El agregado de sucursal aumenta junto con el lote; la UI muestra bloqueado por separado. Es una restitución histórica excepcional, no selección de un lote anulado en una nueva operación. No descartar mercancía automáticamente ni abrir otro lote para ocultar el origen.

Si corresponde retirar ese saldo por un motivo real, un administrador registra un ajuste negativo auditado sobre ese lote. No reutilizar la anulación para descontarlo otra vez ni permitir que un vendedor haga la baja. Una decisión de rehabilitar el lote requeriría otra funcionalidad; esta entrega no ofrece reapertura.

Si la regla o requires_lot fue desactivada cuando todos los saldos estaban en cero, una devolución histórica puede volver a generar saldo trazado. Conservarlo y bloquear su venta por la ruta global sin lote:

~~~text
saldo_no_trazado = stock_general_físico - SUM(saldos_de_lotes_gestionados)
libre_no_trazado = saldo_no_trazado - reservas_activas_sin_lote
~~~

Los nuevos movimientos sin lote solo pueden consumir libre_no_trazado, incluso con política general de negativos cuando haya saldo histórico protegido. Los escritores no adaptados deben rechazar ese producto. Para volver a vender un lote activo devuelto, reactivar el control y el producto con las comprobaciones de conciliación; un lote anulado sigue bloqueado.

La reactivación después de compras sin lote exige saldo no trazado cero o una conciliación administrativa explícita que no duplique entradas. En esta entrega, si no está conciliado, bloquear la reactivación y mostrar los productos/sucursales implicados.

### 5.4. Historial, auditoría y compatibilidad

- Corregir un código no modifica FK ni snapshots de documentos emitidos. Registrar actor, fecha, código anterior/nuevo y observaciones.
- Los saldos se corrigen con movimientos compensatorios, nunca con UPDATE/DELETE del kardex.
- Los lotes gestionados anulados siguen consultables y no usan SoftDeletes. Los soft-deleted legacy no aparecen en opciones nuevas ni se reactivan automáticamente.
- La combinación product_id/branch_id/lot_code cumple la unicidad solicitada. Una bobina no cambia de lote ni reutiliza el código de otra dentro de ese ámbito. No convertir códigos coincidentes legacy en bobinas compartidas.
- No cambiar datos ni permisos de otros módulos para forzar que pasen pruebas. Conservar comportamiento legacy no afectado y documentar los bloqueos específicos de 3.6.

## 6. Plan de Pruebas (Pest) y Verificación

### 6.1. Higiene y aislamiento obligatorio

Antes de editar, ejecutar git status --short y registrar los cambios preexistentes. En el reconocimiento se encontraron:

- docs/contexto-proyecto-para-aragon.md.
- tests/Feature/Settings/PersistentBackupConnectionTest.php.

**Prohibido tocar, eliminar, sobrescribir, formatear o revertir esos archivos y cualquier otro cambio local ajeno.** No ejecutar git reset/clean. No modificar .env operativo, credenciales operativas ni la base real. Este encargo de arquitectura solo escribió esta guía.

Las pruebas deben pasar por php vendor/bin/pest --compact y usar exclusivamente:

~~~text
APP_ENV=testing
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3307
DB_DATABASE=fabrica_calmina_aroma_testing
DB_USERNAME=calmina_test
DB_URL vacío
DB_SOCKET vacío
~~~

phpunit.xml ya fuerza esos valores. tests/bootstrap.php los comprueba antes de Laravel y rechaza configuración cacheada; tests/TestCase.php verifica la conexión resuelta antes de RefreshDatabase. Mantener ambas barreras, además de tests/Pest.php. Si existe bootstrap/cache/config.php, detener las pruebas y resolver la configuración aislada sin borrar configuraciones operativas automáticamente.

No ejecutar migrate:fresh, db:wipe, seeders o un runner alternativo contra .env. No sustituir MySQL por SQLite: estas pruebas dependen de DECIMAL, FK y bloqueos. Las pruebas de lotes no usarán la conexión secundaria de restauración ni sandboxes. No ejecutar la suite completa sin revisar su alcance: contiene pruebas de restauración/sandbox fuera de la base única autorizada para esta entrega.

### 6.2. Archivos de pruebas y casos específicos

Crear:

- tests/Feature/SystemSuperadmin/BusinessLotRulesTest.php.
- tests/Feature/Inventory/InventoryLotsTest.php.
- tests/Feature/Inventory/InventoryLotAuthorizationTest.php.
- tests/Feature/Inventory/InventoryLotConcurrencyTest.php.
- tests/Feature/Purchases/PurchaseLotsTest.php.
- tests/Feature/Sales/SalesLotsTest.php.
- tests/Feature/Pos/PosLotsTest.php.

Reutilizar fixtures/patrones de BusinessProfileRulesTest, InventoryModuleTest, InventoryReservationsModuleTest, PurchasesModuleTest, PurchaseVoidAuthorizationTest, PurchaseVoidConsistencyTest, SalesModuleTest, SalesReturnsModuleTest, DeliveryNotesModuleTest, DocumentItemMergePolicyTest y PosDynamicModuleTest. No cambiar expectativas legacy para esconder una regresión.

| Grupo | Pruebas y resultado verificable |
| --- | --- |
| Gobierno | sistemasuperadmin puede guardar/aplicar la regla; superadmin de negocio y operador reciben 403 en guardar activo, save-and-apply, aplicar y restaurar. No basta probar el GET del panel. |
| Defaults | Perfil antiguo/preset sin lot_tracking_enabled resuelve false aunque uses_lots=true o lot_tracking_optional=true. JSON "false" no se interpreta como true. |
| Dependencias | Regla sin capacidad/módulo, reserva opcional sin soporte e inventory timing manual se rechazan; force=true no evita la guarda. Preview no gobierna escrituras reales. |
| Producto | Alta y edición con requires_lot habilitado; producto no inventariable rechazado; regla apagada no exige campo ni acepta true forzado; campo oculto ausente no borra historial. Alta rápida de compra aplica lo mismo. |
| Incorporación | Activación con stock legacy sin lot_code se bloquea sin crear lotes/entradas. Datos conciliados o productos sin existencias permiten habilitar. |
| Bobina y lote | Dos bobinas nuevas del mismo producto generan dos IDs y códigos distintos; nunca se agrupan. Repetir código para otra bobina da error. Reutilizar lot_id no crea otra bobina y rechaza barcode distinto. |
| Código | Trim/uppercase; LT2026A01 y LT-2026-A01 válidos; caracteres inválidos, solo letras/números/guiones y longitud>80 rechazados según convención. Unicidad por producto/sucursal; al editar se ignora solo el ID actual. |
| Compra | Nuevo lote y lote existente; recepción parcial; todos los detalles conservan FK y snapshot; duplicar líneas del mismo lote suma cantidades una vez. Lote agotado puede reingresar; anulado no. |
| Saldos | Entrada aumenta agregado y lote exactamente; venta disminuye ambos; consultas/reportes no duplican el mismo stock. Cantidades 0.100+0.200 producen 0.300 sin float. |
| Venta/POS | Lote obligatorio en cotización, nota y POS; ausencia, otra sucursal/producto, lote anulado y saldo insuficiente se rechazan sin stock/pago parcial. Negativo global no habilita sobreventa de lote. |
| Fusión | Mismo producto con lotes distintos mantiene dos líneas en venta/POS/compra, incluso same_product_and_unit. |
| Cotización | Sin reserva automática no cambia saldos; con reserva incrementa reservado general y reserva del lote; conversión consume exactamente su lote y reserva propia, sin consumir ajenas. |
| Conversión manipulada | source_quotation_id con líneas/lotes/cantidades diferentes se rechaza. Una cotización no puede convertirse dos veces. |
| Diferidos | payment y delivery conservan compromiso al convertir; primer disparador consume una vez; despacho parcial reduce solo lo enviado; segundo pago/reintento no descuenta otra vez. |
| Liberación | Anular cotización/venta aún no descontada libera pendiente sin crear stock. Expirar usa transacción y respeta consumo parcial. |
| Edición administrativa | superadmin y sistemasuperadmin corrigen código/notes; FK y snapshots siguen iguales y existe auditoría. Editar lote anulado da 422. |
| Anulación administrativa | Con saldo L y sin reservas: S baja exactamente L, lote queda 0.000, voided_at/by/reason presentes, movimiento/ajuste auditados. Otros lotes no cambian. |
| Prohibición operativa | Usuario sin rol administrativo, incluso con inventory.coils.manage y permisos amplios, recibe HTTP 403 en PATCH y POST void usando IDs válidos. No hay cambios de stock ni auditoría de modificación. |
| Reservas y anulación | Administrador no puede anular lote reservado/comprometido; tampoco si expires_at pasó pero reserva sigue active. Repetir anulación no repite el ajuste. |
| Reversión de compra | Activo/saldo libre/consumos/compromisos verificados; revertir solo cantidad comprada. Compra compartiendo lote no elimina otras entradas; saldo final cero no implica voided_at del lote. |
| Rollback | Fallo forzado después de primera línea revierte todas las líneas, saldos, pagos, auditoría y outbox. |
| Devolución | Reingresa al mismo ID; devolución parcial seguida de anulación restituye solo saldo pendiente; sin salida física no crea inventario. |
| Lote anulado devuelto | Entrada histórica al ID original, voided_at intacto, saldo bloqueado fuera de selectores; no reapertura ni descarte automático. |
| Regla apagada/historia | Una devolución histórica sigue trazada; la venta global no consume saldo asignado; reactivación con saldo no trazado se bloquea. |
| Vías alternativas | Ajustes/reservas gestionados mantienen ambos saldos. Transferencias/producción/QR/servicios/recetas/importación no pueden saltarse el control por ruta directa. |
| Caché | Tras commit cambian catálogos, perfil y props de autorización según scope. Un rollback no invalida como éxito. Fallo de caché deja outbox reintentable y no duplica negocio. |

Ejemplo numérico de aceptación: comprar 10.000 de LT2026A01, recibir 2.000 adicionales de esa misma identidad y comprar 5.000 de LT2026B01 deja S=17.000, A=12.000 y B=5.000. Reservar 4.000 de A y convertir esa cotización deja S=13.000, A=8.000, B=5.000 y R=0.000. Anular B como administrador deja S=8.000 y B=0.000 con baja lógica. En el caso de bobinas, la segunda recepción de A no puede representar otra bobina física: debe probarse el rechazo de ese intento.

### 6.3. Concurrencia real e idempotencia

Usar dos conexiones/procesos contra la misma base aislada y barreras explícitas. No invocar dos runners con RefreshDatabase simultáneamente: uno podría destruir fixtures del otro. Crear los datos con commit antes de los trabajadores de concurrencia, limpiar solo la base de prueba al terminar y comprobar en cada trabajador host/puerto/nombre de base.

Casos mínimos:

1. Dos ventas compiten por la última cantidad: una tiene éxito y la otra error de stock; saldo final no negativo.
2. Reserva simultánea con venta/anulación: no existe resultado con reserva activa sobre un lote anulado.
3. Compra concurrente con anulación del mismo lote: se serializa; una operación rechaza según estado final.
4. Dos altas del mismo código: un registro y error controlado para el otro, sin mezclar documentos.
5. Desactivar perfil o desmarcar producto mientras entra mercancía: la guarda impide un estado parcialmente trazado.
6. Conversión repetida, listener repetido y devolución repetida: no duplican salida/entrada ni consumen dos veces la reserva.
7. Dos documentos con los mismos productos/lotes en orden inverso: completar o reintentar de forma acotada, manteniendo todos los saldos.

En pruebas con RefreshDatabase, los callbacks afterCommit pueden no ejecutarse por la transacción externa del test. Verificar primero la fila outbox persistida y procesarla explícitamente en la prueba de invalidación; usar una prueba de integración con commit real para comprobar el enlace afterCommit. No concluir que la caché funciona solo porque se insertó el pendiente.

### 6.4. Ejecución y verificación de entrega

Primera pasada, con las barreras de conexión anteriores intactas:

~~~powershell
php vendor/bin/pest --compact tests/Feature/SystemSuperadmin/BusinessLotRulesTest.php tests/Feature/Inventory/InventoryLotsTest.php tests/Feature/Inventory/InventoryLotAuthorizationTest.php tests/Feature/Inventory/InventoryLotConcurrencyTest.php tests/Feature/Purchases/PurchaseLotsTest.php tests/Feature/Sales/SalesLotsTest.php tests/Feature/Pos/PosLotsTest.php
~~~

Después ejecutar regresiones puntuales de los módulos afectados, siempre con el mismo runner y base:

~~~powershell
php vendor/bin/pest --compact tests/Feature/Inventory/InventoryModuleTest.php tests/Feature/Inventory/InventoryReservationsModuleTest.php tests/Feature/Inventory/InventoryAdjustmentsModuleTest.php tests/Feature/Purchases/PurchasesModuleTest.php tests/Feature/Purchases/PurchaseVoidAuthorizationTest.php tests/Feature/Purchases/PurchaseVoidConsistencyTest.php tests/Feature/Sales/SalesModuleTest.php tests/Feature/Sales/SalesReturnsModuleTest.php tests/Feature/Sales/DeliveryNotesModuleTest.php tests/Feature/Sales/DocumentItemMergePolicyTest.php tests/Feature/Pos/PosDynamicModuleTest.php
~~~

Comprobar sintaxis PHP de archivos modificados y compilar JSX con Vite. package.json ejecuta php artisan ziggy:generate en prebuild: si se usa npm run build, preparar antes entorno testing con la misma conexión aislada y revisar el archivo generado resources/js/ziggy.js; no permitir que ese paso arranque con configuración operativa. Una compilación solo de Vite no sustituye verificar las rutas nuevas de Ziggy.

Verificación manual en el entorno aislado: perfil apagado, perfil encendido, administrador, operador, dos sucursales, compra con lote nuevo/existente, dos bobinas diferentes, cotización reservada, POS, edición, anulación y devolución. Confirmar teclado/foco, móvil, errores SystemErrorFeedback y selección borrada al cambiar sucursal.

Antes de entregar implementación: revisar git diff --check y git status --short, confirmar que los archivos locales protegidos no cambiaron, y resumir migraciones, comportamiento, pruebas ejecutadas y limitaciones explícitas. No afirmar que las pruebas pasaron si no se ejecutaron. No aplicar migraciones ni conciliaciones de existencias sobre la base operativa como parte de esta guía.
