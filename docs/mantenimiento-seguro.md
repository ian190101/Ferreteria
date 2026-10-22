# Respaldo, restauración y purga

La purga se registra con un identificador y se ejecuta en una cola exclusiva. La pantalla consulta su progreso y solo informa una operación terminada cuando el trabajador confirma su resultado. El tiempo máximo del trabajo es de 1.800 segundos y la reserva de la cola vence a los 1.860; no debe ejecutarse esta cola con el trabajador general.

## Preparación

1. Aplicar las migraciones de mantenimiento.
2. Configurar `MAINTENANCE_QUEUE_CONNECTION=maintenance`.
3. Crear una base **desechable**, en el mismo motor y servidor, llamada exactamente `DB_DATABASE` seguido de `_restore_verification`. Crear un usuario exclusivo con permisos solo sobre esa base. No asignar al usuario operativo privilegios globales para este ensayo.
4. Configurar `RESTORE_DB_DATABASE`, `RESTORE_DB_USERNAME` y `RESTORE_DB_PASSWORD`. `RESTORE_DB_HOST` y `RESTORE_DB_PORT` vacíos heredan la conexión operativa. Los valores se configuran fuera del repositorio.
5. Iniciar `php artisan queue:work maintenance --queue=maintenance --sleep=2 --tries=1 --timeout=1800`. El archivo `docker-compose.local.yml` incorpora el servicio `maintenance-ferreteria`.
6. Mantener el programador de Laravel en funcionamiento. `production:invalidate-caches`, programado cada minuto, recupera invalidaciones pendientes después de una indisponibilidad de caché.
7. Mantener `MAINTENANCE_PURGE_ENABLED=false` hasta aprobar la matriz del entorno. Habilitarlo expresamente y recrear los procesos con la misma configuración después de aprobarla. La restauración usa un usuario diferente del operativo; se rechaza `root` como usuario del ensayo.

La prueba local usa únicamente las dos bases con prefijo `fabrica_calmina_aroma_testing` y usuarios restringidos. `docker-compose.testing.yml` prepara el equivalente desechable con MySQL 8.0. No se debe reutilizar una base con información operativa como destino del ensayo de restauración.

La imagen instala `pcntl` para aplicar el límite del trabajador y atender señales. Compose concede 1.900 segundos al trabajador de mantenimiento para terminar antes de detenerlo. La cola general utiliza una reserva de 180 segundos con un límite de trabajo de 120; su reserva no debe configurarse por debajo del límite del trabajador.

## Secuencia de una purga

Para una instalación local sin trabajador activo, `MAINTENANCE_PURGE_INLINE=true` ejecuta el mismo trabajo durante la petición de confirmación, conservando vista previa, permisos, respaldo, barrera, transacción y auditoría. El navegador espera el resultado y el servidor admite hasta 1.800 segundos para esa petición. El valor predeterminado es `false`; en producción se mantiene la cola exclusiva para evitar depender de los límites HTTP durante respaldos y purgas grandes. Este ajuste solo afecta a la purga, no a las restauraciones ni a los despliegues.

Si el modo directo está desactivado, `composer run dev` inicia también el trabajador exclusivo de mantenimiento con el límite de 1.800 segundos. Si se inicia la aplicación con `php artisan serve`, hay que ejecutar el comando del paso 5 en otra terminal. La cola general no procesa la cola `maintenance`: sin su trabajador, las solicitudes permanecen pendientes y todavía no eliminan registros.

La casilla **Eliminar también las tablas dependientes por llaves foráneas** amplía el alcance recursivamente a las tablas permitidas con referencias existentes, incluidas las referencias polimórficas reconocidas. Está desactivada por defecto. La vista previa identifica las tablas añadidas y cuenta todos los registros que se eliminarán: esta opción vacía tablas dependientes completas, incluidos registros ajenos a la selección inicial. Las tablas protegidas o fuera del catálogo y los ciclos obligatorios sin estrategia siguen bloqueando la operación. En modo papelera se mantiene el filtro `deleted_at`, por lo que los hijos activos continúan bloqueando el borrado del padre. Cambiar la casilla exige calcular y confirmar una nueva vista previa.

