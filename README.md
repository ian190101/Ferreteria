# Fabrica Calmina Aroma

Sistema monolitico modular construido con Laravel 12, Inertia y React.

## Stack

- Backend: Laravel 12, PHP 8.2+
- Frontend: Inertia React, Vite, Tailwind CSS
- Base de datos: MySQL/MariaDB
- Roles y permisos: `spatie/laravel-permission`
- Auditoria: `owen-it/laravel-auditing`

## Modulos iniciales

- `app/Modules/Branches`: sucursales y branding.
- `app/Modules/Inventory`: productos, espesores, stock global, bobinas y movimientos.
- `app/Modules/Purchases`: proveedores, compras e ingreso de mercaderia.
- `app/Modules/Sales`: ventas y descuento de inventario.
- `app/Modules/Shared`: modelos/componentes compartidos.

## Configuracion local

La base local esperada es:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=fabrica_calmina_aroma
DB_USERNAME=root
DB_PASSWORD=
```

Con XAMPP encendido:

```bash
php artisan migrate:fresh --seed
npm run build
php artisan serve
```

Usuario inicial:

- Email: `admin@calmina.local`
- Password: `admin12345`

Cambia esta clave antes de usar datos reales.

## Produccion / hosting

Comandos recomendados despues de configurar `.env` del hosting:

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link
```

Usa `CACHE_STORE=database`, `SESSION_DRIVER=database` y `QUEUE_CONNECTION=database` si el hosting no ofrece Redis. Si el hosting permite Redis, cambia caché/sesiones/colas a Redis para mayor rendimiento.

## Notas tecnicas

- Todos los listados nuevos deben usar `paginate()` desde el servidor.
- Las consultas de listados deben declarar relaciones con `with()` para evitar N+1.
- Los codigos `barcode` son strings unicos e independientes de los ids autoincrementales.
- La auditoria guarda usuario, IP, evento, valores anteriores y nuevos; el modelo `App\Models\Audit` bloquea edicion y eliminacion por Eloquent.
- Los colores de sucursal se inyectan como variables CSS compatibles con Tailwind.
