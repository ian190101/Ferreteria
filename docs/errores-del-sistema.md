# Diagnósticos de errores

Las respuestas de excepción incluyen explicación en español, código de aplicación, estado HTTP, tipo técnico, mensaje técnico y referencia UUID. Cuando el controlador de base de datos los proporciona, se conservan SQLSTATE y código nativo. La referencia también se registra en el log del servidor.

La validación conserva sus errores por campo y su redirección habitual. Inertia recibe el diagnóstico en `systemError`; las solicitudes JSON lo reciben en `system_error`. Las respuestas mantienen su estado HTTP y añaden `X-System-Error-Code` y `X-Error-Reference`. Las visitas HTML cuentan con una página de emergencia que no necesita consultar la base.

La interfaz presenta el diagnóstico en formularios autenticados y de acceso, respuestas de Axios y fetch, fallos de navegación Inertia y errores no controlados de JavaScript. Un límite de errores de React evita dejar la pantalla completamente vacía cuando falla su renderizado. Si no llega un diagnóstico del servidor, se muestra el código HTTP o del cliente disponible; no se inventa un código nativo.

Los mensajes se representan como texto. Se ocultan credenciales, tokens y claves privadas; en errores SQL no se envían consultas ni bindings, y se ocultan los valores entre comillas del mensaje del controlador. No se envían trazas ni archivos internos, incluso con debug activo.

Compras y Pagos a proveedores comparten la confirmación de anulación. Los rechazos muestran su diagnóstico y las operaciones exitosas muestran confirmación. No se modificaron las condiciones de inventario ni se repararon datos históricos automáticamente.

La compilación local se realiza con `node node_modules/vite/bin/vite.js build`, sin regenerar rutas ni modificar `.env`. Las pruebas automatizadas están en `tests/Feature/SystemErrorResponseTest.php` y `tests/Frontend/systemErrorDetails.test.mjs`; las pruebas de anulación y pagos existentes cubren la regresión. Los ensayos de navegador usan exclusivamente `calmina_qa_void_20261021`, nunca TiDB ni la base visible de trabajo.
