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

## Añadir aviso

**Actividades comerciales → Añadir aviso** permite elegir primero **Aviso nuevo** o **Retoque de aviso**. La vista `anadir_aviso` y la acción `registrar_aviso` se administran desde **Configurar permisos**; los cargos con una configuración explícita deben tener habilitados ambos permisos. Los administradores conservan acceso total.

El formulario consulta la tarea por su número interno (`_ID`), valida que esté asignada al funcionario y comprueba su relación con el inmueble. En aviso nuevo, el barrio se elige del catálogo y determina la ruta. En retoque, barrio, ruta, tipo y categoría se obtienen del inmueble. La fecha, el funcionario y la sucursal proceden del servidor. La evidencia requiere entre una y tres fotografías de hasta 10 MB, respetando también `UPLOAD_MAX_BYTES` y los límites de PHP (`upload_max_filesize`, `post_max_size`, `max_file_uploads`). Para admitir tres archivos de 10 MB, configura al menos `upload_max_filesize=10M`, `post_max_size=32M` y `max_file_uploads=3`.

Las opciones de tipo de inmueble (`783`), categoría (`784`), promocionado por (`161`) y motivo (`940`) se consultan en `wp_jet_post_types`, filtrando `status='glossary'`. En esta instalación, `meta_fields` contiene las opciones `value`/`label` serializadas en PHP. Se deserializan sin permitir clases y se conserva la diferencia entre el valor guardado y la etiqueta mostrada. La misma fuente se usa para mostrar y validar los campos al guardar.

La ubicación GPS es obligatoria en ambos tipos. Se conserva la estructura del plugin `ubicacion-inmuebles`: historial en `wp_skc_ubicacion_inmuebles_mapa` y enlace en `wp_jet_cct_inmuebles.ubicacion_google_maps`. Se guardan los registros en `wp_jet_cct_retoques`, metadatos WordPress, historial del inmueble y respuesta del ticket. El retoque también actualiza el ticket a **En proceso / Retocando**. Cuando sí se pegó aviso, genera el reporte correspondiente con la regla original: para el empleado `2038`, `valor_aviso`; para los demás, `(valor_transporte / 2) / factor_prestacional`. Estos valores se consultan en `wp_jet_cct_confi_sistema`, nunca en el navegador. Un aviso nuevo conserva el comportamiento original, sin generar ese reporte de pago.

Los correos al funcionario y a administración se guardan en la cola compartida existente, con `project_code=control-servicios-comerciales`, `source_module=commercial_signs` y una clave única por aviso/destinatario. `SIGN_NOTIFICATION_EMAILS` permite ajustar los destinatarios administrativos. No se ejecutan envíos directos. El registro, la ubicación, los historiales y la cola se confirman en una transacción; si falla algún paso, se revierten. Las tablas implicadas deben usar InnoDB. No se invocan los antiguos hooks desde este panel para evitar duplicar sus efectos.

Si no se pegó aviso por **Arrendado**, **Vendido** o **No quiere promocion**, se conserva la despublicación y su historial en la base de datos actual: estado del inmueble en el CCT, metadatos WordPress y registro de despublicación. El panel externo anterior se retiró; este flujo no usa otra conexión de base de datos ni requiere configuración adicional de portales.

La tabla auxiliar `wp_scm_commercial_sign_requests` se crea al primer registro (requiere permiso `CREATE` en ese momento) y guarda la solicitud única para evitar duplicados. Los reintentos del mismo envío reutilizan su aviso, fotografías, contador y correos. Las instalaciones que ya tengan esta tabla siguen funcionando sin migraciones: las columnas antiguas de sincronización quedan sin uso.

La verificación aislada del módulo usa `phpunit --filter CommercialSignServiceTest`. La prueba de navegador usa la base SQLite de `tests/Fixtures/commercial-signs.php`, archivos de prueba en `output/` y la cola local; no escribe registros de producción ni envía correos. Inicia `php -S 127.0.0.1:8771 -t .` y ejecuta `node tools/check-commercial-signs.cjs` con Playwright disponible. `SCM_PLAYWRIGHT_MODULE` y `SCM_BROWSER_CHANNEL` permiten seleccionar el módulo y el navegador instalados.

## Registro desde una tarea

