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

La pestaña **Notificaciones** reutiliza la búsqueda, filtros por contrato, importación SIMI y preferencias del módulo inmobiliario. Incluye propietarios, arrendatarios, copropiedades y Club PPH. No incluye proveedores.

En **Configurar permisos** se puede habilitar la vista **Notificaciones comerciales** y la acción **Enviar notificaciones comerciales** por cargo. Los cargos con acceso total ven todos los contactos y envíos. Los demás solo ven contactos creados por su `id_empleado`, contactos relacionados por `id_propietario`, `id_arrendatario` o `id_copropiedad` con inmuebles/contratos asignados al funcionario y miembros de Club PPH con su `id_empleado`. La misma restricción se aplica en el servidor al buscar, importar, seleccionar todos y enviar. Su cola solo muestra sus propios envíos.

Los mensajes van a `skc_notification_queue` con `project_code=control-servicios-comerciales` y `source_module=commercial_notifications`. Los procesa el worker global existente; no hay envíos directos ni un cron nuevo. Se respetan bloqueos por canal y preferencias de notificaciones; WhatsApp también respeta `permite_marketing_whatsapp`. El identificador del envío y un bloqueo de MySQL/MariaDB impiden duplicados ante reintentos.

WhatsApp dispone de cuatro plantillas genéricas con saludo y firma automática (nombre, cargo y celular reales del funcionario). Se requiere registrar las plantillas y obtener aprobación en Meta antes del primer envío. La guía con nombres, idioma, encabezados, cuerpo y ejemplos está dentro de la pestaña. Email incluye saludo y firma; SMS conserva el límite de 160 caracteres del módulo original.

Los encabezados aceptan JPG/PNG, PDF y MP4. Los límites son 5 MB para imágenes, 100 MB para documentos y 16 MB para videos, sujetos al límite `UPLOAD_MAX_BYTES` de la app y a `upload_max_filesize`/`post_max_size` de PHP. Los archivos se guardan en `storage/uploads` y se sirven mediante `public/file.php` con firma HMAC, sin acceso por nombre solamente. El correo incluye un enlace firmado al archivo. El servidor debe poder servir esos enlaces por HTTPS al worker y a Meta.

Verificación aislada, sin contactos ni envíos reales:

```bash
php tools/check-commercial-notifications.php
```

Para revisar la interfaz con datos ficticios, genera `output/notifications-preview.html` con `php tools/preview-commercial-notifications.php` y sirve el repositorio localmente en `127.0.0.1:8769`. La prueba opcional `tools/check-commercial-notifications-ui.cjs` usa Playwright/Edge y comprueba vista previa, encabezados, selección, cola, API y diseño móvil. Su API de pruebas funciona solo en el servidor CLI local con una base SQLite en memoria.

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
