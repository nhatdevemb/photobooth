"""
LK Magic Native AI Bridge Client for Photobooth Operator Edition.
Communicates with the local HTTP bridge inside LK Magic-WIN (127.0.0.1:5520)
and supports automatic background startup (LK_PHOTOBOOTH_BACKGROUND=1).
"""

from __future__ import annotations

import json
import os
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path
from typing import Any, Dict, Optional, Tuple

import numpy as np
from PIL import Image

from .config import BeautyConfig

DEFAULT_LKMAGIC_URL = "http://127.0.0.1:5520"


def _build_no_proxy_opener() -> urllib.request.OpenerDirector:
    return urllib.request.build_opener(urllib.request.ProxyHandler({}))


def validate_loopback_url(url: str) -> str:
    parsed = urllib.parse.urlparse(url.strip())
    if parsed.scheme != "http" or parsed.hostname not in ("127.0.0.1", "::1", "localhost"):
        raise ValueError(f"LKMAGIC_BRIDGE_URL must be an HTTP loopback URL, got: {url}")
    return url.strip().rstrip("/")


def discover_lkmagic_executable(explicit_path: Optional[str] = None) -> Optional[Path]:
    """Locate `LK magic.exe` from explicit config, environment, workspace sibling, or AppData."""
    candidates = []
    if explicit_path:
        candidates.append(Path(explicit_path))
    env_exe = os.getenv("LKMAGIC_EXECUTABLE", "").strip()
    if env_exe:
        candidates.append(Path(env_exe))

    # Workspace sibling: d:\workspace\Photobooth\LK Magic-WIN\LK magic.exe
    try:
        workspace_root = Path(__file__).resolve().parents[4]
        candidates.append(workspace_root / "LK Magic-WIN" / "LK magic.exe")
    except IndexError:
        pass

    local_app_data = os.getenv("LOCALAPPDATA", "").strip()
    if local_app_data:
        candidates.append(Path(local_app_data) / "Programs" / "LK magic" / "LK magic.exe")

    for candidate in candidates:
        try:
            if candidate.is_file():
                return candidate.resolve()
        except OSError:
            continue
    return None


def check_lkmagic_health(base_url: str = DEFAULT_LKMAGIC_URL, timeout: float = 0.8) -> Dict[str, Any]:
    """Query GET /api/v1/health on the local LK Magic bridge."""
    url = validate_loopback_url(base_url) + "/api/v1/health"
    opener = _build_no_proxy_opener()
    req = urllib.request.Request(url, method="GET")
    try:
        with opener.open(req, timeout=timeout) as resp:
            raw = resp.read().decode("utf-8", errors="replace")
            data = json.loads(raw)
            if isinstance(data, dict):
                return data
            return {"ok": False, "ready": False, "error": "INVALID_HEALTH_JSON"}
    except Exception as exc:
        return {"ok": False, "ready": False, "error": str(exc)}


def ensure_lkmagic_background(
    base_url: str = DEFAULT_LKMAGIC_URL,
    executable_path: Optional[str] = None,
    wait_seconds: float = 20.0,
) -> Dict[str, Any]:
    """
    Ensure LK Magic-WIN is running and initialized in background mode (`LK_PHOTOBOOTH_BACKGROUND=1`).
    If already running, returns its health status immediately.
    """
    health = check_lkmagic_health(base_url, timeout=0.6)
    if health.get("ok") and health.get("ready"):
        return health

    # If the HTTP server is already up but globalInit is still finishing, wait for it
    if health.get("ok") and not health.get("ready"):
        deadline = time.monotonic() + wait_seconds
        while time.monotonic() < deadline:
            time.sleep(0.4)
            health = check_lkmagic_health(base_url, timeout=0.8)
            if health.get("ok") and health.get("ready"):
                return health
        return health

    if os.name != "nt" or os.getenv("LKMAGIC_AUTO_START", "1").strip().lower() in ("0", "false", "no", "off"):
        return health

    exe = discover_lkmagic_executable(executable_path)
    if exe is None:
        return {"ok": False, "ready": False, "error": "LKMAGIC_EXECUTABLE_NOT_FOUND"}

    parsed = urllib.parse.urlparse(validate_loopback_url(base_url))
    port = parsed.port or 5520

    env = os.environ.copy()
    env["LK_PHOTOBOOTH_BACKGROUND"] = "1"
    env["LK_PHOTOBOOTH_PORT"] = str(port)

    creationflags = getattr(subprocess, "CREATE_NO_WINDOW", 0)
    try:
        subprocess.Popen(
            [str(exe)],
            cwd=str(exe.parent),
            env=env,
            stdin=subprocess.DEVNULL,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            creationflags=creationflags,
        )
    except Exception as exc:
        return {"ok": False, "ready": False, "error": f"SPAWN_FAILED: {exc}"}

    deadline = time.monotonic() + wait_seconds
    while time.monotonic() < deadline:
        time.sleep(0.5)
        health = check_lkmagic_health(base_url, timeout=0.8)
        if health.get("ok") and health.get("ready"):
            return health

    return health