En el detalle de cada tarea, **Gestión Operativa** incluye **Aviso nuevo** e **Ingresar inmueble**. Ambas acciones abren el formulario dentro del mismo modal y permiten volver a la tarea. El aviso toma el número interno de la tarea y el código del inmueble, consulta sus datos y conserva el GPS y la evidencia obligatorios. La acción de aviso requiere la vista **Añadir aviso**, **Registrar avisos nuevos y retoques** y acceso al detalle de la tarea.

**Ingresar inmueble** requiere **Ver detalle de la tarea** y la nueva acción **Ingresar inmuebles desde una tarea** en **Configurar permisos**. Los cargos con permisos explícitos deben habilitar esa acción. Se aplica la misma restricción de tareas asignadas que en las demás gestiones; los administradores mantienen su alcance general.

El formulario unifica los dos exports JetFormBuilder proporcionados en `resources/forms/property-entry.json`: **Nuevo inmueble** genera un código WordPress; **Completar un código adelantado** conserva el código de un inmueble existente, en borrador y asignado al funcionario (o gestionado por un administrador). No permite sobrescribir inmuebles publicados ni códigos de otros funcionarios sin acceso administrativo. Conserva los 98 campos, agrupados en secciones, y sus condiciones para recaptación, propiedad horizontal, precios, horarios, Club PPH, RNT y permutas. Las etiquetas y valores proceden de los glosarios serializados y consultas CCT en `wp_jet_post_types`; las opciones de oferta, tipo y destinación proceden de las taxonomías WordPress correspondientes. Los catálogos de ubicación conservan la dependencia país → departamento → ciudad → barrio; el barrio determina la ruta. El servidor vuelve a validar las opciones y condiciones, y calcula la antigüedad sin confiar en valores enviados por el navegador.

Se conservan los efectos de `funcion-agregar-inmueble` y `crear-reporte-captacion`: post y metadatos WordPress, registro CCT del inmueble, relaciones `941` con el funcionario y `658` con el propietario, datos del aliado PPH cuando corresponde y respuesta en el historial de la tarea. También se guarda el historial del inmueble y, para recaptaciones, su registro CCT. Referencia catastral, estrato y procedencia se guardan en sus columnas propias. El estado del inmueble queda **En borrador** y la tarea pasa a **En proceso / Por publicar**, vinculada al código y al ID CCT generados. El número de tarea, funcionario, sucursal, fecha y relaciones se resuelven en el servidor. La subida opcional del uso del suelo acepta un PDF de hasta 6 MB; las fotografías continúan en el flujo existente enlazado al terminar el registro.

Los correos administrativos y del funcionario se encolan en `skc_notification_queue`, con `source_module=commercial_property_entries`, usando el worker compartido. `PROPERTY_ENTRY_NOTIFICATION_EMAILS` permite ajustar los destinatarios administrativos. La tabla auxiliar `wp_scm_property_entry_requests` guarda una solicitud única por envío y se crea al primer registro (permiso `CREATE` requerido en ese momento). Los registros de negocio, historiales, relaciones y mensajes se confirman en una transacción; los reintentos del mismo envío no crean duplicados. La corrección no usa el panel externo retirado anteriormente.

Para regenerar la definición a partir de una actualización de los exports, usa `node tools/import-property-entry-form.cjs <anadir-inmueble.json> <anadir-inmueble-por-codigo-adelantado.json>`. El importador extrae definiciones y mapas; no ejecuta HTML ni scripts de los archivos de origen. Las pruebas del flujo usan SQLite y catálogos de prueba, sin crear inmuebles ni enviar mensajes en producción: `phpunit --filter CommercialPropertyEntryTest` y, con el servidor local de pruebas en el puerto `8771`, `node tools/check-commercial-property-entry.cjs`.

## Herramientas

El menú **Herramientas** del encabezado agrupa calculadoras para el equipo comercial. Requiere la vista **Herramientas** y su subpestaña en **Configurar permisos**; los cargos con configuración explícita deben habilitarlas.

