# Control de Servicios Comerciales

Panel PHP para consultar y gestionar tickets por `estado_comercial`, administrar la visibilidad y las acciones por cargo, y operar el calendario del equipo comercial.

## Alcance

- Tickets abiertos, postergados y cerrados.
- Las 23 categorías comerciales definidas en `CommercialStatusCatalog`.
- Búsqueda por ticket, asunto, solicitante, responsable e inmueble.
- Cambio de estado y reasignación con registro en el historial del ticket.
- Análisis con asistente MiniMax desde el popup de cada tarea, usando tarea, inmueble, historial, respuestas, seguimientos y notas.
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
