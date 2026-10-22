# Validación por fases

## Entornos acordados

1. Local Windows: PHP 8.2.12, Composer 2.8.11 y MariaDB 10.4.32 desechable, puerto 3307.
2. Preproducción: Ubuntu Server, Docker 29.9.1, Compose 5.6.0 y MySQL 8.0.46. Instalación aislada en `/home/ian/calmina-aroma-preproduccion`, proyecto Compose `calmina-qa`, acceso HTTP solo en `127.0.0.1:18081`. Se conservan los servicios existentes del servidor.
3. Producción real: excluida de esta entrega por instrucción expresa del usuario. La plataforma futura es Windows con Docker; no se desplegará ni se afirmará su validación aquí.

La entrega autorizada comprende únicamente local y preproducción. Se transfiere un archivo de fuentes directamente desde el espacio de trabajo, sin publicar en GitHub. Los datos de ensayo permanecen aislados de los datos reales y de los servicios existentes del servidor.

Las verificaciones de integridad finales deben repetirse en MySQL 8.0; un resultado en MariaDB no las reemplaza.

## Preparación reproducible

Instalar con `composer install --no-interaction --prefer-dist`. No ejecutar `composer update`.

Para MariaDB local de XAMPP:

```powershell
./scripts/Start-ValidationDatabase.ps1
php vendor/bin/pest --filter=PaymentsModuleTest --compact
php vendor/bin/pest --compact --log-junit storage/qa/baseline.xml
```

Ejecutar estos comandos en una terminal local con acceso normal al sistema de archivos y a loopback. En el sandbox de Codex para Windows, PHP puede leer el contenido de `tests/bootstrap.php` mientras `is_readable()` devuelve `false`; PHPUnit lo rechaza antes de iniciar las pruebas. Ese resultado no indica que falte el archivo. También puede quedar inaccesible el puerto local `3307` desde ese sandbox.

El script puede repetirse: reutiliza la instancia activa solo si su directorio de datos coincide con `storage/qa/mysql`. La instancia escucha únicamente en loopback y no modifica el servicio ni los datos de XAMPP. Para detenerla:

```powershell
& C:/xampp/mysql/bin/mysqladmin.exe --host=127.0.0.1 --port=3307 --user=root shutdown
```

Para verificar MySQL 8.0 con Docker, detener antes la instancia de MariaDB del puerto 3307:

```powershell
docker compose -f docker-compose.testing.yml up -d --wait
php vendor/bin/pest --compact --log-junit storage/qa/mysql8.xml
docker compose -f docker-compose.testing.yml down
```

El compose de pruebas es independiente del compose operativo y utiliza almacenamiento temporal. Las credenciales vacías solo corresponden a esta instancia desechable, publicada en loopback. El usuario `calmina_test` tiene permisos sobre `fabrica_calmina_aroma_testing` y los esquemas temporales `sandbox_*` necesarios para probar la demo; no dispone de privilegios globales ni acceso a bases operativas. El usuario de restauración permanece separado.

`phpunit.xml` fija conexión, usuario y base. `tests/bootstrap.php` rechaza configuración insegura antes de iniciar Laravel y normaliza variables heredadas de Windows. `Tests\TestCase` valida la conexión resuelta antes de `RefreshDatabase`. La configuración cacheada impide ejecutar pruebas.

El modo de mantenimiento de las pruebas utiliza almacenamiento en memoria por proceso: una interrupción ensayada no puede escribir `storage/framework/down` ni cerrar la aplicación local del desarrollador. Los procesos secundarios heredan esta configuración de pruebas.

`Tests\Support\OperationalScenario` prepara dos compras, dos pagos parciales, inventario global, una bobina con reserva activa y una orden con dos recepciones independientes. Los datos se crean dentro de las transacciones de las pruebas y se revierten al terminar.

## Registro de aceptación

