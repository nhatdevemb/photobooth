"""
Tests for LK Magic-WIN Native AI Bridge integration & fallback in Photobooth.
"""

from __future__ import annotations

import json
import subprocess
import sys
import threading
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path
from typing import Any, Dict

import numpy as np
import pytest
from PIL import Image, ImageEnhance

PYTHON_DIR = Path(__file__).resolve().parent.parent.parent / "python"
if str(PYTHON_DIR) not in sys.path:
    sys.path.insert(0, str(PYTHON_DIR))

from beauty import (
    BeautyConfig,
    BeautyProcessor,
    check_lkmagic_health,
    resolve_beauty_config,
    retouch_via_lkmagic,
)
from beauty.lkmagic_bridge import discover_lkmagic_executable, validate_loopback_url

FIXTURES_DIR = Path(__file__).resolve().parent.parent / "fixtures"
FACE_SLIM_AND_BODY_IDS = set(range(230001, 230013)) | set(range(290001, 290010))


class _FakeLkMagicHandler(BaseHTTPRequestHandler):
    fail_mode: bool = False
    small_output: bool = False

    def log_message(self, format: str, *args: Any) -> None:
        pass

    def do_GET(self) -> None:
        if self.path == "/api/v1/health":
            payload = {
                "ok": not self.fail_mode,
                "ready": not self.fail_mode,
                "busy": False,
                "version": "3.12.10-mock",
                "background": True,
                "face_slim_enabled": False,
            }
            raw = json.dumps(payload).encode("utf-8")
            self.send_response(200 if not self.fail_mode else 503)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(raw)))
            self.end_headers()
            self.wfile.write(raw)
            return
        self.send_response(404)
        self.end_headers()

    def do_POST(self) -> None:
        if self.path == "/api/v1/retouch":
            length = int(self.headers.get("Content-Length", "0"))
            body = json.loads(self.rfile.read(length).decode("utf-8"))
            if self.fail_mode:
                raw = json.dumps({"ok": False, "error": "MOCK_ENGINE_FAILURE"}).encode("utf-8")
                self.send_response(500)
                self.send_header("Content-Type", "application/json")
                self.send_header("Content-Length", str(len(raw)))
                self.end_headers()
                self.wfile.write(raw)
                return

            in_path = Path(body["input_path"])
            out_path = Path(body["output_path"])
            with Image.open(in_path) as img:
                enhanced = ImageEnhance.Brightness(img.convert("RGB")).enhance(1.06)
                if self.small_output:
                    enhanced = enhanced.resize((40, 30))
                out_path.parent.mkdir(parents=True, exist_ok=True)
                enhanced.save(out_path, format="JPEG", quality=96, subsampling=0)

            resp = {
                "ok": True,
                "status": "success",
                "engine": "lkmagic_native",
                "method": "export_fullres",
                "preset": body.get("preset", "natural"),
                "face_slim": False,
                "output_path": str(out_path),
                "duration_ms": 12,
            }
            raw = json.dumps(resp).encode("utf-8")
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(raw)))
            self.end_headers()
            self.wfile.write(raw)
            return
        self.send_response(404)
        self.end_headers()


@pytest.fixture()
def fake_lkmagic_server():
    _FakeLkMagicHandler.fail_mode = False
    _FakeLkMagicHandler.small_output = False
    server = HTTPServer(("127.0.0.1", 0), _FakeLkMagicHandler)
    port = server.server_address[1]
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    try:
        yield f"http://127.0.0.1:{port}", _FakeLkMagicHandler
    finally:
        server.shutdown()
        server.server_close()


def test_loopback_url_validation() -> None:
    assert validate_loopback_url("http://127.0.0.1:5520/") == "http://127.0.0.1:5520"
    with pytest.raises(ValueError):
        validate_loopback_url("http://192.168.1.50:5520")
    with pytest.raises(ValueError):
        validate_loopback_url("https://example.com:5520")


def test_face_slimming_strictly_disabled_in_config_and_bridge() -> None:
    for preset in ("natural", "soft", "korean-glow"):
        cfg = resolve_beauty_config(preset, overrides={"face_slim": True})
        assert cfg.face_slim is False

    bridge_cjs = (
        Path(__file__).resolve().parents[4]
        / "LK Magic-WIN"
        / "app.asar"
        / "out"
        / "main"
        / "lk-photobooth-bridge"
        / "main.cjs"
    )
    if bridge_cjs.is_file():
        out = subprocess.check_output(
            [
                "node",
                "-e",
                f"const m = require({json.dumps(str(bridge_cjs))}); console.log(JSON.stringify(m.PRESET_ALGORITHMS));",
            ],
            text=True,
        )
        presets: Dict[str, Any] = json.loads(out)
        for preset_name, algos in presets.items():
            used_ids = {int(item["id"]) for item in algos}
            forbidden = used_ids & FACE_SLIM_AND_BODY_IDS
            assert not forbidden, f"Preset {preset_name} must not contain face/body slimming IDs: {forbidden}"