def shutdown_lkmagic_background(base_url: str = DEFAULT_LKMAGIC_URL, timeout: float = 2.0) -> bool:
    """Request graceful shutdown of the background LK Magic instance."""
    try:
        url = validate_loopback_url(base_url) + "/api/v1/shutdown"
        opener = _build_no_proxy_opener()
        req = urllib.request.Request(
            url,
            data=b"{}",
            headers={"Content-Type": "application/json"},
            method="POST",
        )
        with opener.open(req, timeout=timeout) as resp:
            return resp.status == 200
    except Exception:
        return False


def retouch_via_lkmagic(
    image_rgb: np.ndarray,
    config: BeautyConfig,
    work_dir: Optional[Path] = None,
) -> Tuple[np.ndarray, Dict[str, Any]]:
    """
    Send an RGB image array to LK Magic-WIN's native AI engine (`aipic.dll` + `aipic-host.exe`)
    and return `(retouched_rgb, audit_stats)`.
    """
    start_ns = time.monotonic_ns()
    base_url = validate_loopback_url(config.lkmagic_url or DEFAULT_LKMAGIC_URL)

    health = (
        ensure_lkmagic_background(base_url, wait_seconds=min(20.0, config.lkmagic_timeout_sec))
        if config.auto_start_lkmagic
        else check_lkmagic_health(base_url, timeout=0.8)
    )
    if not (health.get("ok") and health.get("ready")):
        raise RuntimeError(f"LK Magic bridge not ready: {health.get('error', 'ENGINE_NOT_READY')}")

    orig_h, orig_w = image_rgb.shape[:2]

    with tempfile.TemporaryDirectory(prefix="pb_lkmagic_", dir=str(work_dir) if work_dir else None) as tmp_dir:
        tmp_root = Path(tmp_dir)
        in_path = tmp_root / "input.jpg"
        out_path = tmp_root / "output.jpg"

        pil_in = Image.fromarray(image_rgb, mode="RGB")
        pil_in.save(in_path, format="JPEG", quality=98, subsampling=0)

        payload_bytes = json.dumps(
            {
                "input_path": str(in_path),
                "output_path": str(out_path),
                "preset": config.preset,
                "beauty_config": {
                    "smooth": config.smooth,
                    "brighten": config.brighten,
                    "tone": config.tone,
                    "face_slim": False,
                },
                "timeout_ms": int(config.lkmagic_timeout_sec * 1000),
            }
        ).encode("utf-8")

        req = urllib.request.Request(
            base_url + "/api/v1/retouch",
            data=payload_bytes,
            headers={"Content-Type": "application/json; charset=utf-8"},
            method="POST",
        )
        opener = _build_no_proxy_opener()
        with opener.open(req, timeout=config.lkmagic_timeout_sec + 3.0) as resp:
            resp_data = json.loads(resp.read().decode("utf-8", errors="replace"))

        if not isinstance(resp_data, dict):
            raise RuntimeError("LK Magic returned an invalid response.")
        if not resp_data.get("ok"):
            raise RuntimeError(resp_data.get("error", "LK_MAGIC_RETOUCH_FAILED"))

        if not out_path.is_file() or out_path.stat().st_size <= 0:
            raise RuntimeError("LK Magic did not produce output JPEG file.")

        with Image.open(out_path) as out_img:
            if out_img.format != "JPEG" or out_img.size != (orig_w, orig_h):
                raise RuntimeError(
                    "LK_MAGIC_INVALID_EXPORT: Expected full-resolution JPEG "
                    f"{orig_w}x{orig_h}, received {out_img.format} {out_img.width}x{out_img.height}."
                )
            out_rgb_pil = out_img.convert("RGB")
            output_rgb = np.asarray(out_rgb_pil, dtype=np.uint8).copy()

    duration_ms = int((time.monotonic_ns() - start_ns) // 1_000_000)
    stats: Dict[str, Any] = {
        "status": "success",
        "engine": "lkmagic_native",
        "method": str(resp_data.get("method", "export_fullres")),
        "preset": config.preset,
        "face_slim": False,
        "smooth": round(config.smooth, 4),
        "brighten": round(config.brighten, 4),
        "tone": round(config.tone, 4),
        "duration_ms": duration_ms,
    }
    return output_rgb, stats