**Simulador de ingreso neto del propietario** calcula lo que le queda al propietario cada mes y el crédito preaprobado al que accede, para mostrarle que puede ponerse al día con servicios públicos, administración o reparaciones sin sacar dinero de su bolsillo. Cada inmueble se simula con las tarifas de SuCasa, con la misma lógica del plugin *Calculadora de comisión, garantía inmobiliaria y seguro de servicios públicos* (`herramientas/calculadora-seguros-comisiones`): comisión sobre canon + administración más IVA, garantía según la aseguradora, pólizas de Berkley o Seguros Mundial y seguro de servicios públicos (hasta 4 cuotas sin intereses; desde 5, financiado con `tasa_prestamo_garantias_servicios`). Las tarifas se leen de sus filas de `wp_jet_cct_confi_sistema` (`iva`, `tasa_administracion_arriendo`, `tasa_*_seguro`, `tasa_*_servicios`, `tasa_berkley`, `tasa_seguros_mundial` y sus gastos de expedición) y el cálculo está en `CommercialNetIncomeSimulator` (PHP) y en `commercial-tools.js`. O bien se usan los **valores del contrato**: con un propietario registrado se busca por cédula o NIT en los contratos Entregado o Por entregar (documento del contrato, propietarios del mandato 1 a 6 y ficha del propietario) y se cargan el canon y los descuentos del mandato, o el porcentaje sin mandato. Ingreso neto mensual = canon − comisión − garantía − (seguro + intereses) / 12 − otros; crédito preaprobado = ingreso neto de todos los inmuebles × los meses de política (`income_months`, 3 por defecto), la regla del liquidador de créditos de Control de Servicios Inmobiliarios. El consultor puede escribir cuánto necesita el propietario para cada uso (servicios, administración, reparaciones, otros) y ver si el crédito lo cubre. Todo es editable y no se registra nada. El plazo máximo estimado son los meses de 30 días hasta el fin del contrato más largo menos el margen (`term_margin_months`). La configuración del crédito se lee, sin modificar, de `owner_credit_settings` en `control_servicios_config`. **Copiar mensaje para WhatsApp** arma el texto para el propietario. **Descargar PDF para el propietario** envía los valores al servidor, que recalcula la simulación (`CommercialOwnerPreapprovalService::estimate`) y genera el PDF con el diseño de las actas de revisión de servicios públicos del panel inmobiliario (`CommercialOwnerPreapprovalPdf`, `ticket-completion-document.css`, `public-services-document.css` y `owner-preapproval-document.css`): membrete actual, ingreso neto y crédito preaprobado, detalle por inmueble con sus unidades (mes, 12 meses), lo que puede resolver con el crédito y el aviso de que no es una aprobación.

El PDF se convierte con Chromium mediante `HtmlPdfRenderer`, igual que en el panel inmobiliario: en producción con el servicio Gotenberg del VPS (`SCM_GOTENBERG_URL`, `SCM_GOTENBERG_USERNAME`, `SCM_GOTENBERG_PASSWORD`; requiere la extensión cURL) o, si no está configurado, con un Chromium, Chrome o Edge local (`SCM_CHROMIUM_BIN` si no está en una ruta estándar). Verifica la configuración con `php bin/check-pdf-service.php`.

**Calculadora de ganancia ocasional** estima el impuesto por la venta de un inmueble: 15 % de ganancia ocasional si se poseyó 2 años o más y no es giro ordinario (con la exención de vivienda de 5.000 UVT del art. 311-1 E.T.), o renta ordinaria con la tasa que indique el consultor. La UVT es editable y debe actualizarse cada año (`CommercialToolsView::UVT`).

Pruebas: `phpunit --filter CommercialOwnerPreapprovalTest` y, con el servidor local en el puerto `8771`, `node tools/check-commercial-tools.cjs`.

## Notificaciones comerciales

La pestaña **Notificaciones** reutiliza la búsqueda, filtros por contrato y preferencias del módulo inmobiliario. Incluye propietarios activos, propietarios no activos, arrendatarios activos, arrendatarios no activos, copropiedades y Club PPH. Activos significa que tienen al menos un contrato Entregado; no activos significa que tienen un contrato Recibido y ningún contrato Entregado. Esta clasificación se valida al buscar, contar, seleccionar todos y enviar. No incluye proveedores ni selección desde archivos SIMI.

En **Configurar permisos** se puede habilitar la vista **Notificaciones comerciales** y la acción **Enviar notificaciones comerciales** por cargo. Los cargos con acceso total ven todos los contactos y envíos. Los demás solo ven contactos creados por su `id_empleado`, contactos relacionados por `id_propietario`, `id_arrendatario` o `id_copropiedad` con inmuebles/contratos asignados al funcionario y miembros de Club PPH con su `id_empleado`. La misma restricción se aplica en el servidor al buscar, seleccionar todos y enviar. Su cola solo muestra sus propios envíos.

