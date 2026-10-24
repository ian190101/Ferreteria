# Verificación del flujo de lotes por producto

Fecha: 24 de octubre de 2026.

## Entorno y alcance

Pruebas funcionales mediante peticiones al kernel HTTP de Laravel, exclusivamente en MySQL/MariaDB `127.0.0.1:3307`, base `fabrica_calmina_aroma_testing`, usuario `calmina_test`. Se verificaron puerto y directorio de datos antes de ejecutar. Las pruebas no crean compras ni ventas en la base operativa.

Los tres ejemplos reproducibles están en `tests/Feature/Inventory/InventoryLotFlowTest.php`. Cada etapa comprueba con decimales exactos que el stock general coincide con la suma de los lotes, que las reservas coinciden con los compromisos activos y que los saldos antes/después del kardex encadenan correctamente.

## Ejemplos ejecutados

| Caso | Operaciones | Resultado comprobado |
| --- | --- | --- |
| 1. Calamina roja, entrada múltiple y salida inmediata | Alta rápida en compra con tres lotes de 100, 50 y 25 m. Venta de 10 piezas por 3 m. Devolución de 12 m. Intento de anular la compra con consumo pendiente. Anulación de la venta y después de la compra. | Stock: 175 → 145 → 157 → 175 → 0 m. La compra se bloquea mientras falta restituir stock y se permite después de la restitución completa. Se conservan los tres lotes y nueve movimientos compensados; no se borran identidades. |
| 2. Calamina azul, peso, cobros y reingreso | Compra de 0.100 toneladas con espesor de 2 kg/m: entran 50 m. Venta de 100 kg con descuento al primer pago. Segundo pago, reingreso al mismo lote de 7.500 m y ajuste por merma de 0.125 m. Anulación administrativa del lote, devolución de 5 m y retiro administrativo del saldo bloqueado. | La venta reserva 50 m y el primer cobro los descuenta una sola vez; el segundo no genera otra salida. El reingreso conserva la identidad y los metros iniciales. Saldo previo a la anulación: 7.375 m. Los 5 m devueltos al lote anulado quedan bloqueados; el operador no puede retirarlos y el administrador sí. Saldo final: 0 m. |
| 3. Calamina verde, equivalencia y despacho diferido | Compra de 20 planchas a 2.500 m cada una: entran 50 m. Cotización de 12 planchas, reserva de 30 m y conversión. Cancelación de otra cotización y otra venta pendiente para comprobar liberaciones. Rechazo de devolución sin salida física. Despacho de 10 m, devolución de 2.500 m y despacho de las ocho planchas restantes. | Físico/reservado: 50/30 → 40/20 → 42.500/20 → 22.500/0 m. El despacho de 10 m conserva su equivalencia de cuatro planchas. No quedan compromisos ni despachos pendientes. Una cotización convertida no puede anularse para liberar indebidamente la reserva de su venta. |

También se consultan las vistas de compra, stock, movimientos, devoluciones y despachos y las opciones de lotes, incluidos los saldos bloqueados.

## Errores corregidos

- Anular cotizaciones o ventas pendientes no liberaba las reservas gestionadas. Ahora se liberan sus compromisos dentro de la transacción y se protege la cotización ya convertida.
- Una devolución podía registrar cantidades sin suficiente salida física restituible. Ahora se rechaza y revierte la operación completa; la lista de devoluciones ofrece únicamente cantidades físicamente restituibles.
- Una devolución de un despacho se restaba de nuevo al calcular lo pendiente. Ahora se usa su vínculo al movimiento original para evitar el doble descuento y los compromisos huérfanos.
- Un despacho expresado en metros podía guardar esa misma cifra como cantidad comercial. Ahora conserva la conversión de metros a la unidad del documento.
- La anulación de compra bloqueaba incluso salidas ya restituidas completamente. Ahora reconoce devoluciones y reversiones mediante `reverses_movement_id`, manteniendo el bloqueo de consumos sin restituir y de reservas activas.
- El cobro podía perder el aviso de lote agotado porque la relación de venta cargada por conciliación omitía el tipo de documento. Ahora conserva la venta completa para calcular el aviso.
- El alta rápida de producto en una compra podía fallar si se omitía la descripción opcional. Ahora usa el nombre del producto.
- El kardex mostraba siempre metros y algunos tipos internos sin traducir. Ahora muestra la unidad real y etiquetas de los movimientos gestionados.
- Se reforzó el orden de bloqueo en liberaciones, ajustes y anulaciones. La prueba de concurrencia amplía únicamente la espera de arranque de los trabajadores en Windows, comprueba terminaciones prematuras y conserva la competencia real entre procesos.

## Resultados finales

| Verificación | Resultado |
| --- | --- |
| Suites funcionales de Inventario, Compras, Ventas, POS, Pagos, Alertas y reglas de lotes | 166 pruebas aprobadas, 2035 aserciones |
| `InventoryLotConcurrencyTest.php`, ejecución separada | 3 pruebas aprobadas, 55 aserciones |
| Total de ambas ejecuciones, sin sumar repeticiones previas | 169 pruebas aprobadas, 2090 aserciones |
| `php -l` en los archivos PHP creados/modificados en esta revisión | Sin errores |
| Generación Ziggy y `npm run build`, con entorno aislado de testing | Correcto; Vite compiló los assets |
| `git diff --check` | Sin errores de espacios; avisos preexistentes de normalización CRLF/LF |
| Archivo `bootstrap/cache/config.php` | Ausente |

Evidencias locales ignoradas por Git: `storage/qa/lot-flow-functional.log`, `storage/qa/lot-flow-concurrency.log` y `storage/qa/lot-flow-build.log`.

Los hashes de `.env`, los dos documentos protegidos y `PersistentBackupConnectionTest.php` permanecen iguales a los registrados al inicio. Esta revisión no añade migraciones, no concilia datos operativos y no reconstruye ni despliega imágenes Docker.
