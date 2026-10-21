"""Private authenticated API. Run one uvicorn process; the worker serializes renders."""
import hmac
import json
import logging
import os
import re
import shutil
import threading
import time
import uuid
from contextlib import asynccontextmanager
from pathlib import Path
from queue import Empty, Full, Queue

from fastapi import Depends, FastAPI, File, Form, Header, HTTPException, UploadFile
from fastapi.responses import FileResponse
from PIL import Image
from pydantic import BaseModel, ConfigDict, Field

from renderer import render

ROOT = Path(os.getenv("VIDEO_DATA_DIR", "/data")).resolve()
TOKEN = os.getenv("VIDEO_SERVICE_TOKEN", "")
JOBS = Queue(maxsize=4)
LOCK = threading.RLock()
STOP = threading.Event()
Image.MAX_IMAGE_PIXELS = 20_000_000


class Options(BaseModel):
    model_config = ConfigDict(extra="forbid")
    title: str = Field(default="Tu próximo inmueble", min_length=1, max_length=70)
    operation: str = Field(default="En venta", max_length=30)
    location: str = Field(default="", max_length=80)
    price: str = Field(default="", max_length=50)
    contact: str = Field(default="", max_length=60)
    quality: str = Field(default="720", pattern="^(720|1080)$")
    seconds: int = Field(default=4, ge=2, le=6)
    captions: list[str] = Field(min_length=2, max_length=12)


def authorize(authorization: str = Header(default="")):
    if len(TOKEN) < 32 or not hmac.compare_digest(authorization, "Bearer " + TOKEN):
        raise HTTPException(401, "Acceso no autorizado.")


def folder_for(job):
    if not re.fullmatch(r"[a-f0-9]{32}", job):
        raise HTTPException(404, "Video no encontrado.")
    folder = ROOT / job
    if not (folder / "state.json").is_file():
        raise HTTPException(404, "El video no existe o ya expiró.")
    return folder


def read_state(folder):
    with LOCK:
        return json.loads((folder / "state.json").read_text(encoding="utf-8"))


def update(folder, **values):
    with LOCK:
        state = read_state(folder) if (folder / "state.json").is_file() else {}
        state.update(values)
        temporary = folder / "state.tmp"
        temporary.write_text(json.dumps(state, ensure_ascii=False), encoding="utf-8")
        temporary.replace(folder / "state.json")
        return state


def expire():
    with LOCK:
        for folder in ROOT.iterdir():
            if folder.is_dir() and re.fullmatch(r"[a-f0-9]{32}", folder.name):
                state = read_state(folder) if (folder / "state.json").is_file() else {}
                if state.get("status") not in ("queued", "rendering") and time.time() - folder.stat().st_mtime > 86400:
                    shutil.rmtree(folder)


def worker():
    last_expiry = 0
    while not STOP.is_set():
        if time.time() - last_expiry > 300:
            expire()
            last_expiry = time.time()
        try:
            folder = JOBS.get(timeout=1)
        except Empty:
            continue
        try:
            state = read_state(folder)
            if state["status"] == "cancelled":
                continue
            update(folder, status="rendering", progress=1)

            def progress(value):
                if STOP.is_set() or read_state(folder)["status"] == "cancelled":
                    raise InterruptedError()
                update(folder, progress=value)

            options = json.loads((folder / "options.json").read_text(encoding="utf-8"))
            paths = [folder / f"photo-{i}.jpg" for i in range(len(options["captions"]))]
            audio = folder / "audio" if (folder / "audio").is_file() else None
            duration = render(folder, options, paths, audio, progress)
            with LOCK:
                if read_state(folder)["status"] == "cancelled":
                    raise InterruptedError()
                update(folder, status="ready", progress=100, duration=duration)
        except InterruptedError:
            update(folder, status="cancelled", message="Generación cancelada.")
        except Exception:
            logging.exception("Rendering failed: %s", folder.name)
            update(folder, status="failed", message="No se pudo crear el video. Prueba con menos fotos, calidad 720p u otra música.")
        finally:
            # Inputs and intermediate artifacts are never public or retained after rendering.
            for path in folder.iterdir():
                if path.name not in ("state.json", "video.mp4"):
                    path.unlink(missing_ok=True)
            if read_state(folder)["status"] != "ready":
                (folder / "video.mp4").unlink(missing_ok=True)
            JOBS.task_done()


