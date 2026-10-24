# Verificación comercial del perfil activo de ferretería

Fecha: 24 de octubre de 2026.

## Alcance y aislamiento

Se copió únicamente la configuración del perfil activo `hardware_store` a `tests/Fixtures/hardware-active-profile.json`. Las pruebas crean una copia en la base de testing y verifican después de cada caso que sus reglas no cambiaron. Se utiliza un operador con permisos comerciales, sin privilegios de superadministrador.

Las operaciones de prueba se ejecutaron mediante el kernel HTTP de Laravel exclusivamente contra `127.0.0.1:3307`, base `fabrica_calmina_aroma_testing`, usuario `calmina_test`. No se registraron operaciones de prueba ni se aplicaron migraciones en la base operativa. No se reconstruyó Docker ni se desplegó a producción.

Reglas conservadas: cotización obligatoria antes de la nota de venta, cliente y caja abierta obligatorios, descuento al emitir la nota, lotes activos y sin reservas automáticas de cotizaciones. Se mantuvieron desactivados POS, reservas opcionales, producción, restaurante y órdenes de servicio. El perfil permite líneas de servicio, como corte, sin inventario.

## Casos ejecutados

Suite: `tests/Feature/Inventory/HardwareActiveProfileFlowTest.php`.

| Caso | Comprobación principal |
| --- | --- |
| Cotización y conversión | Exige cliente y caja; rechaza venta directa y conversión repetida; descuenta una sola vez. |
| Cotizaciones competidoras | Revalida stock al convertir y rechaza la segunda operación sin saldo negativo. |
| Cobros y cierre | Efectivo, QR y transferencia; reversión de cobro, despachos sin segundo descuento y cierre de caja conciliado. |
| Recepciones parciales | Suma exacta de 33.333 + 66.667 m al mismo lote; rechaza exceso y revierte sólo la recepción elegida. |
| Proveedores | Pago bancario y anulación integral de compra con restitución de banco y compensación de kardex. |
| Peso a metros | Orden con 4428 kg y 3.13 kg/m produce 1414.696 m; recepción sólo con peso calcula los metros. |
| Transferencias | Permite stock simple y bloquea lotes gestionados sin alterar saldos. |
| Servicio de corte | Cotiza y vende el servicio sin afectar existencias físicas. |
| Venta mixta | Conserva dos líneas del mismo producto con lotes distintos y una línea de servicio. |
| Exportación y sucursales | Exporta el stock una sola vez y rechaza reportes de otra sucursal sin autorización. |
| Reportes y utilidad | Excluye cotizaciones de ingresos y notas anuladas de ventas y utilidad. |
| Entrada decimal inválida | Rechaza notación exponencial en peso/costo de órdenes y recepciones con 422, sin documentos ni stock parcial. |

## Correcciones realizadas

- Las órdenes podían fallar al omitir una descripción opcional; ahora usan el nombre del producto.
- La conversión de órdenes gestionadas multiplicaba un factor inverso redondeado. Ahora divide por kg/m mediante el servicio decimal exacto compartido.
- La recepción gestionada admitía peso en pantalla pero no calculaba su cantidad física. Ahora valida y calcula los metros desde peso y espesor en el servidor; los metros explícitos tienen prioridad y el peso queda como referencia.
- Se aclara ese comportamiento junto a los campos de recepción.
- Los valores en notación exponencial podían alcanzar BCMath y provocar un error interno. Se rechazan antes de procesar órdenes y recepciones.
- Dashboard y reportes sumaban cotizaciones junto con notas, duplicando ingresos del flujo obligatorio de cotización. Sus métricas y gráficos ahora cuentan las notas de venta vigentes; la utilidad excluye notas anuladas y eliminadas. Se versionaron sus claves de caché.

Archivos de aplicación ajustados: `PurchaseOrderController`, `StorePurchaseOrderRequest`, `StorePurchaseOrderReceiptRequest`, `PurchaseReceiptService`, `Purchases/Orders/Receive.jsx`, `DashboardController` y `ReportController`.

## Resultados reales

- Pest: **12 pruebas aprobadas, 372 aserciones**, 43.76 segundos.
- `php -l`: ocho archivos PHP revisados, sin errores.
- `npm run build`: Ziggy y Vite completados; Vite en 13.54 segundos. Se usaron variables de entorno de testing aisladas.
- `git diff --check`: sin errores de espacios; Git informa avisos de normalización CRLF en archivos previamente modificados.
- `bootstrap/cache/config.php`: ausente al terminar.
- SHA-256 de `.env`, los dos documentos protegidos y `PersistentBackupConnectionTest.php`: idénticos a los registrados antes de la revisión.

Esta ejecución comprueba los doce casos comerciales e inventario descritos; no constituye una prueba exhaustiva de todos los módulos administrativos del perfil. Las ejecuciones anteriores y sus escenarios se documentan por separado en `verificacion-flujo-lotes-2026-10-24.md`.
