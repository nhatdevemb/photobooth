#!/usr/bin/env python3
"""
Loopback HTTP daemon keeping Pillow, NumPy, 3D LUTs, and MediaPipe Beauty warm.

Eliminates cold python.exe startup overhead on Windows across JPEG validation,
event frame lettering previews, LUT sample generation, background Beauty prep,
and final collage rendering while keeping active-session Beauty pixels in RAM.
"""

from __future__ import annotations

import argparse
import base64
from functools import lru_cache
import hashlib
import hmac
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import io
import json
import os
from pathlib import Path
import select
import socket
import sys
import threading
import time
from typing import Any, Callable, Dict, Optional, Tuple

from PIL import Image

from cube_lut import LutParseError
from frame_text import frame_layer
import render_pipeline as rp

MAX_REQUEST_BYTES = 2 * 1024 * 1024  # 2 MiB JSON control payload limit
MAX_ERROR_DRAIN_BYTES = 65536
RAM_SWEEP_INTERVAL_SEC = 5.0
SAMPLE_PORTRAIT_PATH = Path(__file__).resolve().parents[1] / "public" / "assets" / "samples" / "source-portrait.jpg"
_JOB_EXECUTION_LOCK = threading.Lock()


@lru_cache(maxsize=1)
def _load_sample_portrait() -> Image.Image:
    image, _, raw = rp.validate_and_load_jpeg(SAMPLE_PORTRAIT_PATH)
    normalized, _ = rp.normalize_orientation_and_srgb(image, raw, 1)
    return normalized


def _extract_deadline(payload: Dict[str, Any]) -> Tuple[Optional[float], float]:
    raw_timeout = payload.get("timeout_seconds")
    if isinstance(raw_timeout, (int, float)) and raw_timeout > 0:
        timeout_sec = float(raw_timeout)
        return time.monotonic() + timeout_sec, timeout_sec
    return None, 90.0


def _make_socket_disconnect_checker(conn: socket.socket) -> Callable[[], bool]:
    def _is_disconnected() -> bool:
        try:
            readable, _, _ = select.select([conn], [], [], 0)
            if not readable:
                return False
            conn.setblocking(False)
            try:
                data = conn.recv(1, socket.MSG_PEEK)
                return data == b""
            except BlockingIOError:
                return False
            except OSError:
                return True
            finally:
                try:
                    conn.setblocking(True)
                except OSError:
                    pass
        except (OSError, ValueError):
            return True

    return _is_disconnected


def handle_validate_jpeg(payload: Dict[str, Any]) -> Tuple[int, Dict[str, Any]]:
    file_path = payload.get("file_path")
    if not isinstance(file_path, str) or not file_path:
        return 422, {"status": "error", "code": "INVALID_JPEG", "message": "Missing file_path"}
    max_bytes = int(payload.get("max_jpeg_bytes", rp.DEFAULT_MAX_JPEG_BYTES))
    max_pixels = int(payload.get("max_image_pixels", rp.DEFAULT_MAX_IMAGE_PIXELS))
    try:
        return 200, rp.validate_jpeg_payload(file_path, max_bytes, max_pixels)
    except rp.PipelineError as exc:
        return 200, {"status": "error", "code": exc.code, "message": exc.message}
    except Exception as exc:
        return 200, {"status": "error", "code": "INTERNAL_ERROR", "message": str(exc)}


def handle_frame_text(payload: Dict[str, Any]) -> Tuple[int, Dict[str, Any]]:
    fields = payload.get("fields")
    slots = payload.get("slots")
    preview = bool(payload.get("preview", False))
    if not isinstance(fields, dict) or not isinstance(slots, list) or (preview and not slots):
        return 422, {"status": "error", "code": "INVALID_FRAME_TEXT", "message": "Invalid frame_text payload"}
    try:
        if preview:
            layer = frame_layer((1181, 1748), slots[0], fields)
            output = io.BytesIO()
            layer.save(output, format="PNG")
            return 200, {"status": "ok", "url": "data:image/png;base64," + base64.b64encode(output.getvalue()).decode("ascii")}
        for item in slots:
            frame_layer((1181, 1748), item, fields, validate_only=True)
        return 200, {"status": "ok"}
    except ValueError as exc:
        return 200, {"status": "error", "code": "INVALID_FRAME_TEXT", "message": str(exc)}
    except Exception as exc:
        rp.log_err(f"frame_text internal error: {exc}")
        return 200, {"status": "error", "code": "RENDER_FAILED"}


