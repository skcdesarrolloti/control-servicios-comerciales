# Reglas operativas del panel de servicios comerciales

- Después de cada cambio solicitado y verificado, hacer commit y push a `main`.
- Toda configuración del panel se abre desde el menú **Configuración** del encabezado (`#commercial-config-menu` en `CommercialDashboardView::render`), no con botones dentro de los paneles. Las secciones se cargan y guardan con `commercial_settings_load` / `commercial_settings_save` (`CommercialSettingsService`); el menú solo lo ven los cargos administradores.
- Los valores que comparten otras aplicaciones (SLA de tareas `control_tickets_sla_config`, plazos y valores de avisos, cupos de destacados) se guardan en su fila de `wp_jet_cct_confi_sistema`; la configuración propia del panel va en `Settings` (`control_servicios_comerciales_config`).
- No cambiar `estado_administrativo` de las tareas salvo que el usuario lo pida. La API del calendario reescribe `estado_comercial` y `estado_administrativo` al vincular un evento a una tarea, así que siempre se envían los valores actuales.
- El calendario comercial reutiliza el motor de `admin-dashboard-runtime.js` y la API de `calendario-actividades`; mantener el comportamiento alineado con el panel administrativo (`control-servicios-inmobiliarios`) y adaptar solo el contenido comercial.
- Las notificaciones nuevas deben ser configurables cuando aplique y encolarse mediante `shared-notifications`; evitar envíos directos o destinatarios quemados en código.
- En los modales del panel, preferir popups internos y conservar el contexto de la tarea; evitar abrir pestañas nuevas si la acción puede resolverse dentro de la app.
- En registros CCT creados o modificados por funcionarios, `cct_author_id` debe guardar el `id_empleado` real de `wp_jet_cct_funcionarios`, no el `_ID` interno de la sesión.
- Las acciones destructivas deben pedir confirmación clara y dejar trazabilidad cuando el módulo ya maneja historial.
- El nombre corporativo visible en vistas, PDFs, correos e informes es `SKC SuCasa Inmobiliaria`.
- Al agregar clases de Tailwind en PHP o JS, regenerar `public/assets/css/tailwind.css` con `npm run build:css`.
- Verificar con `php tools/lint.php`, `phpunit` (`.vendor-test-junction/bin/phpunit`), `phpstan` y, para el calendario y la configuración, `node tools/check-commercial-calendar.cjs` con el servidor `php -S 127.0.0.1:8769 -t .`.
