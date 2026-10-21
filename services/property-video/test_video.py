"""Run with python -m unittest discover -s services/property-video -p test_video.py.

Requires requirements.txt, httpx (test only), and ffmpeg on PATH/FFMPEG_BINARY.
"""
import io
import json
import os
import subprocess
import tempfile
import time
import unittest
import wave
from pathlib import Path

from PIL import Image, ImageDraw

TEST_ROOT = tempfile.TemporaryDirectory(prefix="scm-video-test-")
os.environ["VIDEO_DATA_DIR"] = TEST_ROOT.name
os.environ["VIDEO_SERVICE_TOKEN"] = "test-only-" * 5

from fastapi.testclient import TestClient
from app import app


def photo(color):
    image = Image.new("RGB", (900, 600), color)
    draw = ImageDraw.Draw(image)
    draw.rectangle((40, 60, 350, 530), fill="white")
    draw.ellipse((500, 200, 790, 490), fill="#f3b43c")
    output = io.BytesIO()
    image.save(output, "JPEG")
    return output.getvalue()


class VideoTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.client = TestClient(app)
        cls.client.__enter__()
        cls.headers = {"Authorization": "Bearer " + os.environ["VIDEO_SERVICE_TOKEN"]}

    @classmethod
    def tearDownClass(cls):
        cls.client.__exit__(None, None, None)
        TEST_ROOT.cleanup()

    def create(self, manifest=None, bad=False, audio=False):
        options = manifest or {"title": "Apartamento de prueba", "operation": "En venta", "location": "Medellín", "price": "$350.000.000", "contact": "Asesor de prueba", "quality": "720", "seconds": 2, "captions": ["Sala", "Cocina"]}
        files = [("images", ("a.jpg", b"not an image" if bad else photo("#558da2"), "image/jpeg")), ("images", ("b.jpg", photo("#c79f80"), "image/jpeg"))]
        if audio:
            buffer = io.BytesIO()
            with wave.open(buffer, "wb") as sound:
                sound.setnchannels(1); sound.setsampwidth(2); sound.setframerate(8000)
                sound.writeframes(b"\x00\x00" * 8000)
            files.append(("audio", ("music.wav", buffer.getvalue(), "audio/wav")))
        return self.client.post("/jobs", headers=self.headers, data={"manifest": json.dumps(options)}, files=files)

    def test_requires_authentication_and_rejects_invalid_inputs(self):
        self.assertEqual(401, self.client.get("/health").status_code)
        self.assertEqual(200, self.client.get("/health", headers=self.headers).status_code)
        self.assertEqual(422, self.create(bad=True).status_code)
        self.assertEqual(422, self.create({"title": "Bad", "quality": "4k", "captions": ["a", "b"]}).status_code)
        self.assertEqual(404, self.client.get("/jobs/invalid", headers=self.headers).status_code)

    def test_renders_playable_portrait_mp4_with_audio_and_distinct_scenes(self):
        response = self.create(audio=True)
        self.assertEqual(202, response.status_code, response.text)
        job = response.json()["job_id"]
        self.assertEqual(409, self.client.get(f"/jobs/{job}/file", headers=self.headers).status_code)
        deadline = time.monotonic() + 150
        while time.monotonic() < deadline:
            state = self.client.get(f"/jobs/{job}", headers=self.headers).json()
            if state["status"] in ("ready", "failed"):
                break
            time.sleep(.5)
        self.assertEqual("ready", state["status"], state)
        output = self.client.get(f"/jobs/{job}/file", headers=self.headers)
        self.assertEqual("video/mp4", output.headers["content-type"])
        self.assertEqual(b"ftyp", output.content[4:8])
        path = Path(TEST_ROOT.name) / job / "video.mp4"
        ffmpeg = os.getenv("FFMPEG_BINARY", "ffmpeg")
        metadata = subprocess.run([ffmpeg, "-hide_banner", "-i", str(path)], capture_output=True).stderr.decode(errors="replace")
        self.assertIn("720x1280", metadata)
        self.assertIn("00:00:11", metadata)
        self.assertIn("Audio: aac", metadata)
        colors = []
        for second in (1, 3.8, 5.8, 9):
            result = subprocess.run([ffmpeg,"-v","error","-ss",str(second),"-i",str(path),"-frames:v","1","-f","image2pipe","-vcodec","png","-"],capture_output=True,check=True)
            with Image.open(io.BytesIO(result.stdout)) as image:
                colors.append(image.getpixel((350, 600)))
        self.assertNotEqual(colors[1], colors[2], "Different photos must produce different scenes")
        self.assertFalse(list(path.parent.glob("photo-*")), "Delete source images after rendering")

    def test_cancel_stops_processing(self):
        response = self.create()
        self.assertEqual(202, response.status_code)
        job = response.json()["job_id"]
        cancelled = self.client.delete(f"/jobs/{job}", headers=self.headers)
        self.assertEqual("cancelled", cancelled.json()["status"])
        self.assertEqual(409, self.client.get(f"/jobs/{job}/file", headers=self.headers).status_code)


if __name__ == "__main__":
    unittest.main()