| Fase | Estado | Evidencia requerida para cerrarla |
| --- | --- | --- |
| 1. Entorno reproducible | Completada | Composer desde lock; base y usuario aislados; línea base: 394 aprobadas y 3 fallidas; aislamiento y escenario: 9 aprobadas, 26 aserciones |
| 2. Permisos y anulación integral | Completada | Matriz HTTP y autorización de interfaz: 14 pruebas/81 aserciones; regresión compras/pagos: 42 pruebas/264 aserciones; compilación Vite aprobada |
| 3. Consistencia de compras | Completada | Matriz local de 68 pruebas/456 aserciones, repetida en la suite final MySQL; recorrido HTTP real con varios pagos, reserva, bloqueo, liberación, anulación y reintento |
| 4. Vista previa de purga | Completada | Integridad, barreras, ciclos, tablas protegidas y reversión aprobados en ambos motores; purga real en cola con respaldo obligatorio |
| 5. Correlativos y recuperación | Completada | Matriz de numeración y respaldos aprobada en ambos motores; purga real de proveedores sin alterar ventas y recuperación real de sus datos |
| 6. Versionado Git | Completada | Repositorios temporales, recuperación reconstruida, controles HTTP y actualización Docker real con respaldo ensayado; salida 0 |
| 7. Validación y despliegue | Completada | 496 pruebas/3.481 aserciones aprobadas en cada motor; purga, restauración y actualización reales; publicación final comprobada y evidencias locales verificadas |

Cada fase se cierra con evidencia de sus objetivos y criterios de aceptación. La aceptación final en MySQL 8.0 y preproducción corresponde a la fase 7; no se presume a partir de los resultados locales. Docker Windows en producción real permanece excluido por instrucción del usuario.

## Evidencias de la fase 1

- Instalación completada desde `composer.lock`, incluida la detección de paquetes de Laravel, sin actualizar dependencias.
- Línea base local: 397 pruebas, 394 aprobadas y 3 fallidas, 2866 aserciones. Evidencias locales: `storage/qa/baseline.txt` y `storage/qa/baseline.xml`.
- Dos fallos corresponden a la demo completa: espera redirección al dashboard, pero no puede crear otra base con el usuario restringido de pruebas. No se amplían sus privilegios para ocultar este resultado.
- El tercer fallo corresponde al menú QR: espera un producto y recibe dos en la ejecución completa. Debe verificarse el aislamiento entre pruebas antes de la aceptación final.
- Controles de aislamiento y escenario representativo: 9 pruebas aprobadas, 26 aserciones; `storage/qa/phase1.xml`.
- Al cerrar la fase 1 todavía no se habían validado Docker/MySQL 8 ni Ubuntu; sus resultados finales se registran en la fase 7. La preparación y la medición inicial no sustituyen esa aceptación.

## Evidencias de la fase 2

- `PurchaseVoidAuthorization` aplica permisos y acceso a sucursal en ambos formularios y vuelve a comprobarlos dentro del servicio tras bloquear la compra.
- Las compras con pagos requieren `purchases.manage` y `payments.manage`; las compras sin pagos requieren `purchases.manage`.
- La vista previa autorizada detalla compra, pagos e inventario; los botones reciben `can_void` calculado por el servidor y muestran “Anular compra y pagos”.
- La migración `2026_10_19_000001` conserva usuario, fecha, motivo y referencias de pagos, ítems y recepciones. Se verificó primero en pruebas y posteriormente en la instalación aislada de preproducción.
- `storage/qa/phase2.xml`: 42 pruebas aprobadas, 264 aserciones. `storage/qa/phase2-authorization.xml`: 14 aprobadas, 81 aserciones, incluidos rechazos HTTP sin cambios y acceso entre sucursales.
- Compilación de producción Vite aprobada: `storage/qa/build-phase2.txt`.

## Evidencias de la fase 3