def handle_lut_preview(payload: Dict[str, Any]) -> Tuple[int, Dict[str, Any]]:
    lut_path = payload.get("lut_path")
    output_path = payload.get("output_path")
    intensity = payload.get("intensity")
    if not isinstance(lut_path, str) or not isinstance(output_path, str) or type(intensity) is not int:
        return 422, {"status": "error", "code": "INVALID_LUT", "message": "Invalid lut_preview payload"}
    try:
        lut = rp.load_cube_lut_cached(Path(lut_path))
        base_img = _load_sample_portrait().copy()
        graded = rp.grade_rgb_image(base_img, lut, intensity)
        graded.thumbnail((1000, 1000))
        out = Path(output_path)
        jpeg_bytes = rp.encode_jpeg_bytes(graded, 95, 0, 300, rp.get_srgb_icc_bytes())
        rp.atomic_write_bytes(out, jpeg_bytes)
        return 200, {
            "status": "ok",
            "title": lut.title,
            "size": lut.size,
            "sha256": lut.sha256,
            "preview_sha256": hashlib.sha256(jpeg_bytes).hexdigest(),
        }
    except (LutParseError, rp.PipelineError, ValueError, OSError) as exc:
        return 200, {"status": "error", "code": "INVALID_LUT", "message": str(exc)}


def handle_beauty_task(
    payload: Dict[str, Any],
    cancel_check: Optional[Callable[[], bool]] = None,
) -> Tuple[int, Dict[str, Any]]:
    deadline_mono, wait_budget = _extract_deadline(payload)
    lock_wait = max(0.05, deadline_mono - time.monotonic()) if deadline_mono is not None else wait_budget
    if not _JOB_EXECUTION_LOCK.acquire(timeout=lock_wait):
        return 200, {"status": "error", "code": "RENDER_TIMEOUT", "message": "Beauty execution lock timeout"}
    try:
        return 200, rp.run_beauty_task(payload, deadline_mono=deadline_mono, cancel_check=cancel_check)
    except rp.PipelineError as exc:
        return 200, {"status": "error", "code": exc.code, "message": exc.message}
    except Exception as exc:
        return 200, {"status": "error", "code": "RENDER_FAILED", "message": str(exc)}
    finally:
        _JOB_EXECUTION_LOCK.release()


def handle_render(
    payload: Dict[str, Any],
    cancel_check: Optional[Callable[[], bool]] = None,
) -> Tuple[int, Dict[str, Any]]:
    deadline_mono, wait_budget = _extract_deadline(payload)
    lock_wait = max(0.05, deadline_mono - time.monotonic()) if deadline_mono is not None else wait_budget
    if not _JOB_EXECUTION_LOCK.acquire(timeout=lock_wait):
        return 200, {"status": "error", "code": "RENDER_TIMEOUT", "message": "Render execution lock timeout"}
    try:
        return 200, rp.run_render_from_manifest(payload, deadline_mono=deadline_mono, cancel_check=cancel_check)
    except rp.PipelineError as exc:
        return 200, {"status": "error", "code": exc.code, "message": exc.message}
    except Exception as exc:
        return 200, {"status": "error", "code": "RENDER_FAILED", "message": str(exc)}
    finally:
        _JOB_EXECUTION_LOCK.release()


