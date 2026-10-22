# Control de versiones desde el sistema

El panel `Sistema maestro → Control de versiones Git` es exclusivo del rol `sistemasuperadmin`.

## Flujo de trabajo

1. **Revisar estado local** consulta la rama instalada, el commit y los archivos modificados.
2. **Descargar ramas y etiquetas** ejecuta `fetch` sobre `origin`. Actualiza las referencias y etiquetas conocidas; no sustituye el código ni publica cambios.
3. **Cambios locales** permite seleccionar archivos y crear un commit local con un mensaje. Un archivo preparado previamente que no fue seleccionado queda fuera del commit. Los archivos operativos y privados se excluyen según la política del servicio. La identidad y autenticación Git deben estar configuradas para la cuenta del servidor; el panel no pide ni guarda tokens.
4. **Publicar commits en Git** envía los commits de la rama local a la misma rama de `origin`, sin publicación forzada. Si el remoto está adelantado o hay divergencia, Git rechaza la operación y se presenta su diagnóstico. Un push puede activar los despliegues automáticos existentes en GitHub/Render.
5. **Ramas y versiones** muestra ramas locales, ramas de origin y etiquetas; permite buscar y consultar sus commits. Para cambiar a una rama remota que ya existe localmente, se selecciona la rama local y después se actualiza desde Git.
6. **Historial de commits** admite rama, etiqueta o hash, paginación de 30 commits, resumen de archivos y comparación con el código instalado.
7. **Actualizar desde Git** exige trabajo limpio y avance directo. **Sustituir con versión remota** y **Volver a esta versión** guardan la recuperación y mueven la rama local al commit confirmado. No cambian el remoto. Cambiar de rama requiere un árbol limpio.
8. Cada cambio de versión pasa por una vista previa que fija commit de origen y destino, rama y huella del contenido. Su token está cifrado y autenticado, pertenece al usuario, vence en diez minutos y solo puede enviarse una vez. El trabajador vuelve a validar el código antes del cambio; si hubo modificaciones posteriores, se detiene.
9. **Despliegues** muestra el estado real de la cola y el resultado, el commit de destino y la ubicación privada de recuperación cuando existe.

## Despliegue en una instalación con repositorio Git

La instalación debe tener habilitado `MAINTENANCE_GIT_UPDATES_ENABLED`, un `DEPLOY_RESTART_COMMAND_JSON` válido con el comando supervisado real y un trabajador para la conexión configurada en `MAINTENANCE_QUEUE_CONNECTION`, cola `maintenance`. También necesita Git, Composer, Node/npm, permisos sobre el código y la configuración de verificación de restauración del respaldo.

Ejemplo del trabajador: `php artisan queue:work maintenance --queue=maintenance --timeout=1800 --tries=1`. El nombre de conexión debe coincidir con la configuración de esa instalación. El reinicio supervisado no debe matar al trabajador que registra el resultado antes de que finalice.

El flujo existente verifica la recuperación de la base, pone la aplicación en mantenimiento, sustituye código, instala dependencias, compila frontend, ejecuta migraciones pendientes, actualiza cachés, reinicia procesos y abre el servicio. Un fallo no se informa como éxito ni reabre automáticamente la aplicación. La recuperación de código incluye bundle Git, parches y archivos nuevos permitidos, con hashes verificables en `storage/app/private/git-maintenance`.

Volver al código anterior **no revierte migraciones ni restaura datos**. Antes de ejecutar el cambio se debe comprobar que esa versión es compatible con el esquema actual. Restaurar una base exige un procedimiento distinto y puede reemplazar datos de negocio.

## Docker

Las imágenes publicadas sin `.git` muestran la revisión de construcción y el enlace de despliegue configurado en `DEPLOY_PIPELINE_URL`. Para instalar otra revisión se debe construir una imagen nueva y sustituir el contenedor desde el anfitrión o el flujo de despliegue. Este panel no incorpora acceso al socket Docker ni modifica el anfitrión desde un contenedor. No se debe montar el socket Docker para habilitar estos botones.

La publicación y el cambio de versiones se ensayan con repositorios temporales y un remoto bare local. No se publican cambios en GitHub ni se cambia el commit del proyecto operativo para probar el panel.
