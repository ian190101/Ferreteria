# Contexto del proyecto para Aragon

Fecha de revisión: 22 de octubre de 2026. Proyecto: `Fabrica_Calmina_Aroma`.

## 1. Qué es y qué problema resuelve

Es un ERP/POS web modular cuyo caso de origen es una fábrica y comercializadora de calaminas y ferretería. Integra clientes, cotizaciones, notas de venta, cobros, compras, proveedores, inventario por sucursal, bobinas, producción, despachos, caja, bancos, gastos y reportes.

Sobre esa base se construyó una plataforma configurable denominada en la documentación **Multinegocio 2.0**: un perfil empresarial decide qué módulos, campos, documentos, reglas y flujos se habilitan. El mismo código contempla comercio, restaurantes, servicios, talleres, reservas y otros rubros mediante configuraciones y motores transversales.

La prioridad al evolucionarlo es conservar la consistencia entre documentos comerciales, existencias, pagos, saldos y auditoría. Una acción aparentemente local puede afectar varios módulos.

## 2. Fuentes utilizadas y vigencia

Este contexto parte de mapas ya existentes; no se regeneró ni reindexó el proyecto:

- `graphify-out/graph.json`: grafo de símbolos y relaciones.
- `graphify-out/GRAPH_REPORT.md`: comunidades, nodos centrales y limitaciones del análisis.
- `graphify-out/graph.html`: exploración visual existente.
- `graphify-out/manifest.json`: archivos contemplados por el índice.
- `docs/arbol-nodos.md`: árbol de carpetas y archivos generado el 21 de octubre; sus aristas expresan pertenencia, no llamadas.

El grafo contiene **6.979 nodos, 19.474 relaciones y 344 comunidades**. El informe muestra 191 comunidades y omite 153 pequeñas. Entre sus relaciones hay 4.955 llamadas, 4.587 importaciones, 3.684 relaciones de pertenencia y 2.713 referencias. El informe declara 97 % de extracción y 3 % de inferencia; 487 aristas son inferidas. Las relaciones inferidas ayudan a navegar, pero deben comprobarse en el código.

El grafo identifica el commit `28c3ea35df5ca52d5adbb58cded8202c8a4d8ca3`; el HEAD local consultado es `3d28021`. La comparación entre ambos registra **183 archivos modificados**, con 13.654 inserciones y 896 eliminaciones. Por tanto, aunque el informe está fechado el 21 de octubre, no representa todo el código actual. Se contrastaron sus puntos principales con fuentes locales y documentación operativa.

El conector de Graphify devolvió que no existe un espacio de trabajo seleccionado, incluso al intentar listar espacios. Se utilizaron sus artefactos locales existentes. No se comprobó el estado remoto ni se ejecutaron pruebas en esta revisión documental.

## 3. Arquitectura y stack

| Capa | Implementación observada |
| --- | --- |
| Backend principal | PHP 8.2 o superior y Laravel 12 |
| Interfaz | React 18, Inertia 2, JavaScript/JSX |
| Compilación | Vite 6, Tailwind CSS 3 declarado, PostCSS |
| Rutas en frontend | Ziggy; su archivo se genera antes de desarrollo y compilación |
| Persistencia | Eloquent; entornos documentados con MySQL/MariaDB y alternativas de despliegue TiDB |
| Autorización | Spatie Laravel Permission; Sanctum también está instalado |
| Auditoría | OwenIt Laravel Auditing y modelos compartidos auditables |
| Caché y colas | Drivers configurables; soporte de base de datos y cliente Predis |
| Servicio IA | Python, FastAPI, Pydantic, Uvicorn y faster-whisper |
| Pruebas | Pest/PHPUnit, Playwright y pruebas JavaScript con Node |
| Operación | Docker/Compose, scripts PowerShell y Bash; archivos alternativos para Render |

Es un **monolito modular**, con un servicio Python auxiliar. Los módulos Laravel comparten aplicación y base de datos; no deben interpretarse como microservicios independientes. La interfaz utiliza Inertia para conectar controladores Laravel con páginas React y sus propiedades.

La división por carpetas no implica que exista Arquitectura Limpia estricta en todo el sistema: conviven servicios de dominio, políticas de flujo, controladores con lógica y modelos Eloquent. El frontend actual usa JSX; no se debe asumir TypeScript ni tipado estricto universal en PHP.

## 4. Cómo orientarse en el repositorio

