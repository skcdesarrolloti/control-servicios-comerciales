# Plantillas de WhatsApp de las tareas comerciales

Crea las plantillas 1 a 5, 9 y 10 en **Meta Business Suite → Administrador de WhatsApp → Plantillas de mensajes → Crear plantilla**, en la misma cuenta que usa el panel.

Para todas: categoría **Utilidad**, idioma **Spanish (COL)** (`es_CO`), tipo de variable **Número**, pie de página `SKC SuCasa Inmobiliaria` y un botón **Ir al sitio web** con URL **Dinámica**. Usa los nombres exactos. La 2 lleva encabezado de imagen y la 5 de documento (PDF).

Cada dato es una sola variable: el contacto de un funcionario (nombre, correo y celular) lo arma el sistema en una línea, como en el panel inmobiliario. Meta no acepta cuerpos que empiecen o terminen con una variable ni dos variables seguidas: por eso el cuerpo siempre cierra con la línea `*Construimos confianza, protegemos su patrimonio.*` (la 1 ya estaba creada y queda como está).

En el botón, **URL del sitio web** lleva todo lo que está antes de `{{1}}` (Meta pone `{{1}}` al final) y el panel completa solo el final. En **URL de muestra** va la URL completa. Deja sin marcar **Permite que Meta use el seguimiento de enlaces**.

En las plantillas para funcionarios (1 y 3) el final de `ticket_id=` es el enlace individual firmado de quien recibe el mensaje (`StaffTaskLink::reference`, por ejemplo `1686.1893456000.101.<firma>`): al tocar **Ver tarea** inicia su sesión y abre la tarea en su ventana. Si el enlace venció (30 días), pasa por el login y vuelve a la misma tarea. Las plantillas ya aprobadas no cambian.

Los WhatsApp siempre están activos y los nombres de las plantillas son fijos en el código (`CommercialCallsNotifier::TEMPLATE_*`, idioma `es_CO`): llegan en cuanto Meta apruebe cada plantilla.

## 1. `scm_tarea_comercial_asignada_v1`

Para el funcionario asignado y las copias internas, al crear la tarea (desde Llamadas o desde Precaptación). Ya está creada: no la cambies.

```text
Hola *{{1}}.*

Se registró la tarea comercial *#{{2}}* para gestión.

*Cliente:* {{3}}.
*Motivo:* {{4}}.
*Medio:* {{5}}.
*Responsable:* {{6}}.

Consulta los detalles en el panel.
```

Ejemplos: `{{1}}` Royner Guardo · `{{2}}` 1686 · `{{3}}` Luis Prada · `{{4}}` Arriendo · `{{5}}` Proppit · `{{6}}` Jocabeth Cantillo · Correo: jocabeth@ejemplo.com · Celular: 3001234567

Botón `Ver tarea`. URL del sitio web `https://sucasainmobiliaria.com.co/dashboard-comercial/public/index.php?tab=abiertos&ticket_id=`. URL de muestra `https://sucasainmobiliaria.com.co/dashboard-comercial/public/index.php?tab=abiertos&ticket_id=1686`.

## 2. `scm_tarea_comercial_registrada_v1`

Para el cliente, al crear la tarea (desde Llamadas o desde Precaptación). El panel inmobiliario también la envía al cliente de una retención de contrato (tarea comercial que crea ese panel), con `{{3}}` = `tu contrato 280 (inmueble 10523)`. **Encabezado:** en "Muestra de contenido multimedia" elige **Imagen** y sube una ficha de ejemplo (JPG o PNG, datos inventados). El panel envía la ficha del funcionario asignado (Promoción › Fichas de funcionarios) o, si no tiene, el banner de los correos.

```text
Hola *{{1}}.*

Registramos tu solicitud *#{{2}}* de *{{3}}*.

*Te atenderá:* {{4}}.

Desde el botón puedes ver tu solicitud y escribirnos.

*Construimos confianza, protegemos su patrimonio.*
```

Ejemplos: `{{1}}` Luis Prada · `{{2}}` 1686 · `{{3}}` Arriendo · `{{4}}` Jocabeth Cantillo · Correo: jocabeth@ejemplo.com · Celular: 3001234567

Botón `Ver y responder`. URL del sitio web `https://sucasainmobiliaria.com.co/dashboard-comercial/public/tarea.php?t=`. URL de muestra `https://sucasainmobiliaria.com.co/dashboard-comercial/public/tarea.php?t=1686.1893456000.ejemplo`.

## 3. `scm_tarea_comercial_respuesta_cliente_v1`

Para el funcionario asignado y las copias internas, cuando el cliente escribe desde su enlace.

```text
Hola *{{1}}.*

El cliente *{{2}}* escribió en la tarea comercial *#{{3}}*.

*Mensaje:* {{4}}.

Responde desde el panel.

*Construimos confianza, protegemos su patrimonio.*
```

Ejemplos: `{{1}}` Jocabeth Cantillo · `{{2}}` Luis Prada · `{{3}}` 1686 · `{{4}}` Quisiera visitar el apartamento el sábado

