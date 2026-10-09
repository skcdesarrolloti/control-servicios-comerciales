# Control de Servicios Comerciales

Panel PHP para consultar y gestionar tickets por `estado_comercial`, administrar la visibilidad y las acciones por cargo, y operar el calendario del equipo comercial.

## Alcance

- Tickets abiertos, postergados y cerrados.
- Las 23 categorías comerciales definidas en `CommercialStatusCatalog`.
- Búsqueda por ticket, asunto, solicitante, responsable e inmueble.
- Cambio de estado y reasignación con registro en el historial del ticket.
- Análisis con asistente MiniMax desde el popup de cada tarea, usando tarea, inmueble, historial, respuestas, seguimientos y notas. Los análisis se guardan de forma compacta, con máximo 3 por tarea y máximo 1 nuevo por día.
- Permisos de vistas y acciones por cargo, persistidos en `wp_jet_cct_confi_sistema` bajo `control_servicios_comerciales_config`.
- Guía de estados comerciales.
- Calendario limitado por defecto a:
  - Cargo 9: Consultor de Arriendo.
  - Cargo 10: Consultor de Venta.
  - Cargo 17: Proveedor de Servicios de Consultoría Comercial Inmobiliaria.
- Login compartido con las demás aplicaciones que usan `wp_jet_cct_funcionarios` y la sesión `scm_sess`.
- Autologin opcional mediante enlace HMAC con expiración corta.

## Requisitos

- PHP 8.2 o superior.
- Extensiones `pdo_mysql`, `json`, `fileinfo` y `mbstring`.
- MySQL/MariaDB con acceso a las tablas JetEngine existentes.
- Composer para instalar dependencias de desarrollo.

## Instalación

1. Instala las dependencias:

   ```bash
   composer install --no-dev --optimize-autoloader
   ```

2. Copia `.env.example` como `.env` y completa todos los valores requeridos.

3. Configura el document root del sitio en `public/`.

4. Da permisos de escritura al proceso PHP únicamente sobre `storage/data`, `storage/logs` y `storage/uploads`.

## Estilos locales del login y dashboard

Ambas pantallas cargan `public/assets/css/tailwind.css` desde el mismo servidor, sin depender de `cdn.tailwindcss.com`. El CSS compilado se incluye en el repositorio y debe subirse junto con los archivos PHP; el servidor de producción no necesita Node.js.

Si se agregan o cambian clases de Tailwind en PHP o JavaScript, regenera el archivo antes de desplegar:

```bash
npm ci
npm run build:css
```

La configuración compartida está en `tailwind.config.cjs` y la entrada en `resources/css/tailwind.css`. Se incluyen las clases de las vistas PHP y de los elementos creados por JavaScript. Usa nombres de clase completos en las condiciones; evita construirlos concatenando fragmentos. La URL del CSS usa su fecha de modificación para renovar la caché al actualizarlo.

Las fuentes de Google, Font Awesome y SweetAlert siguen usando sus proveedores externos; este cambio elimina la dependencia externa de Tailwind.

## Notificaciones comerciales

La pestaña **Notificaciones** reutiliza la búsqueda, filtros por contrato y preferencias del módulo inmobiliario. Incluye propietarios activos, propietarios no activos, arrendatarios activos, arrendatarios no activos, copropiedades y Club PPH. Activos significa que tienen al menos un contrato Entregado; no activos significa que tienen un contrato Recibido y ningún contrato Entregado. Esta clasificación se valida al buscar, contar, seleccionar todos y enviar. No incluye proveedores ni selección desde archivos SIMI.

En **Configurar permisos** se puede habilitar la vista **Notificaciones comerciales** y la acción **Enviar notificaciones comerciales** por cargo. Los cargos con acceso total ven todos los contactos y envíos. Los demás solo ven contactos creados por su `id_empleado`, contactos relacionados por `id_propietario`, `id_arrendatario` o `id_copropiedad` con inmuebles/contratos asignados al funcionario y miembros de Club PPH con su `id_empleado`. La misma restricción se aplica en el servidor al buscar, seleccionar todos y enviar. Su cola solo muestra sus propios envíos.

Los mensajes van a `skc_notification_queue` con `project_code=control-servicios-comerciales` y `source_module=commercial_notifications`. Los procesa el worker global existente; no hay envíos directos ni un cron nuevo. Se respetan bloqueos por canal y preferencias de notificaciones; WhatsApp también respeta `permite_marketing_whatsapp`. El identificador del envío y un bloqueo de MySQL/MariaDB impiden duplicados ante reintentos.

