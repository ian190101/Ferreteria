# Despliegue de prueba en Render + TiDB Cloud

## Estado recomendado

- Subir el codigo a GitHub sin `.env`, sin logs, sin backups y sin datos locales.
- Usar TiDB Cloud como base MySQL compatible.
- Configurar variables desde `.env.render.example` en el panel de Render.

## Variables criticas

- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_URL=https://TU-SERVICIO.onrender.com`
- `TRUSTED_PROXIES=*`
- `SESSION_SECURE_COOKIE=true`
- `DB_CONNECTION=mysql`
- `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` desde TiDB Cloud.
- `MYSQL_ATTR_SSL_CA=/etc/ssl/certs/ca-certificates.crt`

## Comandos de despliegue

Render recomienda Docker para Laravel/PHP. Si se usa un runtime con PHP disponible, los pasos son:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

El servidor debe apuntar a la carpeta `public`.

## Advertencias para prueba gratuita

- Render Free puede suspender la app por inactividad; el primer acceso despues de dormir puede tardar.
- El disco local de Render no debe tratarse como persistente para datos criticos. La informacion real debe vivir en TiDB.
- Los backups descargables guardados en `storage/app/backups` pueden perderse en redeploy/restart si no hay disco persistente.
- Para una prueba de clientes, usar usuarios de prueba y no datos sensibles reales.
