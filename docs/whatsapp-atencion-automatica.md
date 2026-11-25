# Atención automática de los chats de WhatsApp (propuesta pendiente)

> Estado: **idea por desarrollar**. Nada de esto está construido aún. Propuesta del 25/11/2026.

## Objetivo

El número de WhatsApp que recibe los chats de clientes (Finca Raíz, Ciencuadras, MercadoLibre, Proppit, publicidad, botón de la página web y chats directos) consume mucho tiempo del cargo que lo atiende. Se busca:

- responder más rápido, también fuera de horario;
- dejar de contestar a mano lo repetitivo;
- no perder el **medio** de cada cliente, que es lo que alimenta las estadísticas por medio;
- que cada chat termine como **llamada + tarea** asignada a un consultor/a, sin volver a escribir los datos.

## Problemas actuales

1. Preguntas repetidas todo el día: "¿sigue disponible?", "¿cuánto vale?", "¿dónde queda?", "¿puedo verlo?".
2. El medio se pierde si no se registra a mano.
3. Respuesta lenta en horas pico y fuera de horario: el cliente le escribe a otra inmobiliaria.
4. La calificación (arriendo o venta, presupuesto, para cuándo) se hace a mano y luego se vuelve a escribir al registrar la llamada.
5. Sin trazabilidad: si la persona falta, nadie sabe en qué quedó cada chat.

## Lo que ya existe en el panel y se reutiliza

| Pieza | Dónde | Para qué sirve aquí |
|---|---|---|
| Envío de WhatsApp con plantillas de Meta | `shared-notifications` (se encola desde los `*Notifier`) | Respuestas fuera de la ventana de 24 h y seguimientos |
| Llamadas con medio, motivo y presupuesto | `src/Commercial/Calls/` | Cada chat se vuelve una llamada con su medio |
| Llenado inteligente con IA | `CommercialCallsSmartFill` + `MiniMaxChat` | Leer la conversación y completar contacto, motivo, código, presupuesto y descripción |
| Pregunta de presupuesto | `CommercialCallsService::budget` | Con Sí se pide el valor; con No queda la nota "consultar" |
| Búsqueda del inmueble por código | `CommercialCallsService::property` | Responder con datos reales del inventario |
| Catálogo de medios | `src/Commercial/Medios/` (`MedioResolver`) | Normalizar el medio detectado |
| WhatsApp al cliente con la ficha del consultor/a | `CommercialCallsNotifier` (plantilla `scm_tarea_comercial_registrada_v1`) | Presentar al consultor/a asignado |
| Cotizaciones | `src/Commercial/Quotes/` | Ofrecer inmuebles similares |
| Calendario comercial | `public/calendar-api.php` | Agendar visitas desde el chat |
| Generador de UTM | Herramientas › Generador UTM | Enlaces de la web y la pauta con texto prellenado |

**Lo que falta:** recibir los mensajes entrantes. Hoy el panel solo envía; no hay un webhook que reciba los chats de los clientes.

## Plan por fases

### Fase 0 — Sin programar (1–2 días)

- [ ] Mensaje de bienvenida y de ausencia en WhatsApp Business: horario y qué enviar (código del inmueble, nombre, correo).
- [ ] Respuestas rápidas para las 10 preguntas más comunes.
- [ ] Etiquetas por medio: Finca Raíz, Ciencuadras, MercadoLibre, Proppit, Web, Pauta.
- [ ] Enlaces propios (botón de la web, anuncios) con texto prellenado. Ejemplo: `Hola, vi el inmueble 84016 en la web`.

### Fase 1 — Los chats entran al panel (base de todo)

- [ ] Conectar el número a la **API de WhatsApp (Cloud API)** y recibir los mensajes en un webhook del panel (por ejemplo `public/whatsapp-webhook.php`, como `esignatures-webhook.php`).
  - Revisar la **coexistencia** de Meta, que permite usar el mismo número en la app WhatsApp Business y en la API a la vez. Confirmar que el número cumple los requisitos.
  - Si `shared-notifications` ya recibe webhooks de Meta (estados de entrega), apoyarse en eso.
- [ ] Tablas: conversaciones (cliente, número, medio, estado, consultor/a, llamada y tarea ligadas) y mensajes (dirección, texto, adjuntos, fecha).
- [ ] Bandeja **Chats** en Actividades comerciales, con el estilo visual del panel (el de Inmuebles):
  - lista de conversaciones con estado: nuevo, en atención, calificado, convertido a tarea, cerrado;
  - responder desde el panel;
  - permisos: subpestaña en `CommercialAccessPolicy::SUBVIEWS` y acciones sensibles en `ACTIONS`.
