"""CPU-only branded photo reels. No remote URLs, AI calls or shell commands."""
import os
import subprocess
import time
from pathlib import Path

from PIL import Image, ImageDraw, ImageFilter, ImageFont, ImageOps

NAVY = "#102d55"
GOLD = "#f3b43c"


def font(size, bold=False):
    names = [os.getenv("VIDEO_FONT_BOLD" if bold else "VIDEO_FONT", ""),
             "/usr/share/fonts/truetype/dejavu/DejaVuSans" + ("-Bold" if bold else "") + ".ttf",
             "C:/Windows/Fonts/arial" + ("bd" if bold else "") + ".ttf"]
    for name in names:
        if name and Path(name).is_file():
            return ImageFont.truetype(name, size)
    return ImageFont.load_default(size=size)


def fitted(draw, text, size, width, bold=False):
    while size > 14:
        face = font(size, bold)
        if draw.textlength(text, font=face) <= width:
            return face
        size -= 1
    return font(size, bold)


def photo_frame(path, size):
    with Image.open(path) as source:
        photo = ImageOps.exif_transpose(source).convert("RGB")
        backdrop = ImageOps.fit(photo, size).filter(ImageFilter.GaussianBlur(28))
        backdrop = Image.blend(backdrop, Image.new("RGB", size, NAVY), .4)
        # Retain the whole room; fill unused vertical/horizontal space with a soft backdrop.
        foreground = ImageOps.contain(photo, size, Image.Resampling.LANCZOS)
        backdrop.paste(foreground, ((size[0] - foreground.width) // 2, (size[1] - foreground.height) // 2))
        return backdrop


def artwork(size, options, kind, index=0, count=1):
    w, h = size
    scale = w / 720
    image = Image.new("RGBA", size)
    draw = ImageDraw.Draw(image)
    margin = round(w * .07)
    if kind in ("intro", "outro"):
        draw.rectangle((0, 0, w, h), fill=(8, 28, 55, 145))
    draw.rounded_rectangle((margin, round(h * .055), w - margin, round(h * .145)), radius=round(15 * scale), fill=(255, 255, 255, 242))
    logo = os.getenv("VIDEO_LOGO_PATH", "")
    if logo and Path(logo).is_file():
        with Image.open(logo) as source:
            mark = ImageOps.contain(source.convert("RGBA"), (w - 2 * margin - 28, round(h * .07)))
            image.alpha_composite(mark, ((w - mark.width) // 2, round(h * .1 - mark.height / 2)))
    else:
        draw.text((w / 2, h * .083), "SuCasa", font=font(round(33 * scale), True), anchor="mt", fill=NAVY)
        draw.text((w / 2, h * .117), "I N M O B I L I A R I A", font=font(round(11 * scale)), anchor="mt", fill=NAVY)
    if kind == "intro":
        draw.rounded_rectangle((margin, h * .37, w - margin, h * .68), radius=round(24 * scale), fill=(16, 45, 85, 238))
        draw.rectangle((margin, h * .37, margin + 8 * scale, h * .68), fill=GOLD)
        y = h * .415
        for text, size_px, color in [(options["title"], 42, "white"), (options["operation"].upper(), 24, GOLD), (options["location"], 23, "white"), (options["price"], 30, "white")]:
            if text:
                draw.text((w / 2, y), text, font=fitted(draw, text, round(size_px * scale), w - 2 * margin - 42, True), fill=color, anchor="mt")
            y += h * .056
        draw.text((w / 2, h * .84), "Conoce tu próximo espacio", font=font(round(22 * scale)), fill="white", anchor="mt")
    elif kind == "outro":
        draw.rectangle((0, h * .47, w, h), fill=(16, 45, 85, 240))
        draw.polygon([(w * .78, h), (w, h * .66), (w, h)], fill=GOLD)
        for text, y, size_px, color in [("¿TE INTERESA", .54, 32, "white"), ("ESTE INMUEBLE?", .59, 36, GOLD), ("Agenda tu visita", .68, 25, "white"), (options["contact"], .75, 29, "white"), ("sucasainmobiliaria.com.co", .87, 18, "white")]:
            if text:
                draw.text((w / 2, h * y), text, font=fitted(draw, text, round(size_px * scale), w - 2 * margin, True), fill=color, anchor="mt")
    else:
        draw.rounded_rectangle((margin, h * .81, w - margin, h * .94), radius=round(16 * scale), fill=(16, 45, 85, 228))
        label = options["captions"][index] or options["title"]
        draw.text((margin + 20 * scale, h * .835), label, font=fitted(draw, label, round(25 * scale), w - 2 * margin - 40, True), fill="white")
        detail = options["location"] or options["operation"]
        draw.text((margin + 20 * scale, h * .877), detail, font=fitted(draw, detail, round(17 * scale), w - 2 * margin - 40), fill="white")
        draw.rectangle((margin, h * .935, margin + (w - 2 * margin) * (index + 1) / count, h * .94), fill=GOLD)
    return image


def run_ffmpeg(args, folder, checkpoint, timeout=240):
    executable = os.getenv("FFMPEG_BINARY", "ffmpeg")
    with (folder / "ffmpeg.log").open("ab") as log:
        process = subprocess.Popen([executable, "-hide_banner", "-loglevel", "error", "-nostdin", "-y", *args], stdout=subprocess.DEVNULL, stderr=log)
        start = time.monotonic()
        try:
            while process.poll() is None:
                checkpoint()
                if time.monotonic() - start > timeout:
                    raise RuntimeError("El procesamiento tardó demasiado. Prueba con menos fotos o calidad 720p.")
                time.sleep(.25)
            if process.returncode:
                raise RuntimeError("No se pudo procesar el video. Revisa las fotos y el archivo de música.")
        finally:
            if process.poll() is None:
                process.kill()
                process.wait()


def render(folder, options, paths, audio, progress):
    size = (720, 1280) if options["quality"] == "720" else (1080, 1920)
    w, h = size
    duration = options["seconds"]
    scenes = [("intro", 0, 3), *[("photo", i, duration) for i in range(len(paths))], ("outro", len(paths) - 1, 4)]
    clips = []
    for n, (kind, index, seconds) in enumerate(scenes):
        progress(round(n / len(scenes) * 90))
        background = folder / f"background-{n}.jpg"
        overlay = folder / f"overlay-{n}.png"
        photo_frame(paths[index], size).save(background, quality=94)
        artwork(size, options, kind, index, len(paths)).save(overlay)
        frames = seconds * 30
        zoom = f"1.02+0.06*on/{frames}" if n % 2 == 0 else f"1.08-0.06*on/{frames}"
        graph = (f"[0:v]scale={w * 2}:{h * 2},zoompan=z='{zoom}':x='iw/2-iw/zoom/2':y='ih/2-ih/zoom/2':d=1:s={w}x{h}:fps=30[photo];"
                 f"[photo][1:v]overlay=0:0,fade=t=in:st=0:d=0.35,fade=t=out:st={seconds - .35}:d=0.35,format=yuv420p[v]")
        clip = folder / f"scene-{n}.mp4"
        run_ffmpeg(["-filter_complex_threads", "1", "-loop", "1", "-framerate", "30", "-i", str(background), "-i", str(overlay), "-filter_complex", graph, "-map", "[v]", "-t", str(seconds), "-an", "-c:v", "libx264", "-preset", "veryfast", "-crf", "21", "-threads", "2", str(clip)], folder, lambda: progress(round(n / len(scenes) * 90)))
        clips.append(clip)
        background.unlink()
        overlay.unlink()
    listing = folder / "scenes.txt"
    listing.write_text("\n".join(f"file '{clip.name}'" for clip in clips), encoding="utf-8")
    total = 7 + len(paths) * duration
    args = ["-protocol_whitelist", "file,crypto,data", "-f", "concat", "-safe", "1", "-i", str(listing)]
    if audio:
        args += ["-stream_loop", "-1", "-protocol_whitelist", "file,crypto,data", "-i", str(audio), "-map", "0:v:0", "-map", "1:a:0", "-af", f"volume=0.22,afade=t=in:d=1,afade=t=out:st={total - 2}:d=2", "-c:a", "aac", "-b:a", "128k"]
    else:
        args += ["-an"]
    output = folder / "video.mp4"
    run_ffmpeg([*args, "-c:v", "copy", "-t", str(total), "-movflags", "+faststart", str(output)], folder, lambda: progress(95))
    for clip in clips:
        clip.unlink()
    listing.unlink()
    if output.stat().st_size > 100 * 1024 * 1024:
        output.unlink()
        raise RuntimeError("El video supera 100 MB. Reduce la cantidad de fotos o la calidad.")
    return total
