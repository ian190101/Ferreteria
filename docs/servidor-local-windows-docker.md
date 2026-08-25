# Servidor local Windows con Docker

Esta configuracion es para instalar una instancia unica del ERP/POS en la PC central de la oficina. No reemplaza la configuracion de Render: Render sigue usando `Dockerfile` y `render.yaml`.

## Arquitectura local

- `app-ferreteria`: Laravel, PHP-FPM, Nginx y assets compilados.
- `db-ferreteria`: MySQL 8 con volumen persistente.
- `queue-ferreteria`: worker de colas para exportaciones, reportes y trabajos pesados.
- `scheduler-ferreteria`: ejecuta `php artisan schedule:run` cada minuto para backups y tareas programadas.
- `mysql_data`: conserva la base de datos aunque se reinicien los contenedores.
- `app_storage`: conserva archivos, adjuntos, logs, sesiones y backups del sistema.
- `CACHE_STORE=database`: recomendado para servidor local con app, cola y scheduler porque evita errores de cache por carpetas borradas en volumenes Docker/Windows.

## Preparacion inicial

1. Copiar el archivo de ejemplo:

```powershell
Copy-Item .env.docker.local.example .env.docker.local
```

2. Generar una clave de aplicacion:

```powershell
docker run --rm -v ${PWD}:/app -w /app php:8.3-cli php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

3. Pegar el valor generado en `APP_KEY`.

4. Cambiar obligatoriamente:

- `DB_PASSWORD`
- `MYSQL_ROOT_PASSWORD`
- `SYSTEM_SUPERADMIN_EMAIL`
- `SYSTEM_SUPERADMIN_PASSWORD`

5. Si se accedera desde otras sucursales por IP local o VPN, cambiar:

```env
APP_URL=http://IP_DE_LA_PC_CENTRAL
```

Ejemplo:

```env
APP_URL=http://192.168.1.10
```

## Levantar el servidor

```powershell
docker compose -f docker-compose.local.yml --env-file .env.docker.local up -d --build
```

Ver estado:

```powershell
docker compose -f docker-compose.local.yml --env-file .env.docker.local ps
```

Ver logs:

```powershell
docker compose -f docker-compose.local.yml --env-file .env.docker.local logs -f app-ferreteria
```

## Acceso desde la oficina

Si `LOCAL_HTTP_PORT=80`, el sistema abre en:

```text
http://localhost
```

Desde otra PC de la red:

```text
http://IP_DE_LA_PC_CENTRAL
```

En Windows puede ser necesario:

- liberar el puerto 80 si IIS, Skype u otro servicio lo esta usando;
- permitir Docker Desktop en el firewall;
- fijar IP estatica para la PC central;
- usar cable de red o Wi-Fi estable;
- configurar VPN si las sucursales estan fuera de la misma red.

Si el puerto 80 esta ocupado, usar temporalmente:

```env
LOCAL_HTTP_PORT=8080
APP_URL=http://IP_DE_LA_PC_CENTRAL:8080
```

## Backups

El sistema ya tiene centro de backups y scheduler. Para que los backups no se pierdan:

- mantener el volumen `app_storage`;
- descargar backups importantes desde la interfaz;
- copiar periodicamente backups a un disco externo o nube;
- probar restauracion en una copia antes de confiar el procedimiento.

Comando manual recomendado antes de cambios importantes:

```powershell
docker compose -f docker-compose.local.yml --env-file .env.docker.local exec app-ferreteria php artisan production:backup-scheduled
```

## Actualizar codigo

1. Generar backup desde la interfaz o por comando.
2. Descargar el backup.
3. Actualizar el repositorio.
4. Reconstruir:

```powershell
docker compose -f docker-compose.local.yml --env-file .env.docker.local up -d --build
```

El contenedor ejecuta migraciones con `RUN_STARTUP_MIGRATIONS=true`.

## Recomendaciones para varias sucursales

- La PC central debe tener UPS.
- Usar IP estatica.
- Evitar apagar la PC durante horario operativo.
- Configurar copias externas diarias.
- Para sucursales remotas, usar VPN o tunel seguro; no exponer MySQL a internet.
- Exponer solo HTTP/HTTPS hacia el ERP, nunca el puerto `3306`.
- Para futuro respaldo, preparar una segunda PC o VPS con restauracion diaria probada.

## Variables que no deben quedar vacias

- `APP_KEY`
- `DB_PASSWORD`
- `MYSQL_ROOT_PASSWORD`
- `SYSTEM_SUPERADMIN_EMAIL`
- `SYSTEM_SUPERADMIN_PASSWORD`

No subir `.env.docker.local` a GitHub.

## Ajuste recomendado si hay errores 500 al filtrar

Si aparece un error 500 en el panel o reportes despues de limpiar cache, confirma que el archivo `.env.docker.local` use:

```env
CACHE_STORE=database
QUEUE_CONNECTION=database
```

Luego ejecuta:

```powershell
docker compose -f docker-compose.local.yml --env-file .env.docker.local exec app-ferreteria php artisan optimize:clear
docker compose -f docker-compose.local.yml --env-file .env.docker.local restart app-ferreteria queue-ferreteria scheduler-ferreteria
```