- La compra, órdenes, ítems, recepciones, pagos, inventario y movimientos bancarios se releen y bloquean antes de modificarlos. Los bloqueos de cada conjunto siguen un orden estable y los interbloqueos admiten hasta tres intentos.
- Se rechazan reservas activas, consumo incluso si después se devolvió la bobina, inventario faltante y cantidades agregadas superiores al stock libre. No se omiten recursos ausentes.
- Las recepciones conservan su historial mediante `voided_at`; solo se descuentan las cantidades de la compra anulada. Anular después la otra compra deja la orden aprobada y su cantidad recibida en cero.
- Pagos y recepciones vuelven a validar saldo y estado después del bloqueo. Los números de recepción incluyen ULID para evitar colisiones dentro del mismo segundo.
- Los cambios de caché se programan después del commit, incluidos los hooks de modelos y las versiones financieras.
- `storage/qa/phase3.xml`: 68 pruebas aprobadas y 456 aserciones. Las pruebas con dos conexiones verifican espera de bloqueo, ausencia de cambios parciales, reintento y retención del bloqueo hasta confirmar.
- Las pruebas concurrentes usan datos confirmados y descartan únicamente el esquema de pruebas. Se detectó que un `down` histórico intenta eliminar dos veces un índice; la limpieza de estas pruebas no depende de esa reversión defectuosa.
- Estos resultados identifican la aceptación local de la fase; su repetición en MySQL 8.0 y el recorrido real de preproducción se registran en la fase 7.

## Evidencias de la fase 4

- La purga requiere una vista previa cifrada, vinculada al usuario y al alcance, con vigencia de quince minutos. Se vuelve a calcular la huella de datos y sus dependencias al ejecutar.
- Solo se aceptan tablas del catálogo permitido. Usuarios, permisos, sucursales, barreras, auditoría y respaldos quedan protegidos.
- Se revisan claves foráneas y referencias polimórficas. La selección de solo `sales` se bloquea si conserva ítems, pagos u otras dependencias.
- En papelera no se eliminan padres con hijos activos. Las eliminaciones y la auditoría de éxito comparten una transacción. El fallo conserva la auditoría del intento y revierte todos los borrados.
- Los ciclos anulables se rompen solo entre registros incluidos en la eliminación. Los ciclos obligatorios se bloquean con una explicación. Las restricciones de integridad permanecen activas durante la purga.
- Una barrera compartida coordina peticiones HTTP mutantes, colas y comandos operativos conocidos; la purga adquiere su barrera exclusiva y un bloqueo nominal que impide otra purga simultánea.
- La barrera usa una conexión adicional mientras dura cada escritura o trabajo. Conserva el paralelismo entre escritores y permite separar los respaldos de la transacción de eliminación. Los cambios administrativos directos en la base deben realizarse fuera de una purga.
- Evidencias: `storage/qa/phase4.xml` (20 aprobadas, 105 aserciones), `storage/qa/phase4-queue.xml` (1 aprobada, 5 aserciones). La regresión HTTP de permisos también fue aprobada al incorporar la barrera.
- Compilación Vite aprobada en `storage/qa/build-phase4.txt`. Correlativos, reinicio de identificadores y restauración verificable se completan en la fase 5.

## Evidencias de la fase 5

- La numeración está desactivada por defecto en purgas parciales y siempre desactivada en papelera. La selección se limita a tipos y sucursales incluidos; se comprueban documentos conservados, incluso eliminados lógicamente. La emisión HTTP posterior produce el siguiente número sin duplicar documentos.
- El reinicio de identificadores solo se permite en reinicio completo con dependencias vacías y sin referencias históricas protegidas. Su DDL se ejecuta después del commit. Se inyectó un fallo y se comprobó que el resultado conserva el borrado confirmado y comunica el fallo por separado; posteriormente se verificó la inserción con identificador uno.
- Los respaldos se escriben por flujo y utilizan nombres únicos, tamaño, SHA-256 y un manifiesto por tabla. Se rechazaron una escritura fallida y un archivo alterado. El respaldo JSON incluyó 5.003 productos sin truncamiento.
- El respaldo SQL reconstruyó realmente la base temporal y sus datos coincidieron con el manifiesto, sin referencias huérfanas. También se restauró sobre la base original de pruebas, manteniendo la integridad y cancelando las purgas pendientes recuperadas.
- La producción exige respaldo y ensayo de recuperación aunque el cliente desmarque la opción. La falta de configuración o el fallo de escritura impiden eliminar datos.
- Las purgas largas se procesan en una cola dedicada. Se ejecutó un trabajador PHP independiente y se comprobó que una entrega duplicada no repite la purga. Las opciones cifradas no se exponen en la API. El respaldo queda vinculado a la ejecución.
- La invalidación de caché dispone de una salida persistente junto con la transacción del negocio. Una interrupción de caché deja un pendiente recuperable y no comunica una reversión falsa después del commit.
- Evidencias: `storage/qa/phase5-final.xml` (29/136), `phase5-required-backup.xml` (2/8), `phase5-execution.xml` (3/29), `phase5-identifiers.xml` (1/10), `phase5-cache-regression.xml` (26/170) y `build-phase5.txt`. Las cifras se solapan entre ejecuciones; no representan pruebas únicas sumables.
- Preparación operativa y límites de restauración: `docs/mantenimiento-seguro.md`. Los respaldos históricos sin manifiesto requieren recuperación manual ensayada; no se afirma su restauración automática.