def create_server(host: str, port: int, expected_token: str) -> ThreadingHTTPServer:
    if host not in ("127.0.0.1", "::1", "localhost"):
        raise ValueError(f"image_daemon must bind loopback only, got: {host}")

    rp.enable_daemon_runtime(True)
    # Warm sRGB ICC profile bytes upfront
    rp.get_srgb_icc_bytes()

    class ImageDaemonHandler(BaseHTTPRequestHandler):
        protocol_version = "HTTP/1.1"

        def _check_auth(self) -> bool:
            if expected_token == "":
                return True
            provided = self.headers.get("X-Photobooth-Token", "")
            return hmac.compare_digest(provided, expected_token)

        def _drain_small_body_before_close(self, length: int) -> None:
            if 0 < length <= MAX_ERROR_DRAIN_BYTES:
                prev_timeout = self.connection.gettimeout()
                try:
                    self.connection.settimeout(0.25)
                    _ = self.rfile.read(length)
                except OSError:
                    pass
                finally:
                    try:
                        self.connection.settimeout(prev_timeout)
                    except OSError:
                        pass

        def _send_json(self, status: int, data: Dict[str, Any]) -> None:
            if status >= 400:
                self.close_connection = True
            raw = json.dumps(data, ensure_ascii=False).encode("utf-8")
            self.send_response(status)
            self.send_header("Content-Type", "application/json; charset=utf-8")
            self.send_header("Content-Length", str(len(raw)))
            self.send_header("Cache-Control", "no-store")
            if self.close_connection:
                self.send_header("Connection", "close")
            self.end_headers()
            self.wfile.write(raw)

        def do_GET(self) -> None:
            ram_stats = rp.get_ram_cache_stats()
            if not self._check_auth():
                self.close_connection = True
                self._send_json(403, {"status": "error", "code": "FORBIDDEN", "message": "Invalid daemon token"})
                return
            if self.path == "/health":
                self._send_json(200, {
                    "status": "ok",
                    "pid": os.getpid(),
                    "pipeline_version": rp.PIPELINE_VERSION,
                    "ram_cache": ram_stats,
                })
                return
            self.close_connection = True
            self._send_json(404, {"status": "error", "code": "NOT_FOUND", "message": "Unknown route"})

        def do_POST(self) -> None:
            rp.get_ram_cache_stats()
            if self.headers.get("Transfer-Encoding") is not None:
                self.close_connection = True
                self._send_json(400, {"status": "error", "code": "INVALID_JSON", "message": "Chunked encoding unsupported"})
                return
            try:
                length = int(self.headers.get("Content-Length", "0"))
            except ValueError:
                self.close_connection = True
                self._send_json(400, {"status": "error", "code": "INVALID_JSON", "message": "Invalid Content-Length"})
                return

            if not self._check_auth():
                self._drain_small_body_before_close(length)
                self.close_connection = True
                self._send_json(403, {"status": "error", "code": "FORBIDDEN", "message": "Invalid daemon token"})
                return

            if length <= 0 or length > MAX_REQUEST_BYTES:
                self._drain_small_body_before_close(length)
                self.close_connection = True
                self._send_json(413, {"status": "error", "code": "PAYLOAD_TOO_LARGE", "message": "Payload out of bounds"})
                return

            body_bytes = self.rfile.read(length)
            try:
                payload = json.loads(body_bytes.decode("utf-8"))
                if not isinstance(payload, dict):
                    raise ValueError("JSON root must be an object")
            except Exception as exc:
                self.close_connection = True
                self._send_json(400, {"status": "error", "code": "INVALID_JSON", "message": str(exc)})
                return

            cancel_check = _make_socket_disconnect_checker(self.connection)
            if self.path == "/validate-jpeg":
                status, result = handle_validate_jpeg(payload)
            elif self.path == "/frame-text":
                status, result = handle_frame_text(payload)
            elif self.path == "/lut-preview":
                status, result = handle_lut_preview(payload)
            elif self.path == "/beauty-task":
                status, result = handle_beauty_task(payload, cancel_check=cancel_check)
            elif self.path == "/render":
                status, result = handle_render(payload, cancel_check=cancel_check)
            else:
                self.close_connection = True
                self._send_json(404, {"status": "error", "code": "NOT_FOUND", "message": "Unknown route"})
                return

            self._send_json(status, result)

        def log_message(self, format: str, *args: Any) -> None:
            pass

    server = ThreadingHTTPServer((host, port), ImageDaemonHandler)
    server.daemon_threads = True

    stop_sweep = threading.Event()

    def _sweep_closed_session_ram() -> None:
        while not stop_sweep.wait(RAM_SWEEP_INTERVAL_SEC):
            rp.get_ram_cache_stats()

    sweeper = threading.Thread(target=_sweep_closed_session_ram, name="pb-ram-cache-sweeper", daemon=True)
    sweeper.start()

    orig_close = server.server_close

    def _close_with_sweeper() -> None:
        stop_sweep.set()
        orig_close()

    server.server_close = _close_with_sweeper  # type: ignore[method-assign]
    return server


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--host", default="127.0.0.1", help="Loopback bind address")
    parser.add_argument("--port", type=int, required=True, help="Loopback TCP port")
    args = parser.parse_args(argv)

    token = os.getenv("IMAGE_DAEMON_TOKEN", "")
    server = create_server(args.host, args.port, token)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()
        rp.enable_daemon_runtime(False)
    return 0


if __name__ == "__main__":
    sys.exit(main())