La cola muestra **Creada por** (nombre, cargo al crear e `id_empleado`) y **Ver mensaje**, que consulta el texto/HTML realmente guardado, asunto, plantilla, adjunto disponible, fechas de creación/envío y último error. El correo se muestra en un iframe sin permisos de scripts. La autoría identifica al funcionario que solicitó el envío; el transporte lo ejecuta el worker. Se registra también el ID interno de usuario para nuevos mensajes. Los históricos conservan la autoría que ya tenían en `meta_json`; cuando falta se muestran como **Sin autor registrado**.

**Eliminar notificaciones comerciales (solo administradores)** figura en Configurar permisos y está ligado a **Acceso Total Administrativo**. No se puede delegar individualmente y se valida también en la API. Eliminar retira el mensaje de la cola mediante metadatos de auditoría: conserva contenido, autor, intentos y clave de deduplicación, y registra quién eliminó y cuándo. Un pendiente pasa a cancelado con una actualización atómica compatible con el claim del worker; uno en procesamiento no se permite eliminar. Un enviado conserva su estado y no se retira del destinatario. No se requieren nuevas tablas ni migraciones.

**Informe por funcionario** muestra una sola fila por `id_empleado`, con columnas de cantidades de WhatsApp, Correo y SMS, total, estados y eliminados. Conserva una sola fila aunque cambien el nombre o cargo históricos del funcionario y muestra su identidad registrada más recientemente. Permite filtrar por ID y fechas de creación (días completos de Colombia). Incluye los eliminados en el total; la columna Eliminados se solapa con los estados. Cada mensaje por canal cuenta una vez. Los administradores consultan todos los autores del módulo y los demás solo sus registros, incluso al manipular filtros. **Descargar CSV** exporta el mismo resultado agrupado con protección ante fórmulas de hoja de cálculo.

**Editar datos** aparece junto a cada destinatario para los cargos con **Editar datos de actores y registros relacionados**. Este permiso está habilitado por defecto para administradores; se puede delegar desde Configurar permisos y conserva el alcance del destinatario. Solo admite documento/NIT, nombre, correo, celular e indicativo. Para personas jurídicas se edita la razón social y NIT cuando existen, conservando los datos del representante. Copropiedades usa `copropiedad`, `nit` y `contacto`; Club PPH usa `telefono` como celular.

**País / indicativo** muestra el nombre del país y su código (por ejemplo, Colombia (+57)) desde el catálogo `jet_cct_paises`. Los países que comparten indicativo se muestran juntos, porque el actor guarda únicamente el código telefónico. Abrir el selector conserva el valor existente; los códigos sin país registrado siguen disponibles. Si falta el catálogo, se ofrece Colombia (+57). Tanto los datos actuales como la comparación muestran el país cuando se conoce. Los registros relacionados se organizan en acordeones por categoría marcada, cerrados inicialmente, con cantidades y selección individual. La comparación usa los mismos acordeones; el registro principal permanece visible.

Al abrir el editor se muestran los inmuebles, contratos de mandato, arrendamientos y cierres vinculados al actor, con ID, código o contrato, estado, dirección y datos actuales cuando están disponibles. Cada grupo indica cuántos registros contiene y permite marcarlos en conjunto o individualmente. **Ver cómo quedarán los datos** abre la comparación incluso sin editar: muestra todos los registros seleccionados con valores actuales y finales, resalta los campos que cambiarán e identifica los registros sin cambios. **Guardar cambios** permanece visible en el pie del editor; se habilita después de revisar y marcar la confirmación, siempre que existan cambios seleccionados. Cambiar la selección obliga a confirmar nuevamente. Solo se escriben y auditan registros con cambios. Revisa todos los estados, incluidos cerrados e históricos. Solo actualiza columnas existentes y relacionadas por IDs del actor; no modifica IDs, propietarios de registros, preferencias de mensajería, información financiera ni PDFs emitidos. En mandatos respeta los seis bloques de titulares y las empresas jurídicas; los mandatos antiguos de un solo titular se resuelven por su ID principal. Si los IDs coinciden ambiguamente con otro actor, muestra la advertencia y bloquea la propagación para evitar modificar datos ajenos; sigue disponible la edición del registro principal. El JavaScript de notificaciones usa una URL versionada por el contenido del archivo para invalidar la caché al desplegar cambios.

La revisión guarda un token de un solo uso en la sesión, vinculado al usuario y válido por 15 minutos. La confirmación usa esa revisión del servidor, valida nuevamente permisos y alcance, bloquea las filas y compara sus versiones completas antes de escribir. Ante cambios concurrentes o errores revierte todas las escrituras. Las tablas deben usar InnoDB. La primera confirmación crea automáticamente `wp_scm_commercial_actor_changes` (respetando el prefijo de la instalación), donde registra funcionario, fecha, actor y valores anteriores/finales de todos los registros elegidos. La cuenta de base de datos necesita permiso de creación para esa tabla de auditoría.