Los mensajes van a `skc_notification_queue` con `project_code=control-servicios-comerciales` y `source_module=commercial_notifications`. Los procesa el worker global existente; no hay envíos directos ni un cron nuevo. Se respetan bloqueos por canal y preferencias de notificaciones; WhatsApp también respeta `permite_marketing_whatsapp`. El identificador del envío y un bloqueo de MySQL/MariaDB impiden duplicados ante reintentos.

La cola muestra **Creada por** (nombre, cargo al crear e `id_empleado`) y **Ver mensaje**, que consulta el texto/HTML realmente guardado, asunto, plantilla, adjunto disponible, fechas de creación/envío y último error. El correo se muestra en un iframe sin permisos de scripts. La autoría identifica al funcionario que solicitó el envío; el transporte lo ejecuta el worker. Se registra también el ID interno de usuario para nuevos mensajes. Los históricos conservan la autoría que ya tenían en `meta_json`; cuando falta se muestran como **Sin autor registrado**.

**Eliminar notificaciones comerciales (solo administradores)** figura en Configurar permisos y está ligado a **Acceso Total Administrativo**. No se puede delegar individualmente y se valida también en la API. Eliminar retira el mensaje de la cola mediante metadatos de auditoría: conserva contenido, autor, intentos y clave de deduplicación, y registra quién eliminó y cuándo. Un pendiente pasa a cancelado con una actualización atómica compatible con el claim del worker; uno en procesamiento no se permite eliminar. Un enviado conserva su estado y no se retira del destinatario. No se requieren nuevas tablas ni migraciones.

**Informe por funcionario** muestra una sola fila por `id_empleado`, con columnas de cantidades de WhatsApp, Correo y SMS, total, estados y eliminados. Conserva una sola fila aunque cambien el nombre o cargo históricos del funcionario y muestra su identidad registrada más recientemente. Permite filtrar por ID y fechas de creación (días completos de Colombia). Incluye los eliminados en el total; la columna Eliminados se solapa con los estados. Cada mensaje por canal cuenta una vez. Los administradores consultan todos los autores del módulo y los demás solo sus registros, incluso al manipular filtros. **Descargar CSV** exporta el mismo resultado agrupado con protección ante fórmulas de hoja de cálculo.

**Editar datos** aparece junto a cada destinatario para los cargos con **Editar datos de actores y registros relacionados**. Este permiso está habilitado por defecto para administradores; se puede delegar desde Configurar permisos y conserva el alcance del destinatario. Solo admite documento/NIT, nombre, correo, celular e indicativo. Para personas jurídicas se edita la razón social y NIT cuando existen, conservando los datos del representante. Copropiedades usa `copropiedad`, `nit` y `contacto`; Club PPH usa `telefono` como celular.

**País / indicativo** muestra el nombre del país y su código (por ejemplo, Colombia (+57)) desde el catálogo `jet_cct_paises`. Los países que comparten indicativo se muestran juntos, porque el actor guarda únicamente el código telefónico. Abrir el selector conserva el valor existente; los códigos sin país registrado siguen disponibles. Si falta el catálogo, se ofrece Colombia (+57). Tanto los datos actuales como la comparación muestran el país cuando se conoce. Los registros relacionados se organizan en acordeones por categoría marcada, cerrados inicialmente, con cantidades y selección individual. La comparación usa los mismos acordeones; el registro principal permanece visible.

Al abrir el editor se muestran los inmuebles, contratos de mandato, arrendamientos y cierres vinculados al actor, con ID, código o contrato, estado, dirección y datos actuales cuando están disponibles. Cada grupo indica cuántos registros contiene y permite marcarlos en conjunto o individualmente. **Ver cómo quedarán los datos** abre la comparación incluso sin editar: muestra todos los registros seleccionados con valores actuales y finales, resalta los campos que cambiarán e identifica los registros sin cambios. **Guardar cambios** aparece en el pie del editor únicamente al abrir la comparación y se oculta al volver a editar; se habilita después de revisar y marcar la confirmación, siempre que existan cambios seleccionados. Cambiar la selección obliga a confirmar nuevamente. Solo se escriben y auditan registros con cambios. Revisa todos los estados, incluidos cerrados e históricos. Solo actualiza columnas existentes y relacionadas por IDs del actor; no modifica IDs, propietarios de registros, preferencias de mensajería, información financiera ni PDFs emitidos. En mandatos respeta los seis bloques de titulares y las empresas jurídicas; los mandatos antiguos de un solo titular se resuelven por su ID principal. Si los IDs coinciden ambiguamente con otro actor, muestra la advertencia y bloquea la propagación para evitar modificar datos ajenos; sigue disponible la edición del registro principal. El JavaScript de notificaciones usa una URL versionada por el contenido del archivo para invalidar la caché al desplegar cambios.

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

