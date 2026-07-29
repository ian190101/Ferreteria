# Checklist final para produccion real

Usa este checklist por cada cliente/instalacion. No marques un punto como cerrado si no fue probado en el hosting real.

## 1. Entorno

- `APP_ENV=production`.
- `APP_DEBUG=false`.
- `APP_URL` con `https://`.
- Base de datos creada y conectada.
- Redis configurado si el hosting lo permite; si no, `CACHE_STORE=database`.
- `QUEUE_CONNECTION=database` o `redis`, no `sync`.
- `SESSION_SECURE_COOKIE=true`.
- `TRUSTED_PROXIES=*` solo si el hosting esta detras de proxy confiable.
- `storage` y `bootstrap/cache` con escritura habilitada.

Comando:

```bash
php artisan production:readiness-check
```

## 2. Instalacion inicial

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

Desde UI tambien puede ejecutarse: `Sistema > Centro de produccion > Asistente inicial`.

## 3. Cron y colas

Cron obligatorio:

```bash
* * * * * cd /ruta/app && php artisan schedule:run >> /dev/null 2>&1
```

Queue worker recomendado:

```bash
php artisan queue:work --sleep=3 --tries=3 --timeout=120
```

## 4. Backups y rollback

- Generar backup SQL.
- Descargar backup.
- Verificar backup desde UI.
- Restaurar backup en una copia de la BD antes de confiarlo en produccion.
- Confirmar que el correo tecnico recibe alerta si falla un backup.

Rollback UI: `Sistema > Centro de produccion > Backups y rollback`.

Rollback consola:

```bash
php artisan production:restore-backup ID_DEL_BACKUP --confirm=RESTAURAR
```

## 5. Licencia y soporte

Politica recomendada para venta por instalacion:

- `offline` por defecto.
- Dominio y NIT registrados.
- Fecha de soporte visible.
- Modulos permitidos registrados.
- Usuario `sistemasuperadmin` solo para soporte tecnico autorizado.

Regla operativa: el cliente usa `superadmin`; Mr. Robot Bolivia usa `sistemasuperadmin`.

## 6. Actualizaciones

Antes de actualizar:

```bash
php artisan production:pre-update --target=VERSION_NUEVA
```

Despues de subir codigo:

```bash
php artisan production:migrate-safe
npm run build
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan production:readiness-check
```

Registrar changelog tecnico: version, fecha, modulos tocados, migraciones aplicadas, backup previo y resultado.

## 7. Datos iniciales

Importar desde `Sistema > Centro de produccion > Importacion masiva`:

- Productos.
- Clientes.
- Proveedores.
- Stock inicial por sucursal.
- Precios.
- Codigos de barras.

Usar las plantillas en `docs/import_templates`.

## 8. SIAT/SIN

No facturar oficialmente hasta completar:

- Token y NIT reales/piloto.
- CUIS solicitado.
- CUFD vigente.
- Catalogos sincronizados.
- Productos homologados.
- Emision de factura.
- Anulacion de factura.
- Evento significativo y paquete, si aplica.

## 9. Pruebas visuales y fisicas

Probar en navegador real:

- Mobile.
- Tablet.
- Laptop/desktop.
- Claro/oscuro.
- Ventas/cotizaciones/notas.
- POS con lector de barras.
- Caja y QR.
- Compras e inventario.
- Reportes/exportaciones.
- Facturacion.

Auditoria automatizada recomendada:

```bash
PLAYWRIGHT_BASE_URL=https://tu-dominio.com E2E_EMAIL=usuario.qa@dominio.com E2E_PASSWORD=clave-segura npm run test:e2e
```

Usar un usuario QA con datos controlados. Si falla, revisar capturas, video y trace generados por Playwright.

Probar impresoras:

- Carta.
- Media carta.
- Termica 58/80mm.
- Etiquetas barcode.
