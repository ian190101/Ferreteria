# Publicación y recuperación de versiones

## Docker en Ubuntu y Windows

La aplicación no necesita Git ni acceso al socket Docker dentro del contenedor. El panel muestra la versión, la revisión y la fecha incorporadas a la publicación. `DEPLOY_PIPELINE_URL` permite vincular un flujo HTTPS existente; si no está configurado, no se presenta un botón que simule actualizar la imagen.

El flujo ejecutable de esta entrega reside en el equipo anfitrión. Ubuntu utiliza:

```bash
bash scripts/Deploy-Compose.sh calmina-preproduccion .env.docker.local VERSION REVISION --initial
```

Windows con Docker Desktop utiliza:

```powershell
./scripts/Deploy-Compose.ps1 -Project calmina-produccion -EnvironmentFile .env.docker.local -Version VERSION -Revision REVISION -Initial
```

`--initial` o `-Initial` solo se permiten cuando el proyecto no tiene una instalación previa. En actualizaciones deben omitirse. No se eliminan volúmenes ni se gestionan contenedores de otros proyectos. La aplicación se publica en loopback por defecto; `LOCAL_HTTP_BIND` permite configurar expresamente otra interfaz.

Los scripts verifican Docker y Compose, construyen una imagen identificada, detienen los escritores del proyecto y generan un respaldo con ensayo real de recuperación antes de reemplazar una instalación existente. Después arrancan la nueva aplicación, exigen que terminen migraciones y cachés, verifican su salud, revisión, conexión y frontend y reinician sus trabajadores. Registran el identificador de la imagen nueva y de la anterior. Solo la terminación de todas las etapas informa éxito.

El destino de recuperación se crea mediante `docker/init-maintenance.sh` en volúmenes nuevos. `RESTORE_DB_PASSWORD` debe contener entre 24 y 128 caracteres alfanuméricos, guion o guion bajo, generados de forma exclusiva. El usuario `calmina_restore` solo tiene permisos sobre la base desechable. En una instalación existente sin ese destino, el administrador debe provisionarlo antes de actualizar; no debe borrar el volumen para ejecutar nuevamente el inicializador.

Para un entorno de pruebas puede usarse `INSTALL_DEV_DEPENDENCIES=true`. La publicación normal instala dependencias PHP sin paquetes de desarrollo. El archivo de entorno y las credenciales permanecen fuera del archivo de entrega y de la imagen.

El arranque inicial admite treinta minutos para migraciones y preparación: en el servidor de ensayo se observaron veintiún minutos. El proceso no abre el servicio hasta verificar revisión, migraciones y frontend. Un vencimiento informa despliegue incompleto; no equivale a haber revertido la base ni autoriza a interrumpir una migración en curso.

La entrega directa se prepara con `scripts/build_release_archive.py`; el archivo incluye un manifiesto de fuentes y una revisión derivada de sus huellas, sin `.git`, archivos de entorno privados, respaldos ni datos operativos. Verificar el SHA-256 en destino antes de extraerlo. No necesita publicar código ni descargarlo desde GitHub.

Extraer cada entrega en una carpeta nueva de `releases/REVISION`, sin mezclarla con la carpeta de una versión anterior. Ejecutar su script con la ruta absoluta del archivo de entorno del anfitrión y el mismo nombre de proyecto Compose. Así el contexto de construcción contiene únicamente las fuentes de esa entrega, sin archivos obsoletos, registros o archivos de entrega anteriores. Los volúmenes conservan su identidad por el nombre del proyecto. Los registros y evidencias del anfitrión deben guardarse fuera de la carpeta de fuentes.

La construcción normaliza la lectura de los directorios de código dentro de la imagen, aunque las fuentes se hayan extraído con un umask privado. El directorio del anfitrión y su archivo de entorno conservan su protección; PHP y Nginx pueden leer los archivos publicados sin conceder escritura sobre el código al usuario del servicio.

## Repositorio escribible

Las operaciones de Git se serializan mediante un bloqueo por ruta. Se valida la raíz exacta, la rama activa, el remoto y el commit resuelto. Un fallo de `fetch` impide el restablecimiento. El avance normal requiere un árbol limpio y un avance directo; la divergencia no se resuelve automáticamente.

Antes de modificar archivos se crea un directorio privado `storage/app/private/git-maintenance/recovery-*` con:

- `repository.bundle`: historial y referencias locales verificadas por Git.
- `changes.zip`: parche del árbol, parche del índice y archivos nuevos de código que podrían limpiarse.
- `manifest.json`: repositorio, commit anterior, destino y SHA-256 de los archivos de recuperación.

Los archivos operativos y secretos se excluyen del commit general. Un secreto previamente preparado en el índice bloquea el commit. Los datos operativos ignorados permanecen en el árbol; si el destino colisiona con un archivo excluido, la actualización se rechaza antes de modificarlo.

Configurar `DEPLOY_RESTART_COMMAND_JSON` como un array JSON de argumentos del reinicio supervisado del servidor web, por ejemplo `'["supervisorctl","restart","php-fpm"]'`. El reinicio de trabajadores utiliza `queue:restart` para terminar el trabajo actual antes de salir. La configuración debe garantizar que el supervisor vuelve a iniciarlos. La aplicación rechaza el despliegue si no hay un reinicio configurado; no interpreta texto de comandos mediante una shell.

`MAINTENANCE_GIT_UPDATES_ENABLED` permanece desactivado por defecto. Solo se habilita para un repositorio escribible cuyo flujo de reinicio y recuperación haya sido comprobado. En Docker sin repositorio permanece desactivado: la actualización se ejecuta desde el anfitrión con los scripts de publicación.

Las solicitudes largas se ejecutan en la cola `maintenance`. El flujo exige respaldo verificado, mantenimiento, Composer desde lock, instalación y compilación frontend, migraciones, cachés, reinicio y apertura. Si una etapa falla, el resultado conserva el destino y la recuperación y no anuncia éxito. La instalación puede permanecer en mantenimiento hasta resolver el fallo.

## Recuperación tras un fallo

Conservar la imagen anterior y el respaldo SQL. Antes de reabrir una aplicación, comprobar la compatibilidad entre código y esquema: volver a una imagen anterior no revierte migraciones. La recuperación de datos utiliza el respaldo ensayado y una ventana de mantenimiento.

Para recuperar código de un repositorio, clonar el `repository.bundle` en otra carpeta, seleccionar el `before_commit` del manifiesto y aplicar `working-tree.patch`. El archivo `index.patch` permite reconstruir el estado preparado por separado; los archivos bajo `untracked/` se recuperan desde el ZIP después de revisar sus rutas. La prueba automatizada reconstruye un repositorio de esta manera y compara los contenidos recuperados.

Las operaciones administrativas externas deben respetar la ventana de mantenimiento. El bloqueo de la aplicación no serializa comandos Git manuales de otros procesos ni escritores directos de la base.