def test_lkmagic_bridge_health_and_retouch(fake_lkmagic_server) -> None:
    base_url, _ = fake_lkmagic_server
    health = check_lkmagic_health(base_url)
    assert health["ok"] is True
    assert health["ready"] is True
    assert health["face_slim_enabled"] is False

    img = np.full((120, 160, 3), 140, dtype=np.uint8)
    cfg = resolve_beauty_config(
        "korean-glow",
        overrides={"engine": "lkmagic", "lkmagic_url": base_url, "auto_start_lkmagic": False},
    )
    out_rgb, stats = retouch_via_lkmagic(img, cfg)
    assert out_rgb.shape == img.shape
    assert stats["status"] == "success"
    assert stats["engine"] == "lkmagic_native"
    assert stats["face_slim"] is False
    assert float(out_rgb.mean()) > float(img.mean())


def test_reduced_resolution_export_is_rejected(fake_lkmagic_server) -> None:
    base_url, handler_cls = fake_lkmagic_server
    handler_cls.small_output = True
    cfg = resolve_beauty_config("natural", overrides={
        "engine": "lkmagic", "lkmagic_url": base_url, "auto_start_lkmagic": False,
    })
    with pytest.raises(RuntimeError, match="LK_MAGIC_INVALID_EXPORT"):
        retouch_via_lkmagic(np.full((120, 160, 3), 140, dtype=np.uint8), cfg)


def test_auto_engine_does_not_require_local_models(fake_lkmagic_server, monkeypatch) -> None:
    base_url, _ = fake_lkmagic_server
    cfg = resolve_beauty_config("natural", overrides={
        "engine": "auto", "lkmagic_url": base_url, "auto_start_lkmagic": False,
    })
    def unavailable_models(*args):
        pytest.fail("Local models must not load when LK Magic succeeds")
    monkeypatch.setattr(BeautyProcessor, "_ensure_models", unavailable_models)
    with BeautyProcessor(cfg) as processor:
        out, stats = processor.process(np.full((120, 160, 3), 140, dtype=np.uint8))
        assert out.shape == (120, 160, 3)
        assert stats["engine"] == "lkmagic_native"


def test_small_native_export_uses_local_fallback(fake_lkmagic_server) -> None:
    base_url, handler_cls = fake_lkmagic_server
    handler_cls.small_output = True
    cfg = resolve_beauty_config("soft", overrides={
        "engine": "auto", "lkmagic_url": base_url, "auto_start_lkmagic": False,
    })
    with Image.open(PYTHON_DIR.parent / "public/assets/samples/source-portrait.jpg") as image:
        with BeautyProcessor(cfg) as processor:
            output, stats = processor.process(image.convert("RGB"))
            assert output.size == image.size
            assert stats["status"] == "success"
            assert stats.get("engine") != "lkmagic_native"


def test_engine_is_part_of_serialized_beauty_config() -> None:
    local = resolve_beauty_config("natural", overrides={"engine": "mediapipe"})
    native = resolve_beauty_config("natural", overrides={"engine": "lkmagic"})
    assert local.to_dict()["engine"] != native.to_dict()["engine"]


def test_beauty_processor_prefers_lkmagic_and_falls_back_on_error(fake_lkmagic_server) -> None:
    base_url, handler_cls = fake_lkmagic_server
    fixture_path = Path(__file__).resolve().parent.parent.parent / "public" / "assets" / "samples" / "source-portrait.jpg"
    if not fixture_path.is_file():
        pytest.skip("Portrait sample photo not found")

    with Image.open(fixture_path) as im:
        orig_pil = im.convert("RGB")

    with BeautyProcessor() as processor:
        # 1. When LK Magic bridge is online -> uses lkmagic_native
        cfg_online = resolve_beauty_config(
            "soft",
            overrides={"engine": "auto", "lkmagic_url": base_url, "auto_start_lkmagic": False},
        )
        out_online, stats_online = processor.process(orig_pil, cfg_online)
        assert isinstance(out_online, Image.Image)
        assert out_online.size == orig_pil.size
        assert stats_online["status"] == "success"
        assert stats_online["engine"] == "lkmagic_native"
        assert processor._detector is None
        assert processor._mask_gen is None

        # 2. When LK Magic bridge fails -> falls back to local MediaPipe seamlessly
        handler_cls.fail_mode = True
        out_fallback, stats_fallback = processor.process(orig_pil, cfg_online)
        assert isinstance(out_fallback, Image.Image)
        assert out_fallback.size == orig_pil.size
        assert stats_fallback["status"] == "success"
        assert stats_fallback.get("engine") != "lkmagic_native"
        assert stats_fallback["faces_detected"] >= 1
