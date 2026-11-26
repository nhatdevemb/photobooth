"""Behavioral tests for custom/python/image_daemon.py and in-RAM Beauty cache."""

from __future__ import annotations

import concurrent.futures
import http.client
import json
import os
from pathlib import Path
import shutil
import sys
import threading
import time
import urllib.error
import urllib.request
import pytest

CUSTOM_ROOT = Path(__file__).resolve().parents[2]
PYTHON_DIR = CUSTOM_ROOT / "python"
if str(PYTHON_DIR) not in sys.path:
    sys.path.insert(0, str(PYTHON_DIR))

import image_daemon
import render_pipeline as rp
from beauty.lkmagic_bridge import discover_lkmagic_executable

FIXTURES_DIR = CUSTOM_ROOT / "tests" / "fixtures"
PORTRAIT_SAMPLE = CUSTOM_ROOT / "public" / "assets" / "samples" / "source-portrait.jpg"
BEAUTY_CHECK_SAMPLE = CUSTOM_ROOT / "assets" / "beauty-check.jpg"
PRESETS_DIR = CUSTOM_ROOT / "presets"
TEMPLATE_PATH = CUSTOM_ROOT / "templates" / "vertical-three-v1.json"


def _daemon_request(base_url: str, method: str, path: str, token: str = "", payload: dict | None = None) -> tuple[int, dict]:
    headers = {"Accept": "application/json"}
    if token:
        headers["X-Photobooth-Token"] = token
    body = None
    if payload is not None:
        body = json.dumps(payload).encode("utf-8")
        headers["Content-Type"] = "application/json; charset=utf-8"
    req = urllib.request.Request(base_url + path, data=body, headers=headers, method=method)
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
    try:
        with opener.open(req, timeout=60) as resp:
            return resp.status, json.loads(resp.read().decode("utf-8"))
    except urllib.error.HTTPError as err:
        return err.code, json.loads(err.read().decode("utf-8"))


@pytest.fixture()
def running_daemon():
    token = "test-daemon-secret-token-0123456789"
    server = image_daemon.create_server("127.0.0.1", 0, token)
    port = server.server_address[1]
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    try:
        yield f"http://127.0.0.1:{port}", token
    finally:
        server.shutdown()
        thread.join(timeout=5)
        server.server_close()
        rp.enable_daemon_runtime(False)


def test_daemon_rejects_non_loopback_bind() -> None:
    with pytest.raises(ValueError, match="loopback"):
        image_daemon.create_server("0.0.0.0", 0, "secret")


def test_daemon_enforces_token_keepalive_and_endpoints(running_daemon: tuple[str, str], tmp_path: Path) -> None:
    base_url, token = running_daemon
    port = int(base_url.rsplit(":", 1)[1])

    # Missing or wrong token returns 403 FORBIDDEN without poisoning HTTP/1.1 client reuse
    conn = http.client.HTTPConnection("127.0.0.1", port, timeout=5)
    try:
        conn.request(
            "POST",
            "/validate-jpeg",
            body=json.dumps({"file_path": "/tmp/a.jpg"}).encode("utf-8"),
            headers={"Content-Type": "application/json", "X-Photobooth-Token": "wrong"},
        )
        r1 = conn.getresponse()
        _ = r1.read()
        assert r1.status == 403

        conn.request("GET", "/health", headers={"X-Photobooth-Token": token})
        r2 = conn.getresponse()
        r2_data = json.loads(r2.read().decode("utf-8"))
        assert r2.status == 200
        assert r2_data["status"] == "ok"
    finally:
        conn.close()

    # JPEG validation
    photo_1 = FIXTURES_DIR / "slot-1-v1.jpg"
    st_val, val_res = _daemon_request(
        base_url, "POST", "/validate-jpeg", token=token, payload={"file_path": str(photo_1)}
    )
    assert st_val == 200
    assert val_res["status"] == "ok"
    assert val_res["sha256"] == rp.sha256_file(photo_1)

    # Frame text preview & internal error distinction
    slots_spec = [{
        "name": {"x": 80, "y": 1520, "width": 1020, "height": 80, "size": 42, "min_size": 22, "color": "#1e293b"},
    }]
    st_ft, ft_res = _daemon_request(
        base_url,
        "POST",
        "/frame-text",
        token=token,
        payload={"fields": {"name": "Minh & Lan", "date": "", "wish": ""}, "slots": slots_spec, "preview": True},
    )
    assert st_ft == 200
    assert ft_res["status"] == "ok"
    assert ft_res["url"].startswith("data:image/png;base64,")

    st_bad_ft, bad_ft_res = _daemon_request(
        base_url,
        "POST",
        "/frame-text",
        token=token,
        payload={"fields": {"name": "A", "date": "", "wish": ""}, "slots": [{"name": {"x": 10, "y": 10}}], "preview": False},
    )
    assert st_bad_ft == 200
    assert bad_ft_res["status"] == "error"
    assert bad_ft_res["code"] == "RENDER_FAILED"

    # Concurrent LUT previews to shared path must not collide on temp files
    shared_out = tmp_path / "lut-sample-concurrent.jpg"
    lut_file = str(PRESETS_DIR / "korean-soft.cube")
    with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:
        futures = [
            pool.submit(
                _daemon_request,
                base_url,
                "POST",
                "/lut-preview",
                token,
                {"lut_path": lut_file, "output_path": str(shared_out), "intensity": 80},
            )
            for _ in range(16)
        ]
        for fut in futures:
            st_lut, lut_res = fut.result()
            assert st_lut == 200
            assert lut_res["status"] == "ok"
    assert shared_out.is_file()


