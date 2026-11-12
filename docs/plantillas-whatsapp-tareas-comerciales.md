# Plantillas de WhatsApp de las tareas comerciales

Crea estas cuatro plantillas en **Meta Business Suite → Administrador de WhatsApp → Plantillas de mensajes → Crear plantilla**, en la misma cuenta que usa el panel.

Para todas: categoría **Utilidad**, idioma **Spanish (COL)** (`es_CO`), tipo de variable **Número**, pie de página `SKC SuCasa Inmobiliaria` y un botón **Ir al sitio web** con URL **Dinámica**. Usa los nombres exactos. Solo la 2 lleva encabezado (imagen).

Cada dato es una sola variable: el contacto de un funcionario (nombre, correo y celular) lo arma el sistema en una línea, como en el panel inmobiliario. Meta no acepta cuerpos que empiecen o terminen con una variable ni dos variables seguidas: por eso el cuerpo siempre cierra con la línea `*Construimos confianza, protegemos su patrimonio.*` (la 1 ya estaba creada y queda como está).

En el botón, **URL del sitio web** lleva todo lo que está antes de `{{1}}` (Meta pone `{{1}}` al final) y el panel completa solo el final. En **URL de muestra** va la URL completa. Deja sin marcar **Permite que Meta use el seguimiento de enlaces**.

Los WhatsApp vienen activos en **Configuración › Llamadas**: llegan en cuanto Meta apruebe cada plantilla.

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

Para el cliente, al crear la tarea (desde Llamadas o desde Precaptación). **Encabezado:** en "Muestra de contenido multimedia" elige **Imagen** y sube una ficha de ejemplo (JPG o PNG, datos inventados). El panel envía la ficha del funcionario asignado (Promoción › Fichas de funcionarios) o, si no tiene, el banner de los correos.

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

## Si Meta exige otro nombre

Escríbelo en **Configuración › Llamadas**. No borres una plantilla para volver a crearla: Meta no deja reusar ese nombre por varias semanas.