```text
app/
  Http/                 Autenticación, middleware y solicitudes generales
  Jobs/                 Despliegue, purga y restauración en segundo plano
  Models/               Usuario y auditoría general
  Modules/              29 módulos funcionales y compartidos
  Providers/            Registro de eventos, permisos, límites y caché
  Support/              Sucursales, decimales, coordinación y errores
resources/js/           Arranque React, layouts, componentes y utilidades
database/               Migraciones, seeders y factories
routes/                 Rutas generales, autenticación y comandos
config/                 Configuración de aplicación e integraciones
ai_service/             Servicio Python auxiliar
tests/                  Pruebas unitarias, funcionales, frontend y E2E
docker/ y scripts/      Construcción, arranque, validación y publicación
docs/                   Diseño, operación y evidencias documentadas
graphify-out/           Grafo y mapas existentes
storage/qa/             Evidencias locales referenciadas por la documentación
```

Cada módulo suele reunir `Http/Controllers`, `Http/Requests`, `Models`, `Services`, `Resources/Pages` y `routes/web.php`, según sus necesidades. No todos tienen todas las capas.

`bootstrap/app.php` carga las rutas de los módulos y registra middleware. `resources/js/app.jsx` resuelve páginas tanto en `resources/js/Pages` como en `app/Modules/**/Resources/Pages`, con importaciones dinámicas. `app/Providers/AppServiceProvider.php` conecta eventos, autorización global, coordinación de escritores e invalidación de caché.

## 5. Inventario funcional de los 29 módulos

| Módulo | Responsabilidad principal |
| --- | --- |
| AiAssistant | Conversaciones, fuentes de conocimiento, herramientas autorizadas, canales, API y webhooks |
| Alerts | Consulta de alertas operativas |
| Audit | Consulta de trazabilidad y auditoría |
| Banks | Cuentas, movimientos bancarios y conciliación |
| Billing | Facturación SIAT, configuración por sucursal, códigos, catálogos, eventos y paquetes |
| Branches | Sucursales y sus configuraciones |
| Cash | Apertura y cierre de sesiones de caja |
| CashFlow | Reportes del flujo de efectivo |
| Customers | Clientes, tipos, interacciones y estados de cuenta |
| Dashboard | Indicadores y visión operativa |
| Expenses | Gastos y categorías, con controles de anulación |
| Exports | Datasets y exportadores PDF/XLSX propios |
| Finance | Servicio transversal de registro financiero y cálculo decimal |
| HumanResources | Trabajadores y pagos de salarios |
| Inventory | Productos, categorías, atributos, unidades, conversiones, espesores, bobinas, stock, reservas, ajustes, movimientos y transferencias |
| Payments | Cobros de ventas, pagos de compras, métodos, promesas y notas de crédito |
| Pos | Venta rápida condicionada por el perfil y las reglas comerciales |
| Printing | Plantillas, reglas, trabajos y renderizado de documentos |
| Production | Fórmulas, insumos, etapas y órdenes de producción |
| Purchases | Proveedores, órdenes, recepciones y compras |
| Reports | Reportes operativos y adaptación a perfiles |
| Reservations | Reservas empresariales y disponibilidad de recursos |
| Restaurant | Mesas, recetas, comandas y consumo de insumos |
| Sales | Cotizaciones, notas, precios, correlativos, devoluciones, recibos y despachos |
| ServiceOrders | Órdenes de servicio y reglas de su ciclo operativo |
| Settings | Configuración, preparación de instalaciones, salud, licencias, importación, respaldos y recuperación |
| Shared | Modelo auditable y componentes reutilizables de interfaz |
| SystemSuperadmin | Perfiles, capacidades, motores dinámicos, pedidos QR, gobierno de datos, purga y versionado |
| Users | Usuarios, roles y permisos |

Los pedidos QR y muchos motores configurables residen dentro de `SystemSuperadmin`; no constituyen módulos de primer nivel adicionales.

## 6. Mapa conceptual de dependencias

```text
Usuario + permisos + acceso a sucursal + perfil empresarial activo
                              |
                Controladores y políticas de flujo
                              |
     +------------------------+-------------------------+
     |                        |                         |
Ventas/POS             Compras/recepciones      Producción/servicios
     |                        |                  restaurante/reservas
     +------------ Inventario y disponibilidad ---------+
     |
Pagos y registro financiero ---- Caja / Bancos / Flujo de efectivo
     |
Documentos / Despachos / Impresión / SIAT / Reportes / Exportación

Transversal: auditoría, caché, trazas, errores y mantenimiento seguro.
IA: consulta y solicita acciones por herramientas controladas en Laravel.
```

