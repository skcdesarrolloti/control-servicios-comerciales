# Estudios de aseguradora

Reemplaza los formularios JetFormBuilder **Agregar estudio de aseguradora** y **Respuesta estudio de aseguradora** y la página de WordPress `/estudio-aseguradora/?id_estudio=`. Guarda en la misma tabla `jet_cct_estudios_aseguradoras`, así que los estudios anteriores se ven y se responden igual, y el panel inmobiliario y la hoja de cierre los siguen leyendo.

## Dónde se usa

- **Popup de la tarea › Gestión operativa › Estudio de aseguradora**. Sale en las tareas de *Arriendo* y *Arriendo o venta*, y en cualquier tarea que ya tenga estudios (con su cantidad en el botón). Desde ahí:
  - **Nuevo estudio**: aseguradora (CCT `aseguradoras`), número de solicitud, valor del canon y datos del cliente (salen de la tarea);
  - **Registrar respuesta** (mientras esté *Esperando respuesta* o *Aplazado*);
  - **Editar** (salvo desistidos);
  - **Desistir negocio** (estudio aprobado que todavía no se entrega);
  - **Ver estudio** (popup con la vista interna).
- **Actividades comerciales › Registros › Estudios** (todo el equipo o solo lo suyo, según el alcance del cargo) y **Mi portafolio › Mis estudios** (siempre solo lo de la persona). Pestañas por estado con su cantidad, búsqueda, aseguradora, consultor/a y fechas. Cada fila tiene **Ver**, el enlace a la tarea y **Desistir negocio** cuando aplica.
- **`public/estudio.php`**:
  - `?e=<enlace firmado>`: el cliente, sin sesión. Va en el correo y en el WhatsApp y vence al año (`PublicStudyAccess`). Muestra el estado explicado, el avance (enviado → respuesta → entrega), los datos del estudio y su consultor/a asignado. No muestra llaves, propietario, casos ni Drive.
  - `?id=<estudio>`: el funcionario con la sesión del panel. Además ve los datos internos (llaves, caso de entrega, contrato, mandato, Drive y el enlace del cliente). Lo abre quien hizo el estudio, quien puede abrir su tarea o quien ve todo el equipo en Estudios.
  - Los botones "Ver estudio aseguradora" de la cronología y del caso de entrega ya abren esta vista, no la de WordPress.

## Qué hace cada paso

| Paso | Estudio | Tarea comercial | Además |
| --- | --- | --- | --- |
| Nuevo estudio | `Esperando respuesta`, con creador, sucursal, cliente, `medio` de la tarea | `En estudio` / `En proceso` | Historial de la tarea. Correo y WhatsApp al cliente. Correo a quien lo hizo y a *Estudio enviado a una aseguradora* |
| Aplazado | `faltante` (obligatorio) | Sigue `En estudio` (pendiente de documentos, no postergada) | Al cliente le llega lo que falta |
| Esperando respuesta (desde Aplazado) | De nuevo en estudio | `En estudio` | Aviso al cliente |
| Rechazado | Observación opcional para el cliente | `Rechazado` / `Cerrado` | |
| Desistido | Razón del glosario 1463 + observación | `Desistido` / `Cerrado` | |
| Aprobado | Inmueble, llaves, fecha posible de entrega, `estado_contrato = Por entregar`, caso y contrato | `Aprobado` / `En proceso` | Ver **Aprobación** |

Todas las respuestas avisan al cliente (correo y WhatsApp), a quien hizo el estudio y a *Respuesta de un estudio*. `estado_administrativo` de la tarea comercial nunca se toca.

### Aprobación

En una transacción, lo mismo que hacía el formulario de WordPress:

