# Generador de videos de inmuebles

Servicio propio de Python, Pillow y FFmpeg para la opción **Crear video con fotos**
de **Ingresar inmueble → Fotos y video** y **Multimedia del inmueble**.
Genera un montaje de fotografías; no convierte una imagen en un recorrido 3D.
No necesita API de pago ni GPU. El costo es el alojamiento y mantenimiento del VPS.

Incluye selección de 2–12 fotos, orden, títulos por escena, portada, ubicación,
precio, contacto, acercamientos, fundidos y cierre SuCasa. Exporta H.264/yuv420p
en MP4 vertical 720×1280 o 1080×1920, 30 fps, con música opcional AAC. Conserva
la foto completa sobre un fondo desenfocado y hace un acercamiento suave del conjunto.
La duración es `7 + fotos × segundos`, con 2–6 segundos por foto (máximo 79 segundos).
La música se repite con volumen reducido y entrada/salida suaves; no se incluye
una pista comercial ni se extrae el audio del video de referencia.

## Instalar en un VPS Linux con Docker

Como punto de partida para probar uso ocasional: 2–4 vCPU y 4 GB de RAM, con
espacio libre para medios temporales. Medir 1080p y concurrencia antes de elegir
el tamaño definitivo. Compose limita este contenedor a 2 CPU y 2 GB de memoria;
solo se renderiza un video a la vez y hay hasta cuatro esperando. No ejecutar
varios workers de Uvicorn sobre el mismo volumen.

Desde esta carpeta:

```sh
# Genera un secreto; guarda su valor en el gestor de secretos o archivo de entorno del servidor.
python3 -c 'import secrets; print(secrets.token_urlsafe(48))'
export VIDEO_SERVICE_TOKEN='EL_SECRETO_GENERADO'
docker compose up -d --build
```

En el `.env` del dashboard, si PHP está en ese mismo VPS:

```dotenv
PROPERTY_VIDEO_URL=http://127.0.0.1:8090
PROPERTY_VIDEO_TOKEN=EL_MISMO_SECRETO_GENERADO
```

Si PHP y Python están en servidores distintos, usar un dominio HTTPS del VPS
como `PROPERTY_VIDEO_URL=https://video.example.com`; configurar un proxy inverso
hacia `127.0.0.1:8090`. El puerto 8090 de Compose solo escucha en localhost.
El token debe viajar en `Authorization: Bearer ...` y jamás se configura en JavaScript.
Ejemplo de ubicación Nginx dentro de un servidor con certificado TLS:

```nginx
location / {
    client_max_body_size 32m;
    proxy_pass http://127.0.0.1:8090;
    proxy_set_header Authorization $http_authorization;
    proxy_read_timeout 60s;
}
```

En PHP se necesita cURL, `upload_max_filesize >= 16M`, `post_max_size >= 32M` y
`max_file_uploads >= 13`. Configurar también el límite del proxy del dashboard.
Para leer las fotos existentes, `WORDPRESS_UPLOAD_PATH` debe apuntar al mismo
directorio donde están los archivos de WordPress. La app no descarga URLs de
fotografías arbitrarias: resuelve solo adjuntos permitidos para esa tarea/inmueble,
incluyendo códigos adelantados propios. Las fotos nuevas se mandan directamente
sin obligar a guardar primero el inmueble.

Las peticiones de creación y descarga tienen un tiempo máximo de 45 segundos;
el render sucede en segundo plano y la interfaz consulta su progreso cada tres
segundos. Cerrar el creador no cancela el trabajo: el recibo se conserva en
`sessionStorage` para recuperarlo en la misma pestaña. Se puede cancelar desde
la interfaz. Al generar otro video se reemplaza el recibo anterior de esa tarea.

Los originales se eliminan al finalizar cada trabajo. Los MP4 duran 24 horas,
con limpieza periódica cada cinco minutos y al iniciar/crear trabajos. Si el
servicio se reinicia durante un render, el trabajo queda fallido y se puede
generar de nuevo. El volumen de Compose conserva los trabajos terminados tras
reiniciar. Supervisar disco y logs como en cualquier servicio de medios.

La descarga pasa por PHP con sesión, CSRF, permiso de tarea y recibo HMAC con
caducidad; no expone la URL privada ni el secreto del servicio. El archivo se
previsualiza y descarga como MP4. **No se publica automáticamente en redes ni
reemplaza el enlace de YouTube** del formulario.

## Logo y tipografía

Sin configuración adicional se usa una cabecera tipográfica SuCasa en azul y
dorado. Para el logo gráfico oficial, montar un PNG/WebP local en el contenedor
y definir `VIDEO_LOGO_PATH=/app/branding/logo.png`. No se descarga desde URLs.
Se pueden montar fuentes y definir `VIDEO_FONT` y `VIDEO_FONT_BOLD`; el contenedor
incluye DejaVu Sans. El diseño es propio y está inspirado en la estructura del
video de referencia, no reproduce sus piezas gráficas exactas.

## Ejecución sin Docker y validación

Python 3.12, FFmpeg con libx264 y una fuente TrueType:

```sh
python3 -m venv .venv
. .venv/bin/activate
pip install -r requirements.txt
export VIDEO_SERVICE_TOKEN='UN_SECRETO_DE_AL_MENOS_32_CARACTERES'
export VIDEO_DATA_DIR='/ruta/privada/videos'
uvicorn app:app --host 127.0.0.1 --port 8090 --workers 1
```

`FFMPEG_BINARY` permite especificar el ejecutable si no está en PATH. No se usan
comandos de shell ni se interpolan textos de usuarios en filtros/argumentos de FFmpeg:
los textos se dibujan con Pillow, y los nombres de archivos los genera el servicio.

Pruebas desde la raíz del repositorio, con el entorno Python activado:

```sh
pip install httpx
python -m unittest discover -s services/property-video -p test_video.py -v
```

El test renderiza un MP4 real, verifica resolución, duración, escenas y audio,
además de autenticación, archivos falsos y cancelación. El navegador se comprueba
con `tools/check-commercial-property-video.cjs` contra el fixture PHP local:
requiere dos fotos y portada en `output/video-photos/foto-{0,1,2}.jpg`, Playwright
y el servicio local. El fixture recibe `SCM_TEST_VIDEO_URL` y `SCM_TEST_VIDEO_TOKEN`
del proceso PHP; nunca usa la base de datos productiva.

Validación de esta implementación: pruebas PHP de ingreso/multimedia/editor/video
(37 pruebas, 123 aserciones), tres pruebas Python con FFmpeg real, exportación
720p y 1080p, y recorridos de navegador del ingreso y del creador de video.
El lint PHP y el análisis estático de los dos servicios de medios/video pasan.
La suite PHP global conserva un fallo previo en `CommercialStatusCatalogTest`
(espera 23 estados y hay 26). PHPStan global también informa errores fuera de
estos servicios, incluyendo incompatibilidades del módulo compartido de
notificaciones; no se modificó ese módulo como parte de esta función.

Referencias: [filtros FFmpeg](https://ffmpeg.org/ffmpeg-filters.html),
[concat y MP4](https://ffmpeg.org/ffmpeg-formats.html),
[archivos en FastAPI](https://fastapi.tiangolo.com/tutorial/request-files/).