Botón `Ver tarea`. URL del sitio web `https://sucasainmobiliaria.com.co/dashboard-comercial/public/index.php?tab=abiertos&ticket_id=`. URL de muestra `https://sucasainmobiliaria.com.co/dashboard-comercial/public/index.php?tab=abiertos&ticket_id=1686`.

## 4. `scm_tarea_comercial_respuesta_funcionario_v1`

Para el cliente, cuando el funcionario le responde marcando avisar al cliente. Firma quien respondió.

```text
Hola *{{1}}.*

Tienes una respuesta en tu solicitud *#{{2}}*.

Desde el botón puedes leerla y escribirnos.

Atentamente,
{{3}}.

*Construimos confianza, protegemos su patrimonio.*
```

Ejemplos: `{{1}}` Luis Prada · `{{2}}` 1686 · `{{3}}` Jocabeth Cantillo · Correo: jocabeth@ejemplo.com · Celular: 3001234567

Botón `Ver y responder`. URL del sitio web `https://sucasainmobiliaria.com.co/dashboard-comercial/public/tarea.php?t=`. URL de muestra `https://sucasainmobiliaria.com.co/dashboard-comercial/public/tarea.php?t=1686.1893456000.ejemplo`.

## 5. `scm_cotizacion_inmuebles_v1`

Para el cliente, al crear una cotización de inmuebles desde el popup de la tarea. **Encabezado:** en "Muestra de contenido multimedia" elige **Documento** y sube un PDF de ejemplo (por ejemplo, una cotización descargada del panel). El panel envía el PDF de la cotización (`cotizacion-inmuebles-<número>.pdf`), que Meta descarga de `cotizacion.php?c=…&formato=pdf`.

```text
Hola *{{1}}.*

Te compartimos la cotización de inmuebles *#{{2}}* con *{{3}}*, de acuerdo con lo que buscas.

*Su consultor/a asignado es* {{4}}.

Desde el botón puedes ver las fotos, los precios y la comparación de los inmuebles.

*Construimos confianza, protegemos su patrimonio.*
```

Ejemplos: `{{1}}` Luis Prada · `{{2}}` 5868 · `{{3}}` 3 inmuebles en arriendo · `{{4}}` Jocabeth Cantillo · Correo: jocabeth@ejemplo.com · Celular: 3001234567

Botón `Ver cotización`. URL del sitio web `https://sucasainmobiliaria.com.co/dashboard-comercial/public/cotizacion.php?c=`. URL de muestra `https://sucasainmobiliaria.com.co/dashboard-comercial/public/cotizacion.php?c=5868.1893456000.ejemplo`.

## 9. `scm_tarea_comercial_postergada_v1`

Para el cliente, cuando el consultor/a posterga la tarea (botón **Postergar** del popup). Lleva el motivo y la fecha en que se retoma. Código: `CommercialCallsNotifier::TEMPLATE_POSTPONED`.

```text
Hola *{{1}}.*

Tu tarea *#{{2}}* queda en pausa hasta el *{{3}}*.

*Motivo:* {{4}}.

*Su consultor/a asignado es* {{5}}.

Desde el botón puedes ver tu tarea y escribirnos.

*Construimos confianza, protegemos su patrimonio.*
```

Ejemplos: `{{1}}` Luis Prada · `{{2}}` 1686 · `{{3}}` lunes 7 de diciembre de 2026 · `{{4}}` El propietario viaja y retomamos las visitas a su regreso · `{{5}}` Jocabeth Cantillo · Correo: jocabeth@ejemplo.com · Celular: 3001234567

Botón `Ver tarea`. URL del sitio web `https://sucasainmobiliaria.com.co/dashboard-comercial/public/tarea.php?t=`. URL de muestra `https://sucasainmobiliaria.com.co/dashboard-comercial/public/tarea.php?t=1686.1893456000.ejemplo`.

## 10. `scm_tarea_comercial_cerrada_v1`

Para el cliente, cuando el consultor/a cierra la tarea (botón **Cerrar Tarea** del popup), salvo los cierres **Duplicado** y **Trasladado** (la gestión sigue en otra tarea). Lleva la observación del cierre. Con la tarea cerrada el cliente ya no puede escribir en su enlace: solo verla. Código: `CommercialCallsNotifier::TEMPLATE_CLOSED`.

```text
Hola *{{1}}.*

Cerramos tu tarea *#{{2}}*.

*Observación:* {{3}}.

*Su consultor/a asignado es* {{4}}.

Gracias por confiar en nosotros. Desde el botón puedes ver el detalle de tu tarea.

*Construimos confianza, protegemos su patrimonio.*
```

Ejemplos: `{{1}}` Luis Prada · `{{2}}` 1686 · `{{3}}` Firmaste el contrato de arriendo del apartamento 502 · `{{4}}` Jocabeth Cantillo · Correo: jocabeth@ejemplo.com · Celular: 3001234567

Botón `Ver tarea`. URL del sitio web `https://sucasainmobiliaria.com.co/dashboard-comercial/public/tarea.php?t=`. URL de muestra `https://sucasainmobiliaria.com.co/dashboard-comercial/public/tarea.php?t=1686.1893456000.ejemplo`.