- **Caso de entrega** en `tickets`: *Entrega de inmuebles* · *REVISION ANTES DE LA OCUPACION*, prioridad urgente, con propietario, llaves, consultor/a de la entrega (quien aprueba), fecha posible y enlace de Drive (`anexos_entrega`). Se asigna al **responsable de las entregas**.
- **Contrato** en `contratos_arrendamiento`: `No asignado`, `Por entregar`, con aseguradora, solicitud, servicios públicos, medio y los datos del inmueble.
- **Inmueble** (CCT y post de WordPress): `En proceso de cierre`, con `id_estudio_aseguradora` e `id_contrato_arrendamiento`.
- Historial del caso de entrega, de la tarea y del inmueble.
- Correo al responsable de las entregas y a *Estudio aprobado: caso de entrega creado*. Correo al propietario avisando la revisión antes de la ocupación.

### Desistir el negocio

Copia de `ClosingSheetDesist::desist` del panel inmobiliario (el JetForm *Desistir negocio*). Pide motivo (glosario 1463), observación opcional y confirmación:

- el contrato `No asignado` queda `Desistido` (con su historial);
- el inmueble vuelve a `En borrador`;
- el estudio queda `Aprobado` con `estado_contrato = Desistido`;
- el caso de entrega se cierra (`Cerrado`, `estado_administrativo = Desistido`, como el formulario);
- la tarea comercial queda `Desistido` / `Cerrado`.

Con **Cambio de inmueble** el estudio vuelve a *Esperando respuesta* y la tarea a *En estudio*, para aprobarlo con otro inmueble. Si el contrato ya tiene número, se desiste en *Contratos de arrendamiento* del panel inmobiliario (**Marcar desistido**). La hoja de cierre pasa a *Desistida* sola (sincronización del panel inmobiliario). Avisa a quien hizo el estudio, al responsable de la entrega y a *Negocio desistido desde un estudio*.

## Mejoras frente a los formularios de WordPress

- Un estudio ya respondido no se vuelve a aprobar: el formulario creaba otro caso y otro contrato cada vez que se enviaba.
- Solo se aprueban inmuebles públicos o en borrador **y con propietario** (sin propietario el formulario aprobaba sin crear nada).
- Todo en una transacción: si algo falla, no queda nada a medias.
- El responsable del caso de entrega se elige en Configuración (estaba fijo en el formulario, también en el texto del caso) y los avisos internos en Notificaciones internas (eran correos fijos).
- Quien responde ya no reemplaza a quien hizo el estudio (`id_empleado`).
- Aplazado deja la tarea abierta *En estudio*: antes la mandaba a postergadas.
- El historial de la tarea dice qué pasó (el formulario lo dejaba vacío) y los enlaces ya no van a la página de tickets anterior.
- El cliente recibe WhatsApp además del correo, y un enlace propio para seguir su estudio.
- Al editar, si ya hay caso y contrato, se les corrige también aseguradora y número de solicitud.

## Configuración

- **Configuración › Actividades comerciales › Estudios**: responsable de las entregas. Sin elegir, se usa quien atendió la última entrega creada desde un estudio.
- **Configuración › Notificaciones internas › Estudios de aseguradora**: estudio enviado, respuesta, aprobado y negocio desistido.
- Las plantillas de WhatsApp (11 y 12) están en `docs/plantillas-whatsapp-tareas-comerciales.md`, siempre activas.

## Permisos

- Pestaña **Estudios de aseguradora** (`estudios`), grupo Actividades comerciales, con alcance *Solo sus estudios* / *Estudios de todo el equipo*.
- Acciones (grupo *Estudios de aseguradora*): `estudio_crear` (crear y editar), `estudio_responder`, `estudio_desistir`. Los cargos sin configurar las tienen; en los cargos con permisos ya guardados hay que marcarlas.

## Pendiente

- **Crear mandato** desde el estudio aprobado (botón en Gestión operativa): fase siguiente.

## Pruebas

`tests/Unit/CommercialStudiesTest.php` y el recorrido en navegador `node tools/check-commercial-studies.cjs <node_modules>` con `php -S 127.0.0.1:8776 -t .`.