Los botones superiores de WhatsApp, Correo, SMS y Todos los canales abren el editor en un popup para la selección masiva. Cada contacto tiene los mismos cuatro botones para un envío individual, sin alterar la selección masiva. La vista previa cambia por canal: el correo usa `EmailTemplate::render`, incluido el banner configurado, igual que el HTML encolado. WhatsApp incluye saludo y firma automática (nombre, cargo y celular reales del funcionario).

La confirmación de envío usa un popup con destinatarios y canales, permite volver al mensaje y muestra el progreso del encolado. Al terminar, otro popup informa mensajes encolados, sin datos válidos, omitidos por preferencias y con error, y permite abrir la cola. Los errores conservan el editor; encolado indica pendiente de envío, no entrega confirmada.

Las URLs directas de secciones sin permiso muestran una página HTTP 403 con el mensaje «No tienes permiso para entrar a esta página» y el botón «Ir al inicio». La navegación interna muestra la misma vista al recibir una denegación del servidor. El bloqueo ocurre antes de cargar los datos de la sección. El botón vuelve a la entrada del dashboard; si Inicio está deshabilitado para ese cargo, la entrada abre su primera sección permitida.

Las cuatro plantillas son `scm_marketing_generica_texto_v1`, `scm_marketing_generica_imagen_v1`, `scm_marketing_generica_documento_v1` y `scm_marketing_generica_video_v1`. Se requiere registrarlas y obtener aprobación en Meta antes del primer envío. El cuerpo conserva los parámetros de nombre, mensaje y firma, con «Atentamente,» antes de `{{3}}` y sin la frase de consultas.

Los botones individuales y las casillas del popup se deshabilitan si falta un destino válido: Email requiere correo y WhatsApp/SMS requieren celular. Todos los canales activa únicamente los disponibles. Para una selección manual, los botones masivos se habilitan si algún contacto dispone del canal. La selección de todos los resultados puede abarcar otras páginas; el servidor vuelve a comprobar cada destino y omite los inválidos por canal sin interrumpir los demás, informando la cantidad omitida.

SMS tiene un límite de aplicación de 160 caracteres, incluido el prefijo `SKC SuCasa Inmobiliaria `. Se verifica antes de enviar en navegador y servidor. El contador muestra la codificación GSM-7/Unicode y una estimación de segmentos, contando dos unidades para caracteres GSM extendidos y pares sustitutos Unicode. La estimación no sustituye la facturación del proveedor. SMS utiliza el prefijo y el mensaje escrito; el saludo y firma extensos corresponden a WhatsApp y Email.

Los contactos se consultan al abrir cada categoría, con paginación de 20. No se recalculan estadísticas de las seis categorías en cada búsqueda ni se consultan contratos por cada fila. El navegador guarda las consultas por categoría/filtros/página durante 60 segundos; Actualizar renueva los datos. Las búsquedas obsoletas se cancelan y el servidor siempre vuelve a validar destinatarios y preferencias al enviar.

Los encabezados aceptan JPG/PNG, PDF y MP4. Los límites son 5 MB para imágenes, 100 MB para documentos y 16 MB para videos, sujetos al límite `UPLOAD_MAX_BYTES` de la app y a `upload_max_filesize`/`post_max_size` de PHP. Los archivos se guardan en `storage/uploads` y se sirven mediante `public/file.php` con firma HMAC, sin acceso por nombre solamente. El correo incluye un enlace firmado al archivo. El servidor debe poder servir esos enlaces por HTTPS al worker y a Meta.

Verificación aislada, sin contactos ni envíos reales:

```bash
php tools/check-commercial-notifications.php
```

Para revisar la interfaz con datos ficticios, genera `output/notifications-preview.html` con `php tools/preview-commercial-notifications.php` y sirve el repositorio localmente en `127.0.0.1:8769`. La prueba opcional `tools/check-commercial-notifications-ui.cjs` usa Playwright/Edge y comprueba vista previa, encabezados, selección, cola, API y diseño móvil. Su API de pruebas funciona solo en el servidor CLI local con una base SQLite en memoria.

Para revisar historial, informe y permisos de eliminación usa `php tools/preview-commercial-notifications.php --audit` y `php tools/preview-commercial-notifications.php --audit --admin`. Generan vistas independientes de usuario y administrador. `tools/check-commercial-notifications-audit-ui.cjs` verifica estas pantallas y las nuevas rutas de API. Los fixtures se reconstruyen por petición; la persistencia de eliminación se comprueba en `CommercialNotificationsAuditTest`.