## Calendario comercial

Usa el mismo motor y la misma API de `calendario-actividades` que el panel administrativo (`CALENDAR_API_URL`), con contenido comercial:

- **Mi calendario / Calendario equipo**: eventos y recordatorios con vista mes/semana/día, creación simple, múltiple y recurrente, edición, traslado, cierre, pendientes vencidos, informe del día y sincronización con Google Calendar (cada funcionario autoriza su propia cuenta). Las tareas del calendario ya no existen en la API, por eso no hay capa ni botón de "Tarea".
- **Categorías y funcionarios**: se eligen en **Configuración → Calendario comercial** (settings `commercial_calendar_categories`, vacío = todas, y `commercial_calendar_cargos`; sin este último se usa `CALENDAR_ALLOWED_CARGOS`). Los eventos existentes conservan su categoría.
- **Vencimientos**: `commercial_calendar_due` (`CommercialCalendarService`) arma tareas atrasadas y por vencer según el SLA por tema (días hábiles de Colombia), seguimientos programados (`fecha_seguimiento`), actualizaciones de inmuebles, retoques y avisos nuevos, y llamadas de precaptación. Cada tipo exige la vista correspondiente; lo vencido antes del mes visible se acumula en el día de hoy.
- **Desde la tarea**: la tarjeta **Agenda comercial** del popup permite agendar una cita vinculada (`id_ticket`), ver la agenda del responsable con cruces de horario y sus pendientes vencidos. La API del calendario reescribe `estado_comercial` y `estado_administrativo` de la tarea, así que se envían el estado comercial elegido y el administrativo actual. Las citas encolan WhatsApp al funcionario y al solicitante (`commercial_calendar_cita_notify`).

Prueba de navegador con SQLite aislado y la API del calendario simulada:

```powershell
php -S 127.0.0.1:8769 -t .
node tools/check-commercial-calendar.cjs RUTA_A_NODE_MODULES
```

## Configuración

El menú **Configuración** del encabezado (solo cargos administradores) reúne toda la configuración del panel, como en el panel administrativo. Cada sección se carga y guarda con `commercial_settings_load` / `commercial_settings_save` (`CommercialSettingsService`):

| Sección | Qué configura | Dónde se guarda |
|---|---|---|
| Permisos del panel | Vistas, subvistas, acciones y cargos visibles | `commercial_permissions`, `commercial_admin_cargos`, `commercial_employee_cargos` |
| Calendario comercial | Categorías y cargos de los funcionarios | `commercial_calendar_categories`, `commercial_calendar_cargos` |
| Tiempos de atención | Alerta y vencimiento por tema comercial (días hábiles) | `control_tickets_sla_config` (compartido con los informes de tareas) |
| Avisos en fachada | Días de retoque e instalación, valor del aviso, transporte y factor prestacional | Filas `dia-retoque-*`, `dias-atraso-aviso-nuevo`, `valor_aviso`, `valor_transporte`, `factor_prestacional` |
| Actualizaciones de inmuebles | Días de alerta y vencimiento por tipo de negocio | `commercial_property_update_limits` |
| Cupos de destacados | Máximo de destacados por portal | Filas de cada portal en `jet_cct_confi_sistema` |

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

## Multimedia del inmueble desde las tareas

**Crear video con fotos** abre un estudio para seleccionar y ordenar de 2 a 12
fotografías, escribir textos, añadir música opcional y exportar un MP4 vertical.
Usa un servicio propio de Python/FFmpeg sin créditos de IA; incluye vista previa,
cola con progreso, cancelación y descarga protegida. Para instalarlo en un VPS y
activar `PROPERTY_VIDEO_URL`/`PROPERTY_VIDEO_TOKEN`, consultar
[services/property-video/README.md](services/property-video/README.md).

