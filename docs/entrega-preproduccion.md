# Entrega local y preproducción

El alcance acordado termina en preproducción Ubuntu con datos ficticios. Producción Windows queda fuera de esta entrega. Las fuentes se transfirieron directamente desde el equipo local; no se publicaron en GitHub.

## Instalación entregada

| Elemento | Valor |
| --- | --- |
| Directorio del servidor | `/home/ian/calmina-aroma-preproduccion` |
| Proyecto Compose | `calmina-qa` |
| Versión | `qa-configurada-20261022` |
| Revisión | `fuente-957e95d2a77e90390ac695bb` |
| Fuentes desplegadas | `releases/fuente-957e95d2a77e90390ac695bb` |
| HTTP del servidor | `127.0.0.1:18081` |
| Base aislada | `calmina_preproduccion` |
| Archivo de configuración privado | `.env.docker.local`, fuera de las fuentes y con permisos `0600` |
| Volúmenes persistentes | `calmina-qa_mysql_data`, `calmina-qa_app_storage` |

La cuenta predeterminada del sistema es `mrrobotbolivia@gmail.com`, con el rol `sistemasuperadmin`. Su contraseña se configura en `SYSTEM_SUPERADMIN_PASSWORD` del archivo privado del entorno; no se incluye en las plantillas ni en las evidencias. Por instrucción del usuario se habilitó esta cuenta en preproducción, se recrearon los cuatro procesos con la configuración actualizada y se verificaron su rol y contraseña. Se comprobó el ingreso desde el navegador y su acceso a Configuración empresarial; la captura está en `storage/qa/acceso-mrrobotbolivia.jpg`. La cuenta `qa-preproduccion@example.test` corresponde a los ensayos anteriores; no es la cuenta predeterminada de acceso.

La configuración privada local utiliza la misma cuenta. La base local del puerto 3306 no estaba disponible durante esta activación, por lo que no se afirma haber actualizado allí su registro. Al preparar una instalación nueva, el seeder usa esta configuración; Docker crea o actualiza la cuenta maestra durante el arranque. Los recorridos automatizados usan una cuenta ficticia separada cuya contraseña aleatoria cambia al ejecutar el ensayo.

Para abrir la aplicación desde Windows, mantener este túnel SSH abierto:

```powershell
ssh -N -L 18081:127.0.0.1:18081 -o 'ProxyCommand=cloudflared access ssh --hostname %h' ian@sshterminalservidor.calaminas-aroma.online
```

Después abrir `http://127.0.0.1:18081` en el navegador. El puerto HTTP del servidor permanece limitado a loopback.

## Operación

La aplicación, cola general, cola de mantenimiento y programador utilizan la misma imagen. La cola de mantenimiento dispone de su propio límite y parada gradual. Los respaldos y diarios privados de recuperación persisten en el volumen de almacenamiento de la aplicación. No eliminar esos volúmenes ni las imágenes y respaldos anteriores al resolver una incidencia.

La purga está habilitada después de aprobar sus verificaciones. Cada ejecución exige vista previa vigente, permisos y respaldo con recuperación temporal comprobada. Git permanece deshabilitado dentro de la imagen; el panel identifica la versión publicada y la actualización Docker se ejecuta desde el anfitrión.

Para una nueva entrega, preparar y verificar su archivo de fuentes, extraerlo en un directorio nuevo y ejecutar su script con el mismo proyecto y el archivo privado del anfitrión:

```bash
bash releases/NUEVA_REVISION/scripts/Deploy-Compose.sh calmina-qa /home/ian/calmina-aroma-preproduccion/.env.docker.local NUEVA_VERSION NUEVA_REVISION
```

No añadir `--initial` en una actualización. El script construye, respalda, ensaya la recuperación, aplica migraciones, verifica y sustituye los procesos. Su fallo requiere revisar la etapa informada; cambiar de imagen no revierte automáticamente la base.

## Evidencia y recuperación

La matriz y los resultados están documentados en `docs/validacion-por-fases.md`. Las evidencias locales residen en `storage/qa`; contienen JUnit, recorridos HTTP, comprobación de publicación y el informe final del entorno. No contienen archivos de entorno privados ni respaldos con datos.

El manifiesto y el SHA-256 del archivo de entrega permiten identificar exactamente las fuentes publicadas. `storage/qa/final-acceptance.json` identifica la imagen de cada proceso y los resultados MySQL; `storage/qa/published-runtime.json` registra revisión, integridad y configuración de mantenimiento sin credenciales.

Consultar `docs/mantenimiento-seguro.md` para recuperar datos y `docs/publicacion-y-recuperacion.md` para recuperar código. La restauración real ya fue ensayada sobre esta instalación con datos ficticios. La base Docker desechable de la suite es independiente y se retira al terminar las comprobaciones; la aplicación de preproducción permanece funcionando.

## Actualización del 22 de octubre de 2026

Se transfirió la entrega por SCP y se verificó su SHA-256 antes de extraerla. El despliegue terminó con código 0 mediante el procedimiento de actualización, con respaldo previo y verificación de publicación, conexión, migraciones y frontend. `/health` y `/login` respondieron 200 en la comprobación posterior. La aplicación, ambas colas y el programador utilizan la imagen `sha256:63af5fb9db8744ca55003b1ae6540ca9bb8018857e8a67c4daa6ab389d6ac980`.

El registro remoto está en `/home/ian/calmina-aroma-preproduccion/registros/despliegue-20261022.log`; el resumen local, en `storage/qa/entrega-20261022.json`. Se conserva el archivo privado de entorno del servidor y los volúmenes existentes. Esta comprobación operativa no equivale a una nueva ejecución de la suite completa.

## Transferencia de la base local del 22 de octubre de 2026

Por solicitud del usuario se reemplazó la base de preproducción por una copia consistente de `fabrica_calmina_aroma` local: 157 tablas y 663 registros. La transferencia verificó SHA-256 y ensayó la restauración completa en la base temporal antes de iniciar mantenimiento. Se respaldó también la base anterior de Ubuntu (157 tablas, 637 registros, dos sucursales) y se comprobó su recuperación antes de importar.

La importación cotejó cantidades, huellas por tabla e integridad referencial. Se omitieron sesiones, caché y trabajos pendientes; las solicitudes administrativas pendientes recuperadas se cancelaron para evitar reejecutarlas. No hubo migraciones pendientes. Se limpiaron cachés, se reiniciaron procesos y se comprobó HTTP 200 en `/health` y `/login`. La comprobación posterior confirmó cuatro sucursales, cuatro configuraciones de sucursal, tres perfiles empresariales y seis usuarios. Los usuarios y contraseñas corresponden a la copia local importada; el archivo privado de entorno de Ubuntu se conservó.

Respaldo previo: `/home/ian/calmina-aroma-preproduccion/entregas/base-ubuntu-previa-20261022.sql`, con permisos `0600`, fuera del contenedor. Registro: `/home/ian/calmina-aroma-preproduccion/registros/importacion-base-20261022.log`. Evidencia local sin datos de negocio ni credenciales: `storage/qa/transferencia-base-20261022.json`. No se descargó el respaldo anterior de Ubuntu a Windows.
