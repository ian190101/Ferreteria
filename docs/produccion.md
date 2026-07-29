# Manual tecnico de produccion

Este documento resume la puesta en marcha de una instancia web por cliente. No es un instalador de escritorio: es el procedimiento para dejar una instalacion Laravel operativa, auditable y mantenible.

## Requisitos minimos

- PHP compatible con Laravel 12 y extensiones comunes: `pdo_mysql`, `openssl`, `mbstring`, `xml`, `ctype`, `json`, `fileinfo`, `tokenizer`, `dom`, `curl`, `zip`.
- MySQL/MariaDB o TiDB compatible.
- Node.js solo para compilar assets antes de desplegar.
- HTTPS obligatorio en produccion.
- Cron habilitado para ejecutar `php artisan schedule:run` cada minuto.
- Permisos de escritura en `storage` y `bootstrap/cache`.

## Variables criticas

- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_URL=https://dominio-del-cliente.com`
- `DB_*` con la base de datos del cliente.
- `CACHE_STORE=redis` si existe Redis estable; si no, `database`.
- `SESSION_DRIVER=redis` o `database`.
- `QUEUE_CONNECTION=database` o `redis`.
- `TRUSTED_PROXIES=*` solo si el hosting esta detras de proxy confiable.
- Variables `SIAT_*` y `SIAT_TEST_*` segun ambiente de prueba u oficial.

## Puesta en marcha

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan key:generate --force
php artisan migrate --force
php artisan app:setup-client --business-name="Nombre cliente" --branch-name="Sucursal Central" --admin-email="admin@cliente.com" --admin-password="TemporalSeguro123"
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan production:readiness-check
```

Luego el superadmin del cliente debe cambiar su contrasena temporal. El usuario `sistemasuperadmin` se usa solo para configuracion tecnica interna.

## Cron y tareas programadas

```bash
* * * * * cd /ruta/app && php artisan schedule:run >> /dev/null 2>&1
```

El schedule ejecuta:

- `production:backup-scheduled`: backup automatico y retencion.
- `siat:daily-maintenance`: renovacion CUFD y sincronizacion de catalogos si esta activo.
- `production:health-check`: chequeo tecnico periodico.
- `production:cleanup`: limpieza productiva diaria.

Si `QUEUE_CONNECTION` es `database` o `redis`, mantener un worker activo:

```bash
php artisan queue:work --sleep=3 --tries=3 --timeout=120
```

## Actualizaciones

Antes de subir una nueva version:

```bash
php artisan production:pre-update --target=1.0.x
```

Luego de subir codigo:

```bash
php artisan production:migrate-safe
npm run build
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan production:readiness-check
```

El sistema genera backup previo y registra logs productivos. El rollback de datos se ejecuta desde `Sistema > Centro de produccion > Backups y rollback`, usando solo backups SQL y confirmando con la palabra `RESTAURAR`.

En emergencia tambien se puede restaurar por consola:

```bash
php artisan production:restore-backup ID_DEL_BACKUP --confirm=RESTAURAR
```

## Backups

- Usar SQL para restauracion completa.
- Usar JSON para respaldo operativo parcial.
- Verificar cada backup desde la interfaz.
- Mantener una copia externa al hosting.
- Configurar correo tecnico para recibir alerta si falla un backup, SIAT o el chequeo de salud.

## Importacion masiva

Desde `Sistema > Centro de produccion > Importacion masiva` se aceptan:

- CSV o TXT con encabezados.
- Excel `.xlsx` con encabezados en la primera hoja.
- PDF con tabla de texto legible. Un PDF escaneado como imagen requiere OCR externo y no debe usarse como fuente confiable.

Modulos soportados: productos, clientes y proveedores. En productos se puede cargar stock inicial por sucursal.

Plantillas base:

- `docs/import_templates/productos.csv`
- `docs/import_templates/clientes.csv`
- `docs/import_templates/proveedores.csv`

## Asistente inicial

El usuario `sistemasuperadmin` puede ejecutar `Sistema > Centro de produccion > Asistente inicial` para preparar una instalacion nueva sin consola:

- Nombre del negocio.
- Sucursal inicial.
- Branding base.
- Usuario superadministrador del cliente con clave temporal.
- Catalogos minimos: moneda BOB, tipo ocasional, metodos de pago base y anticipo inicial.

El superadministrador del cliente queda obligado a cambiar su contrasena en el primer ingreso.

## Licencia y permisos finos

La licencia se controla por instalacion y puede ser offline, online o hibrida. Para ventas por cliente independiente se recomienda `offline` por defecto y soporte manual por dominio/NIT.

Proceso de soporte recomendado:

1. El cliente autoriza soporte tecnico.
2. Mr. Robot Bolivia ingresa con `sistemasuperadmin`.
3. Se revisa `Sistema > Centro de produccion`.
4. Toda accion queda registrada en auditoria/logs productivos.
5. Se cierra soporte y se entrega resumen de cambios.

Los permisos finos permiten separar:

- Ver costos.
- Anular ventas.
- Anular pagos.
- Exportar datos sensibles.
- Aplicar descuentos especiales.

## SIAT/SIN

Para pruebas:

- Configurar ambiente piloto.
- Aplicar preset interno solo con `sistemasuperadmin`.
- Registrar token delegado, NIT, codigo de sistema, sucursal y punto de venta.
- Solicitar CUIS.
- Solicitar CUFD.
- Sincronizar catalogos.
- Homologar productos.
- Emitir, verificar y anular facturas segun los casos del SIN.

Para produccion:

- Cambiar ambiente a produccion.
- Usar token oficial del cliente.
- Renovar CUFD diariamente.
- Mantener logs SIAT exportables.

## Verificacion visual

```bash
php artisan production:visual-smoke
```

La prueba visual completa debe hacerse con navegador real en mobile, tablet y escritorio, verificando login, ventas, POS, caja, impresion, plantillas, compras, inventario, reportes y facturacion. Para POS offline se debe probar: desconectar red, generar recibo temporal, reconectar, enviar cola pendiente y validar que caja/bancos queden conciliados.