Este esquema sintetiza responsabilidades observadas; no pretende enumerar cada llamada exacta del grafo.

Los nodos centrales del mapa incluyen `AuditableModel`, `User`, `Branch`, `AuthenticatedLayout`, `UiCatalogCache`, `SaleController`, `ExportDatasetService`, `ActiveBusinessProfile`, `GitVersioningService` y los servicios de mantenimiento. Su centralidad ayuda a estimar alcance de cambios; no demuestra por sí sola un defecto de diseño.

## 7. Flujos críticos e invariantes

### Ventas y POS

El perfil determina si existe cotización previa, qué documento se emite, cuándo se descuenta inventario, qué datos del cliente se exigen y qué medios de pago se aceptan. `SalesDocumentPolicy`, `SalesWorkflowPolicy`, `CommercialPolicy` y `PosWorkflowPolicy` concentran parte de estas decisiones.

El evento `SaleNoteIssued` se procesa mediante `ProcessSaleNoteIssued`. El listener exige una transacción activa y bloquea la venta. Al convertir una cotización consume sus reservas y la marca como convertida; descuenta inventario si corresponde al flujo y registra el pago POS cuando está presente. La emisión de la nota, las reservas y el cobro deben conservar su coherencia transaccional.

### Compras y recepciones

Una orden puede tener recepciones independientes y compras asociadas. Las compras admiten stock general y bobinas/lotes. La anulación integral puede afectar ítems, recepciones, pagos, inventario y banco.

La documentación actual describe relectura y bloqueo de recursos, orden estable de bloqueos y reintentos ante interbloqueos. Se rechaza la anulación si existen reservas, consumo incompatible o stock libre insuficiente. Las recepciones conservan su historial con `voided_at`; se revierten las cantidades de la compra afectada.

`PurchaseVoidAuthorization` exige acceso a sucursal y `purchases.manage`; si la compra tiene pagos, también `payments.manage`. La interfaz recibe permisos calculados por el servidor y el servicio vuelve a verificarlos al ejecutar.

### Dinero, caja y precisión

`FinancialLedgerService`, `SalePaymentService`, `CashSessionGuard` y los módulos de pagos y bancos forman un área de cambios con impacto transversal. Los pagos de venta incorporan contexto de sucursal y caja; no debe inferirse que un cobro es ajeno al cierre de caja.

Existe un auxiliar `Finance/Support/Decimal.php` con operaciones BCMath y redondeo explícito. Los cambios monetarios deben respetar la precisión configurada y los tipos persistidos; usar coma flotante indiscriminadamente puede introducir diferencias de saldo.

### Inventario

Se distinguen existencias por sucursal, bobinas, movimientos y reservas. Una reserva de stock no equivale a una reserva de agenda o recurso. Producción, restaurante y documentos comerciales pueden consumir inventario según sus políticas. Los reversos deben comprobar disponibilidad, dependencias e historial, no limitarse a incrementar una cantidad.

### Caché

`UiCatalogCache`, `AuthSessionCache` y `SystemCacheInvalidator` participan en catálogos, autorización y resultados operativos. `OperationalCacheOutbox` conserva invalidaciones pendientes junto con el cambio de negocio y permite reintentarlas. Un fallo posterior al commit no debe comunicarse como si la transacción comercial se hubiera revertido.

## 8. Perfiles empresariales y motores dinámicos

`ActiveBusinessProfile` obtiene el perfil activo más reciente, normaliza su configuración y permite una configuración de vista previa. Su comportamiento de respaldo es el perfil de ferretería. El esquema expuesto incluye módulos, submódulos, capacidades, indicadores de funcionalidad, entidades, campos, relaciones, formularios, estados, permisos por campo, gobierno, adjuntos, automatizaciones, aprobaciones e integraciones.

También incorpora reglas específicas para ventas, compras, inventario, POS, documentos, impresión, reservas, restaurante, servicios, producción, sucursales y experiencia de usuario. Hay servicios para borradores, diferencias, migración de configuración, compatibilidad, preparación, activación y sandbox.

