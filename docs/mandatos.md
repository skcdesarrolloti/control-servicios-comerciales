# Contratos de mandato en el panel comercial

El mandato ya se hace completo en el panel: crear, editar, revisión del propietario y firma digital (eSignatures). Los
formularios de WordPress (`formularios/`) siguen funcionando sobre la misma tabla mientras se dejan de usar.

## Flujo en la tarea

Tarea → cotización → estudio de aseguradora → cierre → **contrato de mandato** → hoja de cierre. En el popup de la tarea,
**Gestión operativa › Contrato de mandato** muestra los mandatos de la tarea (los creados desde ella, los de sus estudios y
los de su inmueble) con su revisión y sus acciones:

1. **Nuevo mandato**: el formulario por pasos (contrato y propietarios, inmueble, valores, garantías, condiciones). Trae
   el inmueble de la tarea o del estudio aprobado y su propietario; enlaza el estudio aprobado.
   - Cada propietario y la copropiedad preguntan **"¿Ya está registrado?"** (como `formularios/comun/js/propietario_registrado.js`):
     **Sí, buscarlo** muestra el buscador y carga su ficha (si se cambia un dato, también se actualiza en la ficha);
     **No, es nuevo** deja los datos en blanco y se registra al guardar. Si el documento escrito ya existe, avisa y
     ofrece **Usar ese propietario** (al guardar nunca se duplica un documento). Los datos se ven después de elegir.
   - **País** obligatorio: se toma de la ficha sin importar mayúsculas ni tildes ("Estados unidos" = "Estados Unidos")
     o por su indicativo; sin país, Colombia.
2. **Revisión del propietario** (opcional): se le envía el contrato (correo y WhatsApp); deja observaciones o lo aprueba.
3. **Hoja de cierre**: se hace y se aprueba en el panel inmobiliario (`hoja-cierre.php`), que refleja su estado en el
   mandato (`estado_hoja_cierre`, `id_hoja_cierre`).
4. **Editar**: corrige lo que pidió el propietario. Si ya estaba enviado a firma y la hoja de cierre sigue aprobada,
   actualiza el contrato en eSignatures; si no, queda para **Reenviar a firma**.
5. **Enviar a firma**: solo cuando **la hoja de cierre está aprobada** (`Aprobada`, o `Realizada` si ya tiene contrato
   de arrendamiento). Si falta, cada mandato lo dice ("Para enviar a firma falta") y el servidor no lo envía
   (`CommercialMandatesService::signBlockers`). La revisión del propietario no es obligatoria: si no la aprobó, solo se
   advierte (`reviewNotice`). Crea el contrato en eSignatures o modifica el mismo.
6. Al firmar todos, eSignatures avisa al panel: el mandato queda **Firmado** con su PDF.

Lo mismo se hace desde **Registros › Mandatos** (y Nuevo mandato sin tarea, escribiendo el código del inmueble).

El formulario es copia de `formularios/crear-mandato` (mismos campos y cálculos: comisión, garantía inmobiliaria,
garantía de servicios con préstamo en más de 4 cuotas, cláusula penal, Berkley y Seguros mundial). El servidor recalcula
el crédito de la garantía (`MandateCalculator`) y lo guarda en `wp_jet_cct_creditos`, como el formulario de WordPress.

## Configuración en el servidor

- `.env` del panel: `ESIGNATURES_TOKEN` (el token secreto de eSignatures) y `ESIGNATURES_TEST=false`. Lo leen el panel y
  los formularios de WordPress (`formularios/comun/conexion.php`).
- Webhook de eSignatures (panel de eSignatures › API): `https://sucasainmobiliaria.com.co/dashboard-comercial/public/esignatures-webhook.php`.
  El de WordPress (`/formularios/comun/webhook_esignatures.php`) también sirve: los dos actualizan la misma tabla.
- Columna `id_ticket` del mandato (tarea de donde se creó): `php bin/add-mandate-task-field.php --aplicar`. Sin ella, la
  tarea encuentra sus mandatos por el estudio y el inmueble.
- Plantillas, título, roles y representante legal están fijos en `MandateSignature` (como `esignatures_config.php`).

## Revisión del propietario

- **Actividades comerciales › Registros › Mandatos** (y **Mi portafolio › Mis mandatos**): todos los mandatos de
  `wp_jet_cct_contrato_mandato`, con el estado del mandato (No enviado / Enviado a firma / Firmado) y el de la revisión
  del propietario. **Ver** abre el mandato en un popup.
- **Página del mandato** `public/mandato.php`:
  - `?m=<enlace firmado>`: la ve el **propietario** sin iniciar sesión. Lee el contrato por secciones (propietarios,
    inmueble, copropiedad, servicios, canon y comisión, garantías, condiciones), deja **observaciones** en la sección que
    deba cambiar (o un comentario general) y, cuando todo está correcto, **aprueba** el contrato. El enlace vence a los
    120 días.
  - `?id=<mandato>`: la ve el **equipo** con la sesión del panel. Muestra lo mismo, más el enlace del propietario
    (Copiar), **Enviar al propietario por correo y WhatsApp**, la respuesta a cada observación (y marcarla resuelta) y el
    enlace al panel para editarlo o enviarlo a firma.