@asynccontextmanager
async def lifespan(_app):
    if len(TOKEN) < 32:
        raise RuntimeError("VIDEO_SERVICE_TOKEN debe contener al menos 32 caracteres.")
    ROOT.mkdir(parents=True, exist_ok=True)
    # A restart interrupts a render, but never leaves the UI polling forever.
    for statefile in ROOT.glob("*/state.json"):
        if read_state(statefile.parent).get("status") in ("queued", "rendering"):
            update(statefile.parent, status="failed", message="El servicio se reinició. Vuelve a generar el video.")
    expire()
    STOP.clear()
    thread = threading.Thread(target=worker, daemon=True)
    thread.start()
    yield
    STOP.set()
    thread.join(timeout=5)


app = FastAPI(lifespan=lifespan, docs_url=None, redoc_url=None, openapi_url=None, dependencies=[Depends(authorize)])


@app.get("/health")
def health():
    return {"available": True}


async def copy_upload(upload, destination, limit):
    size = 0
    with destination.open("wb") as output:
        while chunk := await upload.read(65536):
            size += len(chunk)
            if size > limit:
                raise HTTPException(422, "El archivo supera el tamaño permitido.")
            output.write(chunk)
    if not size:
        raise HTTPException(422, "Archivo vacío.")


@app.post("/jobs", status_code=202)
async def create(manifest: str = Form(max_length=8000), images: list[UploadFile] = File(), audio: UploadFile | None = File(default=None)):
    try:
        options = Options.model_validate_json(manifest)
    except ValueError:
        raise HTTPException(422, "Opciones del video inválidas.")
    if len(images) != len(options.captions) or any(len(text) > 80 or "\n" in text or "\r" in text for text in options.captions):
        raise HTTPException(422, "Selecciona entre 2 y 12 fotos con títulos de hasta 80 caracteres.")
    expire()
    folder = ROOT / uuid.uuid4().hex
    folder.mkdir(mode=0o700)
    try:
        for i, upload in enumerate(images):
            temporary = folder / "upload"
            await copy_upload(upload, temporary, 1048576)
            try:
                with Image.open(temporary) as image:
                    if image.format not in ("JPEG", "PNG") or not (500 <= image.width <= 4000 and 500 <= image.height <= 4000) or image.width * image.height > 12_000_000:
                        raise ValueError()
                    image.verify()
                from PIL import ImageOps
                with Image.open(temporary) as image:
                    ImageOps.exif_transpose(image).convert("RGB").save(folder / f"photo-{i}.jpg", quality=95)
            except (OSError, ValueError, Image.DecompressionBombError):
                raise HTTPException(422, "Una fotografía no es un JPG/PNG válido.")
            temporary.unlink()
        if audio:
            await copy_upload(audio, folder / "audio", 12 * 1024 * 1024)
        (folder / "options.json").write_text(options.model_dump_json(), encoding="utf-8")
        state = update(folder, job_id=folder.name, status="queued", progress=0, created_at=int(time.time()))
        try:
            JOBS.put_nowait(folder)
        except Full:
            raise HTTPException(429, "Hay varios videos en espera. Inténtalo en unos minutos.")
        return state
    except BaseException:
        shutil.rmtree(folder)
        raise


@app.get("/jobs/{job}")
def status(job: str):
    return read_state(folder_for(job))


@app.delete("/jobs/{job}")
def cancel(job: str):
    folder = folder_for(job)
    with LOCK:
        if read_state(folder)["status"] in ("queued", "rendering"):
            update(folder, status="cancelled", message="Generación cancelada.")
    return read_state(folder)


@app.get("/jobs/{job}/file")
def download(job: str):
    folder = folder_for(job)
    if read_state(folder)["status"] != "ready":
        raise HTTPException(409, "El video aún no está listo.")
    return FileResponse(folder / "video.mp4", media_type="video/mp4", filename="inmueble.mp4")