def test_runtime_env_forwards_lkmagic_executable_and_auto_start(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    fake_exe = tmp_path / "LK magic.exe"
    fake_exe.write_bytes(b"MZ-fake")
    monkeypatch.delenv("LKMAGIC_EXECUTABLE", raising=False)
    monkeypatch.setenv("LKMAGIC_AUTO_START", "0")

    cfg = rp.beauty_config_for({
        "beauty_preset": "natural",
        "runtime_env": {
            "beauty_engine": "lkmagic",
            "lkmagic_bridge_url": "http://127.0.0.1:59999",
            "lkmagic_executable": str(fake_exe),
            "lkmagic_auto_start": True,
        },
    })
    assert cfg.auto_start_lkmagic is True
    assert cfg.lkmagic_executable == str(fake_exe)
    assert discover_lkmagic_executable(cfg.lkmagic_executable) == fake_exe.resolve()


def test_daemon_ram_beauty_cache_real_faces_determinism_and_eviction(
    running_daemon: tuple[str, str], tmp_path: Path
) -> None:
    base_url, token = running_daemon
    photos = [
        {"slot": 1, "path": str(PORTRAIT_SAMPLE), "sha256": rp.sha256_file(PORTRAIT_SAMPLE)},
        {"slot": 2, "path": str(BEAUTY_CHECK_SAMPLE), "sha256": rp.sha256_file(BEAUTY_CHECK_SAMPLE)},
        {"slot": 3, "path": str(FIXTURES_DIR / "slot-3-v1.jpg"), "sha256": rp.sha256_file(FIXTURES_DIR / "slot-3-v1.jpg")},
    ]
    cache_dir = tmp_path / "beauty" / "sessions" / "session-ram-test"

    # Step 1: Background prepare_beauty via daemon on real human face portraits
    st_prep, prep_res = _daemon_request(
        base_url,
        "POST",
        "/beauty-task",
        token=token,
        payload={
            "task": "prepare_beauty",
            "beauty_cache_dir": str(cache_dir),
            "beauty_preset": "korean-glow",
            "runtime_env": {"beauty_engine": "mediapipe", "lkmagic_auto_start": False},
            "photos": photos,
            "beauty_progress_key": "prog-key-1",
        },
    )
    assert st_prep == 200
    assert prep_res["status"] == "ok"
    assert prep_res["prepared"] is True
    assert (cache_dir / "batch.json").is_file()
    assert (cache_dir / "progress.json").is_file()
    assert list(cache_dir.glob("*.png")) == []

    # Step 2: Render via daemon hitting in-RAM Beauty cache
    daemon_print = tmp_path / "daemon_print.jpg"
    daemon_preview = tmp_path / "daemon_preview.jpg"
    st_rnd, rnd_res = _daemon_request(
        base_url,
        "POST",
        "/render",
        token=token,
        payload={
            "photos": photos,
            "lut_id": "korean-soft",
            "lut_version": "1.0.0",
            "lut_path": str(PRESETS_DIR / "korean-soft.cube"),
            "lut_intensity": 100,
            "template_path": str(TEMPLATE_PATH),
            "beauty_preset": "korean-glow",
            "beauty_cache_dir": str(cache_dir),
            "runtime_env": {"beauty_engine": "mediapipe", "lkmagic_auto_start": False},
            "output_print_path": str(daemon_print),
            "output_preview_path": str(daemon_preview),
        },
    )
    assert st_rnd == 200
    assert rnd_res["status"] == "ok"
    assert len(rnd_res["beauty_audit"]) == 3
    assert rnd_res["beauty_audit"][0]["faces_detected"] >= 1
    assert rnd_res["beauty_audit"][1]["faces_detected"] >= 1
    assert all(item["cache_hit"] is True and item["duration_ms"] == 0 for item in rnd_res["beauty_audit"])
    assert list(cache_dir.glob("*.png")) == []

    # Step 3: Compare against standalone CLI non-daemon render for exact byte determinism
    rp.enable_daemon_runtime(False)
    cli_print = tmp_path / "cli_print.jpg"
    cli_preview = tmp_path / "cli_preview.jpg"
    cli_res = rp.run_render_from_manifest({
        "photos": photos,
        "lut_id": "korean-soft",
        "lut_version": "1.0.0",
        "lut_path": str(PRESETS_DIR / "korean-soft.cube"),
        "lut_intensity": 100,
        "template_path": str(TEMPLATE_PATH),
        "beauty_preset": "korean-glow",
        "runtime_env": {"beauty_engine": "mediapipe", "lkmagic_auto_start": False},
        "output_print_path": str(cli_print),
        "output_preview_path": str(cli_preview),
    })
    assert rnd_res["sha256"] == cli_res["sha256"]
    assert rnd_res["input_signature"] == cli_res["input_signature"]

    # Step 4: Re-enable daemon mode, populate RAM cache, delete session dir, verify non-Beauty calls evict RAM
    rp.enable_daemon_runtime(True)
    _daemon_request(
        base_url,
        "POST",
        "/beauty-task",
        token=token,
        payload={
            "task": "prepare_beauty",
            "beauty_cache_dir": str(cache_dir),
            "beauty_preset": "korean-glow",
            "runtime_env": {"beauty_engine": "mediapipe", "lkmagic_auto_start": False},
            "photos": photos,
        },
    )
    assert len(rp._BEAUTY_RAM_CACHE) == 1
    shutil.rmtree(cache_dir)
    _daemon_request(base_url, "POST", "/validate-jpeg", token=token, payload={"file_path": str(PORTRAIT_SAMPLE)})
    assert len(rp._BEAUTY_RAM_CACHE) == 0


def test_daemon_render_deadline_prevents_zombie_output_writes(
    running_daemon: tuple[str, str], tmp_path: Path
) -> None:
    base_url, token = running_daemon

    # Spin up slow mock LK Magic bridge (0.55s per photo -> 1.65s total > 1.0s timeout)
    from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

    class SlowBridgeHandler(BaseHTTPRequestHandler):
        def log_message(self, *args):
            pass

        def do_GET(self):
            raw = json.dumps({"ok": True, "ready": True}).encode("utf-8")
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(raw)))
            self.end_headers()
            self.wfile.write(raw)

        def do_POST(self):
            length = int(self.headers.get("Content-Length", "0"))
            req_body = json.loads(self.rfile.read(length).decode("utf-8"))
            time.sleep(0.55)
            shutil.copyfile(req_body["input_path"], req_body["output_path"])
            raw = json.dumps({"ok": True, "method": "copy"}).encode("utf-8")
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(raw)))
            self.end_headers()
            self.wfile.write(raw)

    bridge = ThreadingHTTPServer(("127.0.0.1", 0), SlowBridgeHandler)
    bridge_port = bridge.server_address[1]
    bridge_thread = threading.Thread(target=bridge.serve_forever, daemon=True)
    bridge_thread.start()
    try:
        photos = [
            {"slot": i, "path": str(FIXTURES_DIR / f"slot-{i}-v1.jpg"), "sha256": rp.sha256_file(FIXTURES_DIR / f"slot-{i}-v1.jpg")}
            for i in (1, 2, 3)
        ]
        attempt_dir = tmp_path / "pending" / "attempt-1"
        attempt_dir.mkdir(parents=True, exist_ok=True)
        cache_dir = tmp_path / "beauty" / "sessions" / "sid-timeout"

        st, res = _daemon_request(
            base_url,
            "POST",
            "/render",
            token=token,
            payload={
                "photos": photos,
                "lut_id": "original",
                "template_path": str(TEMPLATE_PATH),
                "beauty_preset": "natural",
                "beauty_cache_dir": str(cache_dir),
                "runtime_env": {
                    "beauty_engine": "lkmagic",
                    "lkmagic_bridge_url": f"http://127.0.0.1:{bridge_port}",
                    "lkmagic_auto_start": False,
                },
                "output_print_path": str(attempt_dir / "print.jpg"),
                "output_preview_path": str(attempt_dir / "preview.jpg"),
                "timeout_seconds": 1,
            },
        )
        shutil.rmtree(attempt_dir)
        assert st == 200
        assert res["status"] == "error"
        assert res["code"] == "RENDER_TIMEOUT"
        time.sleep(0.8)
        assert not attempt_dir.exists()
    finally:
        bridge.shutdown()
        bridge_thread.join(timeout=5)
        bridge.server_close()