- **Contrato completo**: la página siempre muestra el contrato completo (cláusulas y anexos) tal como se enviará a firma,
  lleno con los datos del mandato (`MandateContractText`). El texto se lee de la plantilla vigente en eSignatures
  (`GET /api/templates/{id}/content`) y se guarda una hora en `storage/data/mandatos-plantillas/`; si eSignatures no
  responde, se usa la última copia o la de `resources/mandatos/` (`por-defecto.md`, `berkley.md`, `seguros-mundial.md`).
  Cambiar el texto en eSignatures basta: en máximo una hora se ve aquí.
- En el panel, cada mandato tiene **Revisión del propietario**, **Ver contrato** y **Vista pública** (lo que ve el
  propietario con su enlace: `mandato.php?id=…&como=propietario`, sin marcarlo como visto ni dejar escribir); el popup
  cambia entre las tres vistas.
- La aprobación guarda una huella del contenido: si el mandato cambia después, queda en **"Cambió después de aprobar"** y
  hay que enviárselo otra vez. No se puede aprobar con observaciones pendientes.
- La revisión es opcional: se puede enviar a firma sin ella (el panel solo lo advierte). Lo obligatorio es la hoja de
  cierre aprobada, que los formularios de WordPress (`formularios/enviar-mandato`) no validan: conviene dejar de usarlos.

## Enlace para el listado de mandatos de WordPress (mientras se migra)

Botón **"Revisión del propietario"** en el listado de JetEngine (`%_ID%` es el id del mandato):

```
https://sucasainmobiliaria.com.co/dashboard-comercial/public/index.php?tab=mandatos&abrir_mandato=%_ID%
```

Abre el panel en Mandatos con el popup de ese mandato (pide iniciar sesión si hace falta y vuelve ahí). Alternativa sin
el panel alrededor: `https://sucasainmobiliaria.com.co/dashboard-comercial/public/mandato.php?id=%_ID%`.

## Permisos

- Vista **Contratos de mandato** (`mandatos`), con alcance: solo sus mandatos (por `id_empleado`) o todo el equipo.
  Los cargos ya configurados en Permisos deben marcarla.
- Acciones (grupo **Contratos de mandato**): `mandato_crear` (crear y editar), `mandato_enviar_revision`,
  `mandato_responder_observaciones` y `mandato_enviar_firma`.

## Avisos

- Al propietario (cada uno de los del mandato, salvo que haya bloqueado el canal en su ficha): correo y WhatsApp con el
  enlace.
- Al equipo (**Configuración › Notificaciones internas › Contratos de mandato**; quien creó el mandato siempre):
  `mandato_revision_enviada`, `mandato_observaciones` y `mandato_aprobado`.

### Plantilla de WhatsApp (crear en Meta)

- **Nombre:** `scm_mandato_revision_propietario_v1`
- **Idioma:** Español (Colombia) `es_CO` · **Categoría:** Utilidad
- **Cuerpo:**

```
Hola {{1}}, preparamos el contrato de mandato #{{2}} de su inmueble {{3}} para que lo revise antes de la firma digital.

Si algo debe cambiar, puede dejar sus observaciones en el enlace; si todo está correcto, puede aprobarlo ahí mismo.

*Su consultor/a asignado es* {{4}}.

*Construimos confianza, protegemos su patrimonio.*
```

- **Ejemplos:** `{{1}}` Lilia Granados · `{{2}}` 629 · `{{3}}` Cll 30 No 19-30 (código 28355) ·
  `{{4}}` Monica Acuña Barrios · Correo: skconsultorinmobiliario@gmail.com · Celular: 3107545514
- **Botón** de URL dinámica, texto **"Revisar contrato"**:
  - URL: `https://sucasainmobiliaria.com.co/dashboard-comercial/public/mandato.php?m={{1}}`
  - Muestra: `https://sucasainmobiliaria.com.co/dashboard-comercial/public/mandato.php?m=629.1795000000.3f6c1a9e0b7d4c2a8e5f1b3d7a9c0e2f4b6d8a1c3e5f7092b4d6f8a0c2e4b6d8`

## Tablas propias (se crean solas al primer uso)

- `wp_scm_mandato_revision`: envíos al propietario, primera vez que lo abrió y aprobación (con la huella).
- `wp_scm_mandato_observaciones`: observaciones del propietario por sección y la respuesta del equipo.

## Formularios de WordPress (`formularios/`)

Ya no tienen contraseñas en el código: `comun/conexion.php` toma la base de datos (DB_*) y `ESIGNATURES_TOKEN` del
`.env` del panel (`public_html/dashboard-comercial/.env`). Cuando el equipo deje de usarlos, se pueden apagar.