La misma vista de administrador incluye datos ficticios de los cuatro tipos de actor y sus relaciones. `tools/check-commercial-actor-editor-ui.cjs` verifica edición, comparación por titular y sin editar, selección individual, confirmación explícita, guardado, permisos/CSRF y móvil. Agrega `--shell` a la generación de la vista y al verificador para probar también con el dashboard completo, sus scripts y la URL versionada del JavaScript. `CommercialActorEditorTest` prueba persistencia, exclusiones, comparación sin cambios, auditoría, personas jurídicas, alcance, tokens, referencias ambiguas y rollback completo con SQLite en memoria. La API local conserva los tokens en una sesión de pruebas; reconstruye los datos por petición y nunca edita actores reales.

## Autologin firmado

El autologin está desactivado por defecto. Para habilitarlo configura:

```dotenv
AUTO_LOGIN_ENABLED=true
AUTO_LOGIN_SECRET=un-secreto-aleatorio-de-al-menos-32-caracteres
AUTO_LOGIN_TTL=300
```

El enlace debe incluir `auto_user`, `auto_expires` y `auto_signature`. La firma es:

```text
hex(HMAC-SHA256("usuario|expira", AUTO_LOGIN_SECRET))
```

`auto_expires` es una marca Unix futura que no puede exceder `AUTO_LOGIN_TTL`. El funcionario también debe estar activo en `wp_jet_cct_funcionarios`.

## Asistente MiniMax

Para habilitar el botón **Analizar con asistente** en el popup de tareas comerciales, configura:

```dotenv
MINIMAX_API_KEY=tu-api-key
MINIMAX_BASE_URL=https://api.minimax.io/v1
MINIMAX_MODEL=MiniMax-M3
MINIMAX_TIMEOUT=45
```

La API key se usa únicamente desde PHP; nunca se expone al navegador.

Los análisis quedan almacenados en `wp_scm_commercial_task_analyses` (respetando el prefijo configurado en `DB_PREFIX`). La tabla se crea automáticamente cuando se usa el asistente por primera vez.

## Seguridad

- Las credenciales y secretos solo viven en `.env`, que está ignorado por Git.
- Las operaciones mutables requieren sesión, CSRF y permiso de acción por cargo.
- Los cambios de estado y responsable registran una entrada en `wp_jet_cct_historial_del_ticket`.
- La configuración comercial usa una fila separada de la aplicación inmobiliaria.
- `AUTH_ALLOW_LEGACY_PASSWORDS=true` debe usarse solo mientras existan contraseñas heredadas sin hash.

## Verificación

```bash
php -l public/index.php
php -l public/api.php
php -l src/Commercial/CommercialTaskAssistant.php
node --check public/assets/js/commercial-dashboard.js
```

Para verificar todos los archivos PHP:

```powershell
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
```

## Actividades comerciales: Precaptación

El menú **Actividades comerciales → Precaptación** integra el panel suministrado en
`precaptacion/precaptaciones/precaptaciones.php`. Su adaptación está en
`src/Precaptacion/LegacyPanel.php`; usa la sesión, base de datos, permisos y cola de
notificaciones de este proyecto y no requiere cargar WordPress.

En **Configuración de Accesos**, habilitar la vista **Precaptación** y las acciones
necesarias: registrar, editar resultados, crear tickets y crear barrios/inmobiliarias.
Los cargos administrativos pueden ver el control completo; los demás solo ven y
modifican sus propias precaptaciones, identificadas por `id_empleado`.

El formulario conserva los campos de `anadir-precaptacion.json`, incluida la evidencia
fotográfica y los campos condicionales de Club PPH y competencia. **Añadir barrio** y
**Añadir inmobiliaria** crean y seleccionan el registro sin abandonar el formulario.
La comparación ignora mayúsculas, tildes y espacios repetidos; para barrios también
compara país y ciudad. Los registros existentes se reutilizan. La creación de catálogos
usa bloqueo por tabla en MySQL para coordinar solicitudes concurrentes de este módulo.

Se reutilizan las tablas CCT existentes de precaptaciones, barrios, inmobiliarias,
funcionarios, países y Club PPH, los glosarios JetEngine y las tablas de tickets e
historial del panel original. Los campos obligatorios de precaptaciones se comprueban
antes de insertar. Los correos se encolan en `shared-notifications`; no se envían desde
la petición HTTP.

Pruebas con SQLite aislado:

```powershell
php .vendor-test-junction/bin/phpunit --filter 'PrecaptacionTest|CommercialAccessPolicyTest'
php -S 127.0.0.1:8770 -t .
# En otra terminal, con Playwright disponible:
node tools/check-precaptacion-ui.cjs RUTA_A_NODE_MODULES
```

La prueba de navegador comprueba registro con fotografías, catálogos, campos
condicionales, permisos, edición y creación de tickets con reintentos. La base de datos
y los archivos de esa prueba se guardan en el directorio temporal, separados del entorno real.
