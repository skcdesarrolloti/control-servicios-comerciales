# Contratos de mandato: revisión del propietario antes de la firma

Primera fase de la migración de los mandatos (`formularios/` de WordPress) al panel comercial.

## Qué hay

- **Actividades comerciales › Registros › Mandatos** (y **Mi portafolio › Mis mandatos**): todos los mandatos de
  `wp_jet_cct_contrato_mandato`, con el estado del mandato (No enviado / Enviado a firma / Firmado) y el de la revisión
  del propietario. **Ver** abre el mandato en un popup.
- **Página del mandato** `public/mandato.php`:
  - `?m=<enlace firmado>`: la ve el **propietario** sin iniciar sesión. Lee el contrato por secciones (propietarios,
    inmueble, copropiedad, servicios, canon y comisión, garantías, condiciones), deja **observaciones** en la sección que
    deba cambiar (o un comentario general) y, cuando todo está correcto, **aprueba** el contrato. El enlace vence a los
    120 días.
  - `?id=<mandato>`: la ve el **equipo** con la sesión del panel. Muestra lo mismo, más el enlace del propietario
    (Copiar), **Enviar al propietario por correo y WhatsApp**, la respuesta a cada observación (y marcarla resuelta), y
    **Editar mandato** / **Enviar a firma**, que por ahora abren los formularios de WordPress.
- La aprobación guarda una huella del contenido: si el mandato cambia después, queda en **"Cambió después de aprobar"** y
  hay que enviárselo otra vez. No se puede aprobar con observaciones pendientes.

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
- Acciones `mandato_enviar_revision` y `mandato_responder_observaciones` (grupo **Contratos de mandato**).

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

## Lo que sigue (próximas fases)

1. Crear y editar el mandato en el panel (formulario por pasos de `crear-mandato` y `edit-mandato`, con sus cálculos).
2. Enviar a firma desde el panel (`comun/mandato_firma.php` y `esignatures.php`) y el webhook de eSignatures, con el
   token en el `.env`.
3. Apagar los formularios de WordPress.