`BusinessProfilePresetFactory` contiene configuraciones para ferretería formal y POS, restaurantes, cafetería, servicios profesionales, talleres, supermercado, librería, ropa, fábrica, alquileres, farmacia, salud, estética, gimnasio, veterinaria, mecánica, reparación electrónica, lavadero, mudanzas, construcción, préstamos/empeño, educación, eventos, transporte y agropecuaria, entre otras variantes.

**Un preset expresa configuración y alcance previsto; no acredita por sí solo que cada especialidad tenga la profundidad de un producto vertical completo ni una habilitación regulatoria.** Antes de prometer una función de un rubro, comprobar su implementación, capacidades y pruebas. Tampoco debe asumirse aislamiento multiempresa tipo SaaS: el código revisado muestra un perfil activo compartido y acceso por sucursal, no una garantía general de aislamiento por tenant.

Los middleware `business_feature` y `business_capability` bloquean funcionalidades desde backend. Ocultar un menú no sustituye estos controles.

## 9. Seguridad, auditoría y manejo de errores

El acceso combina autenticación, permisos, rol administrativo, sucursal y capacidades del perfil. `BranchAccess`, `SystemRoles`, los Form Requests y las políticas operativas son referencias centrales. El proveedor registra un bypass de autorización para el superadministrador; ese rol exige especial cuidado al evaluar permisos.

Hay middleware para cambio obligatorio de contraseña, cabeceras de seguridad, proxy/HTTPS, sandbox, vista previa y coordinación de escrituras. Existen límites de solicitudes para pedidos QR y endpoints del asistente.

Los errores se centralizan mediante `SystemErrorResponder` y `SystemErrorDetails`, con componentes React como `SystemErrorBoundary` y `SystemErrorFeedback`. Al cambiar respuestas HTTP, mantener coherencia entre JSON, navegación Inertia y errores de interfaz.

No trasladar contraseñas iniciales del README ni secretos de `.env` al contexto de otros agentes, entregas o documentación pública. Este resumen no los incluye.

## 10. Facturación SIAT

El módulo contiene CUIS/CUFD, generación de CUF, catálogos, homologación de productos, XML, validación XSD, comunicación SOAP, compresión, hash, QR, eventos significativos y paquetes.

`SiatCertificationReadinessService` verifica token delegado, modalidad, código de sistema, NIT, actividad, producto SIN, CUIS/CUFD vigentes, catálogos y XSD. El checklist inspeccionado apunta a Computarizada en Línea y señala requisitos adicionales para Electrónica.

La existencia de código, pruebas y modos simulados **no acredita certificación ni emisión fiscal real aprobada**. Para conocer el estado de una instalación hay que revisar su configuración y evidencias correspondientes. Este documento describe código, no ofrece una validación normativa.

## 11. Asistente de IA

Laravel conserva conversaciones, mensajes, fuentes, canales, clientes API, registros y ejecuciones de herramientas. FastAPI sirve como motor auxiliar para recuperación de conocimiento, chat y transcripción.

El modo inicial documentado es `cpu_light`, con índice RAG persistente JSONL y proveedor `rag_local`. Opcionalmente conecta un servidor compatible con API de chat, como Ollama, vLLM o LM Studio. La transcripción utiliza faster-whisper y contempla una respuesta de respaldo explícita si no está disponible.

La comunicación interna admite token y firma HMAC SHA-256 sobre marca temporal y cuerpo cuando se configura el secreto. La documentación asigna a Laravel la autorización fuerte por usuario/sucursal, auditoría y ejecución de herramientas; FastAPI no debe ejecutar SQL libre ni modificar directamente la base del ERP.

`AiAssistantToolRegistry` filtra herramientas según permisos: ventas, ganancias, stock, productos, clientes, caja, bancos, pedidos y exportaciones. Las entradas de creación de pedidos/cotizaciones que llegan al caso de respaldo del registro devuelven `requires_confirmation`; no se deben describir como acciones universales y autónomas solo porque figuren en el catálogo.

La presencia del módulo no prueba que el servicio Python, el modelo o los canales externos estén activos en el servidor.

## 12. Mantenimiento, purga y recuperación

Es una de las áreas más delicadas y más modificadas desde el grafo:

