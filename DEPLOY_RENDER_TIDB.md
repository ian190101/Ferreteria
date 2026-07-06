# Despliegue de prueba en Render + TiDB Cloud

## Estado recomendado para esta prueba

- Subir el codigo a GitHub sin `.env`, sin logs, sin backups y sin datos locales.
- Usar TiDB Cloud como base MySQL compatible.
- Configurar variables desde `.env.render.example` en el panel de Render.
- Usar Docker solo para Render Free durante la prueba. El despliegue real puede migrar luego a hosting nativo Laravel.

## Variables criticas

- `APP_KEY`: generar con `php artisan key:generate --show` y copiar el valor en Render.
- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_URL=https://TU-SERVICIO.onrender.com`: ajustar cuando Render entregue la URL final.
- `TRUSTED_PROXIES=*`
- `SESSION_SECURE_COOKIE=true`
- `DB_CONNECTION=mysql`
- `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` desde TiDB Cloud.
- `MYSQL_ATTR_SSL_CA=/etc/ssl/certs/ca-certificates.crt`

## Despliegue en Render con Docker

El repositorio incluye:

- `Dockerfile`
- `.dockerignore`
- `docker/nginx.conf.template`
- `docker/start.sh`
- `render.yaml`

En Render:

1. Crear un nuevo `Web Service`.
2. Elegir el repositorio de GitHub.
3. Runtime: `Docker`.
4. Plan: `Free`.
5. Cargar las variables de entorno de `.env.render.example`.
6. Poner `APP_KEY`, `APP_URL` y datos de TiDB Cloud.

El contenedor ejecuta al arrancar:

- `php artisan migrate --force`
- `php artisan config:cache`
- `php artisan route:cache`
- `php artisan view:cache`
- `php-fpm` + `nginx`

## Advertencias para prueba gratuita

- Render Free puede suspender la app por inactividad; el primer acceso despues de dormir puede tardar.
- El disco local de Render no debe tratarse como persistente para datos criticos. La informacion real debe vivir en TiDB.
- Los backups descargables guardados en `storage/app/backups` pueden perderse en redeploy/restart si no hay disco persistente.
- Para una prueba de clientes, usar usuarios de prueba y no datos sensibles reales.