La vista previa caduca a los quince minutos y queda vinculada al usuario y a la base de datos. Si cambian los datos o los correlativos, debe recalcularse. El trabajador adquiere una barrera exclusiva compartida por peticiones mutantes, trabajos y comandos operativos coordinados. Durante el mantenimiento no deben existir escritores externos que omitan esa barrera. Las conexiones de coordinación tienen su gestor de callbacks desacoplado: liberar una barrera no debe descartar envíos de cola ni invalidaciones posteriores al commit del negocio.

En producción el respaldo es obligatorio aunque el cliente lo desmarque. Se escribe por flujo con un nombre único, se comprueban tamaño y SHA-256 y se restaura realmente en la base desechable. Se comparan cantidades, huellas por tabla y claves foráneas. Un fallo detiene la purga antes de eliminar datos. El identificador del respaldo queda asociado a la ejecución, también si falla el ensayo posterior.

Los borrados, el cambio de correlativos autorizado y la auditoría se confirman en una transacción. Las restricciones de integridad siguen activas. En papelera no se modifican correlativos. Los identificadores internos solo se reinician en un reinicio completo permitido y con tablas vacías, después del commit. Su fallo se informa por separado y no significa que se haya revertido el borrado.

## Recuperación

Los respaldos SQL nuevos contienen un manifiesto verificable. Antes de restaurar sobre la base original se repite el ensayo desechable; después de restaurar se compara nuevamente el manifiesto. Se cancelan purgas pendientes recuperadas desde el respaldo para impedir su ejecución accidental.

El ensayo desechable reconstruye y coteja todas las tablas del archivo. Al recuperar la instalación original se conservan las tablas efímeras `cache`, `cache_locks`, `jobs`, `failed_jobs` y `sessions`: las consultas de progreso, los trabajadores y las sesiones pueden seguir usándolas. Si falta alguna, se reconstruye su estructura sin resucitar sus filas antiguas. Los datos persistentes conservan su cotejo completo y se comprueban las claves foráneas también en las tablas efímeras. La recuperación invalida catálogos y autorización, limpia los permisos almacenados y solicita el reinicio de los trabajadores. Un fallo en esta invalidación mantiene un pendiente y deja el servicio en mantenimiento; no se anuncia una recuperación terminada.

La petición HTTP de restauración registra el trabajo y responde inmediatamente. El trabajador de mantenimiento realiza la recuperación; la pantalla consulta `/settings/system/recovery-runs`. Su diario se guarda con permisos privados en `storage/app/private/maintenance-recovery`, fuera de las tablas que serán reemplazadas. Ese directorio debe persistir junto con los respaldos. El diario distingue verificación, restauración y comprobación final; una entrega duplicada no restaura de nuevo y las otras recuperaciones pendientes de esa base quedan canceladas.

La restauración SQL utiliza DDL: no es una operación atómica reversible mediante una transacción. Debe realizarse con una ventana de mantenimiento y conservarse una copia externa del estado previo y del archivo utilizado. Un fallo durante la restauración requiere recuperación operativa; no debe interpretarse como una reversión automática.

Si el trabajo falla después de iniciar la sustitución, activa el modo de mantenimiento y conserva su diagnóstico en el diario privado. La reapertura exige completar la recuperación y la invalidación de cachés; `production:invalidate-caches` puede ejecutarse manualmente durante esa revisión.

Los respaldos históricos sin manifiesto siguen siendo conservables y descargables. Su restauración automática se rechaza: requieren una restauración manual previamente ensayada. El formato JSON permite exportar todos los registros, pero no habilita restauración SQL automática.

Si el proceso termina después de confirmar el borrado y antes de terminar sus tareas posteriores, la ejecución conserva el estado confirmado. Revisar el resultado antes de repetir acciones. Las invalidaciones de caché se guardan junto con el cambio de negocio y se reintentan; su fallo no se presenta como una reversión del cambio confirmado.