1. La purga exige vista previa cifrada vinculada al usuario y alcance, con vigencia y nueva comprobación de huellas y dependencias.
2. `PurgeScopePlanner` y `PurgeNumberingPolicy` evalúan tablas, relaciones, ciclos y correlativos. Hay tablas protegidas y restricciones para reiniciar identificadores.
3. `OperationalWriteGate` coordina escritores HTTP, trabajos y comandos conocidos; las operaciones exclusivas requieren su propia barrera.
4. Los respaldos nuevos incorporan SHA-256 y manifiesto por tabla; la recuperación se ensaya en una base temporal antes de sustituir datos.
5. `PurgeDatabaseJob`, `RestoreMaintenanceBackupJob` y `DeployRepositoryJob` permiten trabajos largos en una cola dedicada.
6. `RecoveryJournal` conserva el progreso fuera de las tablas que se restauran, en almacenamiento privado persistente.

La restauración original conserva o reconstruye las tablas efímeras sin resucitar sesiones o trabajos antiguos; verifica datos persistentes e integridad y cancela operaciones administrativas recuperadas que no deben reejecutarse. Debe completar la invalidación de caché antes de declarar éxito.

La restauración SQL ejecuta DDL y no es atómica. Volver a una imagen anterior tampoco revierte migraciones. Los respaldos históricos sin manifiesto y las exportaciones JSON no equivalen al flujo de restauración SQL automática verificada.

Referencias obligatorias para intervenir: `docs/mantenimiento-seguro.md` y `docs/publicacion-y-recuperacion.md`.

## 13. Despliegue y estado documentado

El despliegue Docker utiliza una misma imagen para aplicación, cola general, programador y trabajador de mantenimiento. La base y `storage` persisten en volúmenes. Los scripts `Deploy-Compose.sh` y `Deploy-Compose.ps1` realizan preparación y actualización desde el anfitrión.

El flujo de actualización exige respaldo y ensayo de recuperación, migraciones, compilación/cachés, comprobación de salud, revisión publicada y reinicio de procesos. La entrega de fuentes utiliza archivo con manifiesto y SHA-256, extraído en una carpeta nueva por revisión. Las credenciales quedan fuera de fuentes e imagen.

La documentación distingue dos modos: Docker sin Git operativo dentro del contenedor, y repositorio escribible con versionado controlado. `GitVersioningService` y `RepositoryDeploymentService` contemplan bloqueo, validación de destino y recuperación de código mediante bundle, parches y archivos nuevos.

Según `docs/entrega-preproduccion.md` y `docs/validacion-por-fases.md`:

- La entrega aceptada cubrió Windows local y preproducción Ubuntu; producción real Windows/Docker quedó fuera de ese alcance.
- Preproducción se identifica como proyecto Compose `calmina-qa`, con HTTP en loopback del servidor y acceso mediante túnel.
- El 22 de octubre se documentó una actualización exitosa, con cuatro procesos sobre la misma imagen y respuestas 200 de `/health` y `/login`.
- También se documentó la copia de la base local a preproducción: 157 tablas y 663 registros, con comprobación de integridad; el estado posterior registrado contenía cuatro sucursales, tres perfiles y seis usuarios.

Estas son evidencias históricas documentadas; no se volvió a consultar el servidor en esta tarea. Que el entorno utilice `APP_ENV=production` no cambia su condición de preproducción acordada.

## 14. Pruebas y nivel de evidencia

La aceptación del 20 de octubre registra **496 pruebas aprobadas y 3.481 aserciones por motor**, tanto en MariaDB local aislada como en MySQL 8 de Ubuntu Docker; incluye ensayos de purga, recuperación y operación HTTP. Son dos ejecuciones de la misma matriz y no deben sumarse como pruebas únicas. Hay validación adicional de recuperación con caché y cola activas.

Esto no implica que el HEAD del 22 de octubre ni los cambios posteriores se hayan validado de nuevo con la suite completa. Los blueprints describen diseños y planes; las evidencias fechadas y el código son necesarios para determinar qué quedó implementado y probado.

`phpunit.xml` fija MySQL en loopback, puerto 3307 y base `fabrica_calmina_aroma_testing`. `tests/bootstrap.php` y `Tests/TestCase` verifican el aislamiento antes de ejecutar operaciones destructivas de pruebas. No ejecutar la suite contra la base operativa ni quitar sus guardas para hacerla pasar.

Comandos de referencia, después de preparar el entorno aislado:

```text
composer install --no-interaction --prefer-dist
php vendor/bin/pest --compact
npm ci
npm run build
npm run test:e2e
```

Playwright necesita URL y credenciales QA de su entorno. `production:staging-gate --strict` es parte del cierre técnico descrito en `docs/staging_multinegocio_2.md`; no sustituye las pruebas ni QA manual. Las operaciones monetarias actuales también requieren la extensión BCMath usada por el código.

