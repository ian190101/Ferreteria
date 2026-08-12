# Gate de staging para Multinegocio 2.0

Este documento define el paso obligatorio entre `main` y produccion real. No reemplaza el checklist final; lo ordena para validar que Multinegocio 2.0 no rompa ferreteria ni habilite motores nuevos sin perfil.

## 1. Preparar staging

Staging debe usar una base separada de produccion y un motor MySQL/MariaDB/TiDB. No se considera cierre real si solo se probo con SQLite.

Variables minimas:

```bash
APP_ENV=staging
APP_DEBUG=false
APP_URL=https://staging-tu-dominio.com
DB_CONNECTION=mysql
CACHE_STORE=database
QUEUE_CONNECTION=database
SESSION_SECURE_COOKIE=true
```

Extensiones PHP obligatorias:

- `pdo_mysql`
- `mbstring`
- `xml`
- `curl`
- `fileinfo`
- `zip`

La extension `zip` es necesaria para validar importacion/exportacion XLSX sin saltos de prueba.

## 2. Desplegar codigo

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Si el hosting ejecuta migraciones automaticamente, verificar en logs que no queden migraciones pendientes.

## 3. Ejecutar gate tecnico

Modo normal, permitido para diagnostico:

```bash
php artisan production:staging-gate
```

Modo estricto, obligatorio antes de produccion:

```bash
php artisan production:staging-gate --strict
```

Criterio de cierre:

- `--strict` debe terminar con codigo `0`.
- No debe haber errores ni advertencias.
- Si aparece advertencia de MySQL/MariaDB/TiDB o `ZipArchive`, el entorno no esta listo.

## 4. Ejecutar pruebas automatizadas

```bash
php artisan test
npm run build
```

En staging completo no deben quedar pruebas saltadas por:

- `ZipArchive`.
- Demo completa sin MySQL/MariaDB.

Si se tienen credenciales QA:

```bash
PLAYWRIGHT_BASE_URL=https://staging-tu-dominio.com E2E_EMAIL=usuario.qa@dominio.com E2E_PASSWORD=clave-segura npm run test:e2e
```

## 5. QA manual obligatorio

Validar con usuario real de staging:

- Login y salida.
- Perfil ferreteria por defecto sin cambios visibles inesperados.
- Cotizacion, nota de venta, pago y flujo de efectivo.
- Compra por stock general.
- Compra por lote existente y lote nuevo.
- Inventario central y stock por sucursal.
- Anulacion/devolucion con permiso.
- Exportaciones permitidas y bloqueadas.
- Modulos desactivados ocultos y bloqueados por URL.
- Servicios/transporte solo si el perfil lo activa.
- QR publico solo si el perfil lo activa.

## 6. Cierre

Solo se puede pasar a produccion cuando:

- `production:staging-gate --strict` pasa.
- `php artisan test` pasa sin fallas.
- `npm run build` pasa.
- QA manual queda registrada.
- Existe backup reciente y restauracion probada en copia.

Usar despues `docs/checklist_produccion.md` para la instalacion final.
