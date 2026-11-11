# Plantillas de WhatsApp de las tareas comerciales

Crea estas cuatro plantillas en **Meta Business Suite → Administrador de WhatsApp → Plantillas de mensajes → Crear plantilla**, en la misma cuenta que usa el panel.

Para todas: categoría **Utilidad**, idioma **Español (Colombia)** (`es_CO`), variables tipo **Número**, sin encabezado salvo la 2, pie de página `SKC SuCasa Inmobiliaria` y un solo botón **Visitar sitio web** con URL **dinámica**. Usa los nombres exactos.

En el botón, el campo **URL del sitio web** lleva todo lo que está antes de `{{1}}` (Meta pone `{{1}}` al final por su cuenta) y el panel completa solo el final: el número de la tarea o el código del enlace. Deja sin marcar **Permite que Meta use el seguimiento de enlaces**: el enlace del cliente es privado y así no pasa por Meta.

Los WhatsApp vienen activos en **Configuración › Llamadas**: en cuanto Meta apruebe las plantillas empiezan a llegar. Mientras tanto Meta rechaza esos envíos y el correo sale igual.

## 1. `scm_tarea_comercial_asignada_v1`

Para el funcionario asignado y las copias internas, al crear la tarea.

```text
Hola {{1}}.
Se registró la tarea comercial #{{2}} para gestión.
Cliente: {{3}}.
Motivo: {{4}}.
Medio: {{5}}.
Responsable: {{6}}.
Consulta los detalles en el panel.
```

Ejemplos: María Pérez · 10945 · Laura Gómez · Arriendo · Referido · Carlos López

Botón `Ver tarea`, URL `https://sucasainmobiliaria.com.co/dashboard-comercial/public/index.php?tab=abiertos&ticket_id={{1}}`. En **URL de muestra** escribe la URL completa: `https://sucasainmobiliaria.com.co/dashboard-comercial/public/index.php?tab=abiertos&ticket_id=10945`.

## 2. `scm_tarea_comercial_registrada_v1`

Para el cliente, al crear la tarea. Es la única con **encabezado de imagen**: la ficha del funcionario asignado (Promoción › Fichas de funcionarios). Si el funcionario no tiene ficha se envía el banner de los correos.

- **Encabezado:** Multimedia → **Imagen**. Como muestra sube cualquier ficha de ejemplo en JPG o PNG (por ejemplo, la tarjeta de presentación de un asesor con datos inventados).

```text
Hola {{1}}.
Registramos tu solicitud #{{2}} de {{3}}.
Te atenderá {{4}}.
Desde el botón puedes ver tu solicitud y escribirnos.
```

Ejemplos: Laura Gómez · 10945 · Arriendo · Carlos López

Botón `Ver y responder`, URL `https://sucasainmobiliaria.com.co/dashboard-comercial/public/tarea.php?t={{1}}`. En **URL de muestra** escribe la URL completa: `https://sucasainmobiliaria.com.co/dashboard-comercial/public/tarea.php?t=10945.1893456000.ejemplo`.

## 3. `scm_tarea_comercial_respuesta_cliente_v1`

Para el funcionario asignado y las copias internas, cuando el cliente escribe.

```text
Hola {{1}}.
El cliente {{2}} escribió en la tarea comercial #{{3}}.
Mensaje: {{4}}.
Responde desde el panel.
```

Ejemplos: Carlos López · Laura Gómez · 10945 · Quisiera visitar el apartamento el sábado

Botón `Ver tarea`, la misma URL de la plantilla 1. En **URL de muestra** escribe la URL completa: `https://sucasainmobiliaria.com.co/dashboard-comercial/public/index.php?tab=abiertos&ticket_id=10945`.

## 4. `scm_tarea_comercial_respuesta_funcionario_v1`

Para el cliente, cuando el funcionario responde marcando avisar al cliente.

```text
Hola {{1}}.
{{2}} respondió tu solicitud #{{3}}.
Desde el botón puedes leer la respuesta y escribirnos.
```

Ejemplos: Laura Gómez · Carlos López · 10945

Botón `Ver y responder`, la misma URL de la plantilla 2. En **URL de muestra** escribe la URL completa: `https://sucasainmobiliaria.com.co/dashboard-comercial/public/tarea.php?t=10945.1893456000.ejemplo`.

## Activación

No hay que encender nada. Si Meta exige otro nombre para alguna plantilla, escríbelo en **Configuración › Llamadas**.