Durante esta revisión apareció un archivo local sin seguimiento: `tests/Feature/Settings/PersistentBackupConnectionTest.php`. Se conserva intacto; su presencia no acredita ejecución o aprobación. El resto del resumen no depende de que esa prueba esté integrada.

## 15. Guía práctica para Aragon

- Empezar por el módulo afectado, sus rutas, Form Requests, servicios, políticas y pruebas. Utilizar el grafo como mapa y confirmar las relaciones en las fuentes actuales.
- Para cambios transversales, revisar `ActiveBusinessProfile`, acceso a sucursal, permisos, caché y auditoría, además del controlador visible.
- Para ventas, pagos, compras o inventario, identificar la transacción completa, los bloqueos y los efectos reversibles antes de cambiar una pantalla o endpoint.
- Mantener compatibilidad con el flujo de ferretería y comprobar el comportamiento con capacidades activadas y desactivadas. El bloqueo debe existir por URL, además del menú.
- No introducir otro servicio o framework sin una necesidad concreta. Seguir Laravel modular y React/Inertia existentes, con SOLID, DRY, KISS y YAGNI aplicados de forma proporcional.
- Usar español en respuestas y comentarios nuevos. Preferir tipado explícito, validación backend y componentes accesibles, coherentes con las convenciones actuales.
- Respetar los cambios locales existentes y conservar los archivos de bloqueo al instalar dependencias. No confundir actualización de código con recuperación de base de datos.
- Distinguir siempre entre función configurada, código implementado, prueba automatizada aprobada y validación real del entorno.

## 16. Lectura recomendada

| Necesidad | Fuentes dentro del repositorio |
| --- | --- |
| Navegación inicial | `docs/arbol-nodos.md`, `graphify-out/GRAPH_REPORT.md`, `graphify-out/graph.html` |
| Arranque y límites de aplicación | `bootstrap/app.php`, `app/Providers/AppServiceProvider.php`, `resources/js/app.jsx` |
| Configuración multinegocio | `app/Modules/SystemSuperadmin/Services/ActiveBusinessProfile.php`, `BusinessProfileConfiguration.php`, `BusinessProfilePresetFactory.php` |
| Venta e inventario | `app/Modules/Sales/Listeners/ProcessSaleNoteIssued.php`, servicios y pruebas de Sales/Inventory/Payments |
| Compra y anulación | `app/Modules/Purchases/Services/PurchaseService.php`, `PurchaseVoidAuthorization.php`, pruebas de Purchases |
| Mantenimiento seguro | `docs/mantenimiento-seguro.md`, `app/Support/OperationalWriteGate.php`, servicios de Settings y SystemSuperadmin |
| Publicación y recuperación | `docs/publicacion-y-recuperacion.md`, scripts `Deploy-Compose`, configuración Docker |
| Estado aceptado | `docs/validacion-por-fases.md`, `docs/entrega-preproduccion.md`, evidencias referenciadas en `storage/qa` |
| SIAT | `app/Modules/Billing/Services/SiatCertificationReadinessService.php` y pruebas de Billing |
| IA | `ai_service/README.md`, `docs/ai_assistant_produccion.md`, servicios de AiAssistant |

## Contexto breve reutilizable

> Trabajas sobre Fabrica_Calmina_Aroma, un ERP/POS monolítico modular con Laravel 12/PHP 8.2+, React 18, Inertia 2 y MySQL/MariaDB. Su núcleo es calaminas/ferretería y se extiende mediante perfiles empresariales configurables. Existen 29 módulos que comparten base y servicios transversales. Las áreas críticas son ventas-cotizaciones-reservas-stock, compras-recepciones-pagos, caja/bancos y mantenimiento con purga, respaldo y restauración. Revisa permisos, sucursal, perfil activo, transacciones, bloqueos, auditoría e invalidación de caché en cada cambio. La IA usa FastAPI auxiliar y herramientas autorizadas en Laravel; SIAT implementado no equivale a certificación real. El grafo local es anterior al HEAD y debe contrastarse con las fuentes. Hay aceptación histórica de 496 pruebas/3.481 aserciones por motor y preproducción Ubuntu documentada, pero no una validación nueva del código actual ni de producción Windows. Conserva cambios locales, usa bases aisladas para pruebas y responde y comenta en español. Consulta docs/contexto-proyecto-para-aragon.md para el detalle.