## Evidencias de la fase 6

- Los comandos Git utilizan argumentos estructurados y repositorios temporales. Se comprobó que el fallo de descarga conserva los cambios, que el destino se resuelve antes de modificar archivos y que el bundle, parches y archivos nuevos permiten reconstruir el estado anterior.
- Se verificaron mensajes con separadores, renombrados, índice preparado, exclusión de secretos, colisiones con datos operativos, exclusión mutua y fallos de limpieza y compilación. El despliegue exige dependencias, frontend, migraciones, cachés y reinicio supervisado; sus errores no informan éxito.
- La interfaz registra actualizaciones en una cola. Una prueba con la cola real confirmó que el trabajo persiste después de terminar la petición HTTP. Las conexiones de coordinación no capturan ni descartan sus callbacks de confirmación.
- Evidencias locales: `phase6-regression.xml` (17/122), `phase6-controls.xml` (4/19), `phase6-gates.xml` (7/37), `phase6-portable.xml` (19/101) y `commit-callback-regression.xml` (15/190). Son ejecuciones solapadas.
- Actualización real de Ubuntu desde `fuente-d04495288ba2a92a36eaf862` a `fuente-636ba33034f7bd8a9b0c3f05`, versión `qa-fase6b`. Imagen resultante: `sha256:58363a6dcf811cdc67ca33583e7d4415fe0d4c28f9eb669c3d3b2ab38f4012c9`. El script terminó con código 0 después de verificar respaldo, recuperación temporal, migraciones, salud, revisión, frontend y reinicio de procesos.
- La transferencia fue directa por SSH/SCP y su SHA-256 se comprobó antes de extraer. No se publicó en GitHub ni se modificó producción real. La instalación Docker muestra metadatos de la imagen y mantiene deshabilitadas las operaciones Git sobre su sistema de archivos.

## Evidencias de la fase 7

Aceptación del 20 de octubre de 2026, limitada a local y preproducción según el alcance autorizado.

| Entorno | Resultado final | Evidencia |
| --- | --- | --- |
| Windows local, PHP 8.2.12, MariaDB 10.4.32 aislada | 496 aprobadas, 3.481 aserciones, cero fallos, errores u omisiones | `storage/qa/production-readiness-local.xml`, `storage/qa/local-acceptance.json` |
| Ubuntu Docker, PHP 8.3, MySQL 8.0.46 desechable | 496 aprobadas, 3.481 aserciones, cero fallos, errores u omisiones | `storage/qa/production-readiness-mysql8.xml` |
| Recuperación con caché y cola activas, MySQL 8 | 13 aprobadas, 104 aserciones; ejecución adicional a la suite completa | `storage/qa/recovery-runtime-mysql8.xml` |
| HTTP sobre la imagen final de preproducción | Compra recibida, dos pagos, reserva, rechazo sin cambios, liberación, anulación integral y reintento sin duplicar reversos | `storage/qa/published-preproduction-flow.txt` |
| Purga y recuperación HTTP en cola | Proveedor eliminado y recuperado, numeración de ventas intacta y restricciones activas | `storage/qa/recovery-preproduction-maintenance.txt` |

Las cifras por motor corresponden a la misma matriz; no deben sumarse como pruebas únicas. Las ejecuciones parciales de fases anteriores se solapan con estas suites completas.