- [ ] **Detección del medio:**
  - **Portales:** reglas sobre el texto o enlace que manda cada uno ("vi este inmueble en Fincaraiz…", enlace de MercadoLibre, etc.). Armarlas con mensajes reales.
  - **Anuncios de Meta que abren WhatsApp:** el webhook trae los datos del anuncio de origen.
  - **Web:** el texto prellenado trae el código.
  - **Si no se reconoce:** preguntar "¿Por dónde nos encontró?".
- [ ] Convertir el chat en **llamada**: el llenado inteligente lee la conversación y propone los campos; el funcionario revisa y guarda.

### Fase 2 — Asistente que atiende y califica 24/7

Bot con **guion fijo** (no conversación libre):

1. **Saludo y reconocimiento del inmueble** por código o enlace. Responde con datos reales del inventario: precio, barrio, área, fotos y enlace. Si ya no está disponible, lo dice y ofrece similares.
2. **Calificación:**
   - ¿Arriendo o compra?
   - ¿Cuenta con presupuesto? Con Sí, cuánto; con No, nota "consultar".
   - ¿Para cuándo lo necesita?
   - Nombre y correo.
3. **Crea la llamada y la tarea, y asigna el consultor/a** (por turno, zona o tipo de negocio). El cliente recibe el WhatsApp con la ficha y la línea "Su consultor/a asignado es…".
4. **Paso a una persona** cuando:
   - el cliente escribe "asesor/a" o "consultor/a";
   - pregunta algo que el bot no sabe;
   - la conversación se enreda.

   El consultor/a recibe el resumen y no vuelve a preguntar nada.

Reglas del bot:

- Nunca negocia precio ni promete condiciones.
- Siempre ofrece hablar con una persona.
- Habla de usted y dice "consultor/a", nunca "asesor".
- Nombre corporativo: **SKC SuCasa Inmobiliaria**.

### Fase 3 — Más valor

- [ ] Inmuebles similares del inventario cuando el que pidió no está disponible (mismo criterio que Cotizaciones).
- [ ] Agendar visitas desde el chat con el calendario comercial, con recordatorio automático.
- [ ] Seguimiento: si el cliente no responde en 24 h, enviar una plantilla; si sigue sin contestar, la tarea queda para el consultor/a.
- [ ] Tablero de eficacia: tiempo de primera respuesta, % de chats calificados y conversión por medio (qué portal trae clientes que sí cierran).

## Reglas de Meta que condicionan el diseño

- **Ventana de 24 h:** desde el último mensaje del cliente se puede responder libremente. Fuera de ella, solo con **plantillas aprobadas**.
- **Costo:** responder dentro de la ventana hoy no tiene costo; las plantillas que envía la empresa sí se cobran. Por eso conviene que el bot responda rápido, dentro de la ventana. Verificar precios vigentes antes de empezar.
- **Plantillas nuevas:** se entregan con nombre, cuerpo, ejemplo de cada variable y URL de muestra, siguiendo las reglas de `AGENTS.md`:
  - el contacto del consultor/a va en una sola variable;
  - el cuerpo termina con `*Construimos confianza, protegemos su patrimonio.*`;
  - nunca dos variables seguidas.

## Preguntas por resolver antes de construir

1. **El número:** ¿hoy está en la app WhatsApp Business del celular, en WhatsApp Web o ya en algún proveedor o la API?
2. **Volumen:** ¿cuántos chats nuevos entran al día y en qué horario?
3. **Mensajes reales:** 10 a 20 primeros mensajes, de cada portal si es posible, para armar la detección del medio.
4. **Reparto:** ¿quién atiende hoy y cómo se reparten los clientes a los consultores/as (turno, zona, tipo de negocio)?
5. **`shared-notifications`:** ¿ya recibe webhooks de Meta (estados o respuestas)?

## Recomendación de arranque

1. Hacer la **Fase 0** de inmediato.
2. Construir juntas la **Fase 1** y el núcleo de la **Fase 2**: recibir chats, detectar el medio, responder con datos del inmueble, calificar y crear la llamada y la tarea. Ahí está la mayor parte de la carga que se libera.
3. Sumar la **Fase 3** según lo que muestren las métricas.