## Plantillas compartidas con el panel inmobiliario (6, 7 y 8)

Las usan este panel y `control-servicios-inmobiliarios`, con los mismos nombres de siempre. Se **editan** en Meta (texto y botón); no se crean nuevas. Tenían fija en el botón la página de tickets anterior (`https://sucasainmobiliaria.com.co/ticket/?id_ticket={{1}}`) y el texto decía "solicitud" o "ticket".

Para que el mismo botón sirva en los dos paneles, la **URL del sitio web** queda solo con el dominio, `https://sucasainmobiliaria.com.co/`, y cada panel completa el resto (`SharedButtonUrl::suffix`, igual en los dos repositorios): este, `dashboard-comercial/public/...`, y el inmobiliario, `control-servicios-inmobiliarios/public/?scm_case=...`. La referencia va en una sola variable con su nombre (`Tarea #11459` aquí, `Caso #10245` en el inmobiliario), así el texto sirve para los dos. Cada plantilla conserva su cantidad de variables. Botón **Ver detalle** en las tres.

Orden del cambio: publicar el código de los dos paneles y editar las tres plantillas en Meta el mismo día. Mientras uno de los dos va adelantado, el botón de esa plantilla abre una dirección incompleta (el texto llega bien).

## 6. `pqr_trasladada_funcionario_v1` (editar)

Para el nuevo responsable cuando se reasigna (traslada) una tarea o un caso. Código: `CommercialCallsNotifier::TEMPLATE_TRANSFERRED`.

```text
Hola *{{1}}*.

Se trasladó a tu gestión: *{{2}}*.

*Tipo:* {{3}}.
*Responsable anterior:* {{4}}.

Consulta los detalles en el botón.

*Construimos confianza, protegemos su patrimonio.*
```

Ejemplos: `{{1}}` Royner Guardo · `{{2}}` Tarea #11459 · `{{3}}` Venta de inmueble · `{{4}}` Daniela Hernandez Buelvas

Botón `Ver detalle`. URL del sitio web `https://sucasainmobiliaria.com.co/`. URL de muestra `https://sucasainmobiliaria.com.co/dashboard-comercial/public/index.php?tab=abiertos&ticket_id=11459.1893456000.101.ejemplo`.

## 7. `scm_cita_funcionario_v1` (editar)

Para el funcionario cuando se le agenda una cita. Código: `CommercialCalendarNotifier::TEMPLATE_EMPLOYEE`.

```text
Hola *{{1}}*, se te agendó una cita de *{{2}}*.

*Referencia:* {{3}}.
*Fecha:* {{4}}.
*Hora:* {{5}}.
*Dirección:* {{6}}.

Consulta los detalles en el botón.

*Construimos confianza, protegemos su patrimonio.*
```

Ejemplos: `{{1}}` Alvaro Florez De Oro · `{{2}}` Visita · `{{3}}` Tarea #11459 · `{{4}}` 25/11/2026 · `{{5}}` 10:00 a. m. a 11:00 a. m. · `{{6}}` Urb Simón Bolívar Mz 20 lote 7

Botón `Ver detalle`. URL del sitio web `https://sucasainmobiliaria.com.co/`. URL de muestra `https://sucasainmobiliaria.com.co/dashboard-comercial/public/index.php?tab=abiertos&ticket_id=11459.1893456000.101.ejemplo`.

## 8. `scm_cita_solicitante_v1` (editar)

Para el cliente cuando se le agenda una cita. Código: `CommercialCalendarNotifier::TEMPLATE_REQUESTER`. Como la comparte el panel inmobiliario, quien agendó va como "Agendada por" y no como consultor/a.

```text
Hola *{{1}}*, se te agendó una cita de *{{2}}*.

*Referencia:* {{3}}.
*Fecha:* {{4}}.
*Hora:* {{5}}.
*Lugar:* {{6}}.
*Agendada por:* {{7}}.

Si no puedes asistir, comunícate con quien agendó la cita. Consulta los detalles en el botón.

*Construimos confianza, protegemos su patrimonio.*
```

Ejemplos: `{{1}}` Roy Guardo · `{{2}}` Visita · `{{3}}` Tarea #11459 · `{{4}}` 25/11/2026 · `{{5}}` 10:00 a. m. a 11:00 a. m. · `{{6}}` Urb Simón Bolívar Mz 20 lote 7 · `{{7}}` Alvaro Florez De Oro - 3001234567

Botón `Ver detalle`. URL del sitio web `https://sucasainmobiliaria.com.co/`. URL de muestra `https://sucasainmobiliaria.com.co/dashboard-comercial/public/tarea.php?t=11459.1893456000.ejemplo`.

## Si Meta exige otro nombre

Cámbialo en la constante `TEMPLATE_*` de `src/Commercial/Calls/CommercialCallsNotifier.php` (o `TEMPLATE` de `src/Commercial/Quotes/CommercialQuotesNotifier.php` para la cotización, y `TEMPLATE_EMPLOYEE`/`TEMPLATE_REQUESTER` de `src/Commercial/CommercialCalendarNotifier.php` para las citas). No borres una plantilla para volver a crearla: Meta no deja reusar ese nombre por varias semanas.