El ensayo detectó una carrera real al restaurar tablas de caché mientras las consultas de progreso y los trabajadores seguían escribiéndolas. Se reprodujo primero con una prueba fallida y se corrigió conservando las tablas efímeras existentes, sin resucitar trabajos consumidos ni sesiones antiguas. Cuando falta una de esas tablas se reconstruye su estructura sin recuperar sus filas. El ensayo temporal sigue cotejando todas las tablas; la recuperación original coteja todos los datos persistentes y comprueba la integridad referencial de todas las tablas. La matriz incluye caché ausente, caché activa, reinicio de trabajadores, permisos e invalidación fallida recuperable.

También se corrigió la creación HTTP de compras sin descripción opcional. Las pruebas de configuración comparan el JSON persistido por cada motor para verificar ausencia de mutaciones sin depender del orden de claves que MySQL normaliza. Los permisos de directorios extraídos con umask privado se normalizan durante la construcción de la imagen.

La recuperación real `a306fb9e-c076-4bce-b975-1447084e967f` terminó el `2026-10-20T13:55:33Z`, después de reconstruir y verificar los datos. El recorrido confirmó el proveedor recuperado y el correlativo de ventas conservado. Los fallos previos permanecen registrados como fallos y no se presentan como recuperaciones exitosas.

Publicación final: `qa-validada-20261020`, revisión `fuente-419faba9308b00bb9a2118b9`, imagen `sha256:1267512c73bc6bee109387c97f80fdba851ba5f23c0fb2785c57ca0b260f3b62`. El script terminó con código 0 y el recorrido HTTP posterior también terminó con código 0. La entrega contiene el mismo código PHP, frontend, pruebas y archivos de bloqueo del candidato sometido a recuperación y suite MySQL; el manifiesto solo diferencia la normalización de permisos en Dockerfile y su documentación. El SHA-256 del archivo transferido es `718eb6c99930c75908db0d72a2c60c3168d422bf62542a5c785f9c5fd5346f07`.

El despliegue construyó el frontend, ensayó su respaldo previo en la base temporal, sustituyó la imagen, verificó conexión, revisión y migraciones y recreó los procesos del proyecto. Se conservan los respaldos y las imágenes anteriores. La recuperación de datos no se confunde con una reversión transaccional de DDL ni con una reversión automática de migraciones.

La purga queda habilitada únicamente en la preproducción cuya matriz fue aprobada. Git permanece deshabilitado dentro de Docker, donde no existe repositorio operativo; su actualización se realiza desde el anfitrión. Sus operaciones y recuperación se verificaron en repositorios temporales. No se subió código a GitHub ni se desplegó producción Windows.

La comprobación final confirmó `APP_ENV=production`, depuración desactivada, MySQL 8.0.46, integridad referencial activa, cero invalidaciones pendientes y servicio abierto. Aplicación y cola general tienen sondeos saludables; programador y trabajador de mantenimiento tienen sus procesos esperados activos. Los cuatro utilizan la misma imagen. La imagen normal no contiene Git ni Pest y sí incluye `pcntl`. `/health` y `/login` respondieron 200; no quedaron migraciones ni recursos frontend pendientes.

Las evidencias del servidor se copiaron al proyecto local y se verificó su SHA-256: `b433868495d3a123a9b1e6725d14c86d8f87bc270a1a5b940d1ec8e1253ddc24`, archivo `storage/qa/final-acceptance-evidence.tar.gz`. Los informes resumidos son `storage/qa/final-acceptance.json` y `storage/qa/published-runtime.json`; los registros de despliegue también están en `storage/qa`. El manifiesto verificó las fuentes del servidor y la comparación local confirmó coincidencia del código publicado.

Se retiró únicamente el proyecto MySQL desechable `calmina-validacion` y se detuvo la instancia local de pruebas del puerto 3307 tras comprobar su directorio de datos. La aplicación de preproducción y sus volúmenes permanecen disponibles. No se gestionaron contenedores de otros proyectos ni la base local operativa del puerto 3306.

Las siete fases quedan completadas para el alcance local y preproducción autorizado. Acceso y operación: `docs/entrega-preproduccion.md`. Esta aceptación no certifica un despliegue en Windows Docker de producción, que fue expresamente excluido.