**Ingresar inmueble** reúne datos, detalles, ubicación, características y fotos/video
en cinco secciones de un mismo formulario. **Guardar inmueble** registra los datos
y sube la multimedia seleccionada; si una subida falla, el reintento conserva el código
ya creado. Los códigos adelantados pueden recuperar su multimedia actual antes de guardar.
La tarea también muestra **Multimedia del inmueble** cuando ya tiene un inmueble asociado.
Este paso usa el permiso `ingresar_inmueble` y comprueba la asignación de la tarea y
la relación entre tarea, inmueble y adjuntos. Permite portada, galería ordenable y video
HTTPS de YouTube; guarda los IDs de WordPress y los campos del CCT sin invocar `portales`
ni conectar con el antiguo panel externo.

Las características internas, externas, alrededores y zonas sociales se eligen con
tarjetas de iconos, búsqueda y contadores. Al elegir barrio se consultan hasta 200
inmuebles registrados en ese barrio y ciudad para mostrar frecuencias; solo se sugieren
valores existentes en los catálogos. No se marcan automáticamente características
de otros inmuebles. El mapa de iconos se adapta de `sucasa-inmuebles-premium` mediante
`tools/import-property-feature-icons.cjs`.

El editor integrado permite tamaño/proporción, calidad JPG, brillo, contraste,
saturación, desenfoque, giros, texto, figuras y marcos, con deshacer/rehacer.
Usa una copia local de Fabric.js (licencia en `public/assets/vendor/FABRIC-LICENSE.txt`).

**SuCasa Studio** añade zoom/ajuste a la ventana, comparación con el original,
recorte, volteos, capas y atajos de deshacer/rehacer. La vista inicial conserva el
backstore del lienzo en pantallas Retina/DPR 2 y 3. La mejora automática ajusta
luminancia, balance de color y nitidez en un Web Worker. El pincel de retoque local
reconstruye zonas pequeñas desde los píxeles vecinos, sin enviar la foto a una API;
funciona mejor en superficies simples y no equivale a inpainting generativo.

El borrado generativo está preparado para [Clipdrop Cleanup](https://clipdrop.co/apis/docs/cleanup).
Para activarlo, configurar `CLIPDROP_API_KEY` en el `.env` del servidor. La clave nunca
se incluye en el navegador. El usuario marca una máscara y pulsa explícitamente
**Eliminar objeto con IA**; solo entonces se envían foto y máscara a Clipdrop.
El endpoint exige sesión, nonce, permiso de ingreso y alcance de la tarea; valida
formatos, dimensiones, tamaños y la imagen devuelta. Las pruebas usan un transporte
simulado y no consumen créditos. El estado sin clave mantiene las herramientas locales.

La [API de imagen de MiniMax](https://platform.minimax.io/docs/guides/image-generation)
documenta generación con referencia de sujeto; no se usa para este borrado por máscara.
La integración existente de MiniMax para tareas continúa independiente.

Prueba de editor: `node tools/check-commercial-editor.cjs`, con la misma fixture local
en `127.0.0.1:8771`. Verifica píxeles visibles al abrir en DPR 2, redimensionado,
retoque sin modificar el exterior de la máscara, mejora, recorte, capas y exportación.

Las imágenes JPG/PNG se envían individualmente, con un máximo de 1 MB y dimensiones
de 500 × 500 a 1700 × 1200. El navegador ajusta las imágenes grandes; el servidor
vuelve a validar el archivo. La galería admite 12 imágenes, o 6 para locales, lotes y
bodegas; conserva el límite superior de galerías históricas ya existentes.

Los archivos públicos se guardan en `../wp-content/uploads` respecto a la carpeta del
proyecto, con registros `attachment`, `_wp_attached_file` y `_wp_attachment_metadata`.
Si WordPress usa otra ubicación, configurar `WORDPRESS_UPLOAD_PATH` y
`WORDPRESS_UPLOAD_URL`. No se carga el núcleo de WordPress ni sus hooks. Quitar una
foto del formulario la retira de la selección; no borra el adjunto de la biblioteca.

Después de actualizar el servidor hay que recargar las pestañas abiertas. Los scripts
usan el hash de su contenido para que el navegador solicite la versión correspondiente.
Si falla la apertura de un formulario, el modal conserva el mensaje del servidor y
ofrece reintentar o volver a la tarea.

Pruebas aisladas: `php .vendor-test-junction/phpunit/phpunit/phpunit --filter
'CommercialPropertyMediaTest|CommercialPropertyEntryTest|CommercialSignServiceTest'`.
Con el servidor PHP en `127.0.0.1:8771`, `node tools/check-commercial-property-entry.cjs`
comprueba además las subidas, redimensionado, persistencia, errores 422 y vista móvil.
