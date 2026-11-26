#!/usr/bin/env python3
"""
Photobooth Operator Edition — Deterministic Image Pipeline & Collage Renderer.

Contract (Section 7.2 & 7.3 of implementation-plan.md):
- Invoked via argv with a path to a JSON manifest file (no shell interpolation).
- Diagnostic logs go to stderr; stdout is strictly reserved for the JSON result.
- Validates real JPEG inputs (<= 50 MiB, <= 60 MP).
- Preserves original files untouched; applies EXIF transpose on working copy.
- Converts valid ICC profiles to sRGB; if no ICC profile exists, assumes sRGB and flags for audit;
  if an ICC profile is declared in JPEG APP2 or info but corrupt, raises INVALID_ICC_PROFILE.
- Applies 33x33x33 .cube LUT via vectorized NumPy trilinear interpolation BEFORE cropping/resizing
  into collage slots (Original bypasses LUT).
- Center-crops each photo with LANCZOS into the pinned layout (1181x1748).
- Exports print.jpg at quality=95, subsampling=0, 300 DPI, embedded sRGB ICC, stripped EXIF/GPS.
- Derives preview.jpg from print.jpg (max edge 1000 px) without re-running LUT.
"""

from __future__ import annotations

import atexit
from dataclasses import replace
from contextlib import closing, suppress
from functools import lru_cache
import hashlib
import io
import json
import os
import secrets
import sys
import threading
import time
from pathlib import Path
from typing import Any, Callable, Dict, List, Optional, Tuple, Union

import numpy as np
from PIL import Image, ImageCms, ImageOps, UnidentifiedImageError

from beauty import resolve_beauty_config
from cube_lut import CubeLut, LutParseError, apply_cube_lut_rgb, parse_cube_file

PIPELINE_VERSION = "1.3.0"
DEFAULT_MAX_JPEG_BYTES = 50 * 1024 * 1024  # 50 MiB
DEFAULT_MAX_IMAGE_PIXELS = 60_000_000      # 60 MP

Image.MAX_IMAGE_PIXELS = DEFAULT_MAX_IMAGE_PIXELS
_PIL_LIMIT_LOCK = threading.Lock()


class PipelineError(Exception):
    def __init__(self, code: str, message: str) -> None:
        super().__init__(message)
        self.code = code
        self.message = message


def log_err(msg: str) -> None:
    sys.stderr.write(f"[render_pipeline] {msg}\n")
    sys.stderr.flush()


def sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(65536), b""):
            h.update(chunk)
    return h.hexdigest()


def fsync_directory(dir_path: Path) -> None:
    try:
        fd = os.open(str(dir_path), os.O_RDONLY)
        try:
            os.fsync(fd)
        finally:
            os.close(fd)
    except OSError:
        pass


def atomic_write_bytes(target_path: Path, data: bytes, *, require_existing_parent: bool = False) -> None:
    if require_existing_parent:
        if not target_path.parent.is_dir():
            raise PipelineError("RENDER_CANCELLED", f"Target directory removed before write: {target_path.parent.name}")
    else:
        target_path.parent.mkdir(parents=True, exist_ok=True)
    tmp_path = target_path.with_name(
        f".{target_path.name}.tmp.{os.getpid()}.{threading.get_ident()}.{secrets.token_hex(6)}"
    )
    try:
        try:
            with tmp_path.open("wb") as f:
                f.write(data)
                f.flush()
                os.fsync(f.fileno())
        except FileNotFoundError as exc:
            if require_existing_parent:
                raise PipelineError("RENDER_CANCELLED", f"Target directory removed during write: {target_path.parent.name}") from exc
            raise

        if require_existing_parent and not target_path.parent.is_dir():
            raise PipelineError("RENDER_CANCELLED", f"Target directory removed before commit: {target_path.parent.name}")

        for attempt in range(3):
            try:
                os.replace(str(tmp_path), str(target_path))
                break
            except PermissionError:
                if os.name == "nt" and attempt < 2:
                    time.sleep(0.005)
                    continue
                raise
            except FileNotFoundError as exc:
                if require_existing_parent:
                    raise PipelineError("RENDER_CANCELLED", f"Target directory removed before replace: {target_path.parent.name}") from exc
                raise
        fsync_directory(target_path.parent)
    finally:
        if tmp_path.exists():
            try:
                tmp_path.unlink()
            except OSError:
                pass


def has_raw_jpeg_icc_marker(raw_bytes: bytes) -> bool:
    """
    Scan JPEG markers before SOS (0xFFDA) to detect if an APP2 ICC_PROFILE segment is declared.
    """
    if len(raw_bytes) < 4 or raw_bytes[0:2] != b"\xff\xd8":
        return False
    idx = 2
    n = len(raw_bytes)
    while idx + 4 <= n:
        if raw_bytes[idx] != 0xFF:
            break
        while idx < n and raw_bytes[idx] == 0xFF:
            idx += 1
        if idx >= n:
            break
        marker = raw_bytes[idx]
        idx += 1
        if marker in (0xD8, 0xD9) or (0xD0 <= marker <= 0xD7) or marker == 0x01:
            continue
        if marker == 0xDA:  # Start of Scan
            break
        if idx + 2 > n:
            break
        seg_len = (raw_bytes[idx] << 8) | raw_bytes[idx + 1]
        if seg_len < 2 or idx + seg_len > n:
            break
        seg_payload = raw_bytes[idx + 2 : idx + seg_len]
        if marker == 0xE2 and seg_payload.startswith(b"ICC_PROFILE\x00"):
            return True
        idx += seg_len
    return False


def validate_and_load_jpeg(
    photo_path: str | Path,
    max_jpeg_bytes: int = DEFAULT_MAX_JPEG_BYTES,
    max_image_pixels: int = DEFAULT_MAX_IMAGE_PIXELS,
) -> Tuple[Image.Image, str, bytes]:
    path = Path(photo_path)
    if not path.is_file():
        raise PipelineError("MISSING_PHOTO_FILE", f"Photo file does not exist: {path.name}")

    byte_size = path.stat().st_size
    if byte_size <= 0:
        raise PipelineError("INVALID_JPEG", f"Empty photo file: {path.name}")
    if byte_size > max_jpeg_bytes:
        raise PipelineError(
            "IMAGE_TOO_LARGE",
            f"Photo file size {byte_size} exceeds limit {max_jpeg_bytes}",
        )

    raw_bytes = path.read_bytes()
    file_hash = hashlib.sha256(raw_bytes).hexdigest()

    # Check JPEG SOI magic bytes
    if len(raw_bytes) < 4 or raw_bytes[:3] != b"\xff\xd8\xff":
        raise PipelineError("INVALID_JPEG", f"File is not a valid JPEG: {path.name}")

    try:
        with _PIL_LIMIT_LOCK:
            old_max = Image.MAX_IMAGE_PIXELS
            Image.MAX_IMAGE_PIXELS = max_image_pixels
            try:
                img = Image.open(io.BytesIO(raw_bytes))
                # Canon mirrorless cameras (e.g., EOS R10) embed an MPF preview inside .jpg files,
                # which Pillow opens as MPO (a multi-picture JPEG container); accept both JPEG and MPO.
                if img.format not in ("JPEG", "MPO"):
                    raise PipelineError("INVALID_JPEG", f"Image format is {img.format}, expected JPEG")
                width, height = img.size
                if width <= 0 or height <= 0 or (width * height) > max_image_pixels:
                    raise PipelineError(
                        "IMAGE_PIXELS_EXCEEDED",
                        f"Image dimensions {width}x{height} exceed pixel limit {max_image_pixels}",
                    )
                img.load()
            finally:
                Image.MAX_IMAGE_PIXELS = old_max
    except PipelineError:
        raise
    except Image.DecompressionBombError as exc:
        raise PipelineError("IMAGE_PIXELS_EXCEEDED", str(exc)) from exc
    except (UnidentifiedImageError, OSError, SyntaxError, ValueError) as exc:
        raise PipelineError("CORRUPT_JPEG", f"Corrupt or truncated JPEG: {exc}") from exc

    return img, file_hash, raw_bytes


def normalize_orientation_and_srgb(
    img: Image.Image,
    raw_bytes: bytes,
    slot_num: int,
) -> Tuple[Image.Image, str]:
    """
    Apply EXIF orientation transpose and convert color profile to sRGB.
    Returns (rgb_image, icc_status) where icc_status is 'converted_to_srgb' or 'assumed_srgb'.
    Raises PipelineError('INVALID_ICC_PROFILE') if ICC is declared but corrupt.
    """
    icc_bytes = img.info.get("icc_profile")
    icc_declared_in_raw = has_raw_jpeg_icc_marker(raw_bytes)

    # Apply EXIF orientation on working copy
    transposed = ImageOps.exif_transpose(img)

    if icc_bytes is not None or icc_declared_in_raw:
        if not icc_bytes or len(icc_bytes) < 128:
            raise PipelineError(
                "INVALID_ICC_PROFILE",
                f"Slot {slot_num}: ICC profile declared but truncated or empty",
            )
        try:
            src_profile = ImageCms.ImageCmsProfile(io.BytesIO(icc_bytes))
            # Access profile name/info to verify header integrity
            _ = ImageCms.getProfileName(src_profile)
            srgb_profile = ImageCms.createProfile("sRGB")
            if transposed.mode not in ("RGB", "L", "CMYK"):
                transposed = transposed.convert("RGB")
            converted = ImageCms.profileToProfile(
                transposed,
                src_profile,
                srgb_profile,
                outputMode="RGB",
            )
            if converted is None:
                raise PipelineError(
                    "INVALID_ICC_PROFILE",
                    f"Slot {slot_num}: ICC conversion returned empty image",
                )
            return converted, "converted_to_srgb"
        except PipelineError:
            raise
        except Exception as exc:
            raise PipelineError(
                "INVALID_ICC_PROFILE",
                f"Slot {slot_num}: Corrupt or unsupported ICC profile ({exc})",
            ) from exc

    if transposed.mode != "RGB":
        transposed = transposed.convert("RGB")
    return transposed, "assumed_srgb"


def center_crop_and_resize(img: Image.Image, target_w: int, target_h: int) -> Image.Image:
    """
    Center-crop an RGB PIL Image to the target aspect ratio and resize with LANCZOS.
    """
    src_w, src_h = img.size
    target_ratio = target_w / float(target_h)
    src_ratio = src_w / float(src_h)

    if abs(src_ratio - target_ratio) > 1e-6:
        if src_ratio > target_ratio:
            # Source is wider than target slot: crop width
            new_w = max(1, int(round(src_h * target_ratio)))
            left = (src_w - new_w) // 2
            img = img.crop((left, 0, left + new_w, src_h))
        else:
            # Source is taller than target slot: crop height
            new_h = max(1, int(round(src_w / target_ratio)))
            top = (src_h - new_h) // 2
            img = img.crop((0, top, src_w, top + new_h))

    if img.size != (target_w, target_h):
        img = img.resize((target_w, target_h), resample=Image.Resampling.LANCZOS)
    return img


@lru_cache(maxsize=1)
def get_srgb_icc_bytes() -> bytes:
    raw = bytearray(ImageCms.ImageCmsProfile(ImageCms.createProfile("sRGB")).tobytes())
    # Normalize ICC header creation date/time (bytes 24..36: 2026-01-01 00:00:00 UTC)
    # so output JPEG bytes and SHA-256 are 100% deterministic across invocations.
    raw[24:36] = b"\x07\xea\x00\x01\x00\x01\x00\x00\x00\x00\x00\x00"
    return bytes(raw)


@lru_cache(maxsize=16)
def _parse_cube_cached_by_hash(
    resolved_path: str,
    file_sha256: str,
    expected_size: int,
    require_unit_domain: bool,
) -> CubeLut:
    lut = parse_cube_file(resolved_path, expected_size=expected_size, require_unit_domain=require_unit_domain)
    if lut.sha256 != file_sha256:
        raise LutParseError("LUT file changed while loading")
    return lut


def load_cube_lut_cached(
    lut_path: str | Path,
    expected_size: int = 33,
    require_unit_domain: bool = True,
) -> CubeLut:
    path = Path(lut_path)
    if not path.is_file():
        raise LutParseError(f"LUT file does not exist: {path}")
    if path.stat().st_size > 5 * 1024 * 1024:
        return parse_cube_file(path, expected_size=expected_size, require_unit_domain=require_unit_domain)
    digest = sha256_file(path)
    return _parse_cube_cached_by_hash(str(path.resolve()), digest, expected_size, require_unit_domain)


def encode_jpeg_bytes(
    img: Image.Image,
    quality: int,
    subsampling: int,
    dpi: int,
    icc_bytes: bytes,
) -> bytes:
    buf = io.BytesIO()
    # Do not pass exif=... so EXIF/GPS metadata is stripped; preserve sRGB ICC and DPI
    img.save(
        buf,
        format="JPEG",
        quality=quality,
        subsampling=subsampling,
        dpi=(dpi, dpi),
        icc_profile=icc_bytes,
        optimize=False,
    )
    return buf.getvalue()


def compute_input_signature(
    photo_hashes: List[str],
    lut_id: str,
    lut_version: str,
    lut_hash: str,
    template_id: str,
    template_version: str,
    encoder_spec: Dict[str, Any],
    template_snapshot: Optional[Dict[str, Any]] = None,
    lut_intensity: int = 100,
    beauty_spec: Optional[Dict[str, Any]] = None,
) -> str:
    payload = {
        "photo_hashes": photo_hashes,
        "lut_id": lut_id,
        "lut_version": lut_version,
        "lut_hash": lut_hash,
        "lut_intensity": lut_intensity,
        "template_id": template_id,
        "template_version": template_version,
        "encoder": {
            "quality": encoder_spec.get("quality", 95),
            "subsampling": encoder_spec.get("subsampling", 0),
            "dpi": encoder_spec.get("dpi", 300),
        },
        "pipeline_version": PIPELINE_VERSION,
    }
    if template_snapshot is not None:
        payload["template_snapshot"] = {k: v for k, v in template_snapshot.items() if k not in ("overlay_path", "background_path")}
    if beauty_spec is not None and beauty_spec.get("preset") != "off":
        payload["beauty"] = beauty_spec
    canonical = json.dumps(payload, sort_keys=True, separators=(",", ":")).encode("utf-8")
    return hashlib.sha256(canonical).hexdigest()


def grade_rgb_image(image: Image.Image, lut: Optional[CubeLut], intensity: int = 100) -> Image.Image:
    if type(intensity) is not int or not 0 <= intensity <= 100:
        raise PipelineError("INVALID_LUT", "LUT intensity must be an integer from 0 to 100")
    if lut is None or intensity == 0:
        return image
    source = np.asarray(image, dtype=np.uint8)
    graded = apply_cube_lut_rgb(source, lut)
    if intensity == 100:
        return Image.fromarray(graded)
    amount = intensity / 100.0
    blended = np.rint(source.astype(np.float32) * (1.0 - amount) + graded.astype(np.float32) * amount)
    return Image.fromarray(np.clip(blended, 0, 255).astype(np.uint8))



_DAEMON_MODE: bool = False
_CACHE_LOCK = threading.RLock()
_WARM_BEAUTY_LOCK = threading.Lock()
_WARM_BEAUTY_PROCESSOR: Optional[Any] = None
_WARM_BEAUTY_MODEL_KEY: Optional[Tuple[Optional[str], Optional[str], int]] = None
# folder_str -> {entry_key -> (pil_rgb_image, icc_status, stats_dict, touched_ns)}
_BEAUTY_RAM_CACHE: Dict[str, Dict[str, Tuple[Image.Image, str, Dict[str, Any], int]]] = {}
_MAX_RAM_SESSION_FOLDERS = 2
_MAX_RAM_ENTRIES_PER_SESSION = 12


def close_warm_beauty_processor() -> None:
    global _WARM_BEAUTY_PROCESSOR, _WARM_BEAUTY_MODEL_KEY
    with _WARM_BEAUTY_LOCK:
        if _WARM_BEAUTY_PROCESSOR is not None:
            with suppress(Exception):
                _WARM_BEAUTY_PROCESSOR.close()
            _WARM_BEAUTY_PROCESSOR = None
            _WARM_BEAUTY_MODEL_KEY = None


atexit.register(close_warm_beauty_processor)


def enable_daemon_runtime(enabled: bool = True) -> None:
    """Enable warm MediaPipe model reuse and in-RAM Beauty cache inside image_daemon.py."""
    global _DAEMON_MODE
    with _CACHE_LOCK:
        _DAEMON_MODE = enabled
        if not enabled:
            _BEAUTY_RAM_CACHE.clear()
    if not enabled:
        close_warm_beauty_processor()


def get_ram_cache_stats() -> Dict[str, int]:
    with _CACHE_LOCK:
        _prune_ram_beauty_sessions_unlocked()
        return {
            "sessions": len(_BEAUTY_RAM_CACHE),
            "entries": sum(len(items) for items in _BEAUTY_RAM_CACHE.values()),
        }


def _prune_ram_beauty_sessions_unlocked() -> None:
    stale_folders = [f for f in list(_BEAUTY_RAM_CACHE.keys()) if not Path(f).is_dir()]
    for folder_key in stale_folders:
        _BEAUTY_RAM_CACHE.pop(folder_key, None)
    while len(_BEAUTY_RAM_CACHE) > _MAX_RAM_SESSION_FOLDERS:
        oldest_folder = min(
            _BEAUTY_RAM_CACHE.keys(),
            key=lambda f: max((entry[3] for entry in _BEAUTY_RAM_CACHE[f].values()), default=0),
        )
        _BEAUTY_RAM_CACHE.pop(oldest_folder, None)


def _store_ram_beauty_entry(folder: Path, key: str, image: Image.Image, icc_status: str, stats: Dict[str, Any]) -> None:
    folder_key = str(folder.resolve())
    with _CACHE_LOCK:
        _prune_ram_beauty_sessions_unlocked()
        bucket = _BEAUTY_RAM_CACHE.setdefault(folder_key, {})
        bucket[key] = (image.copy(), icc_status, dict(stats), time.monotonic_ns())
        if len(bucket) > _MAX_RAM_ENTRIES_PER_SESSION:
            sorted_keys = sorted(bucket.keys(), key=lambda k: bucket[k][3], reverse=True)
            for drop_key in sorted_keys[_MAX_RAM_ENTRIES_PER_SESSION:]:
                bucket.pop(drop_key, None)
        _prune_ram_beauty_sessions_unlocked()


def _load_ram_beauty_entry(folder: Path, key: str) -> Optional[Tuple[Image.Image, str, Dict[str, Any]]]:
    folder_key = str(folder.resolve())
    with _CACHE_LOCK:
        _prune_ram_beauty_sessions_unlocked()
        bucket = _BEAUTY_RAM_CACHE.get(folder_key)
        if not bucket or key not in bucket:
            return None
        img, icc_status, stats, _ = bucket[key]
        bucket[key] = (img, icc_status, stats, time.monotonic_ns())
        return img.copy(), icc_status, dict(stats)


def _process_with_warm_beauty(image: Image.Image, cfg: Any) -> Tuple[Image.Image, Dict[str, Any]]:
    global _WARM_BEAUTY_PROCESSOR, _WARM_BEAUTY_MODEL_KEY
    from beauty import BeautyProcessor

    model_key = (cfg.face_model_path, cfg.segmenter_model_path, cfg.max_faces)
    with _WARM_BEAUTY_LOCK:
        if _WARM_BEAUTY_PROCESSOR is None or _WARM_BEAUTY_MODEL_KEY != model_key:
            if _WARM_BEAUTY_PROCESSOR is not None:
                _WARM_BEAUTY_PROCESSOR.close()
            _WARM_BEAUTY_PROCESSOR = BeautyProcessor(cfg)
            _WARM_BEAUTY_MODEL_KEY = model_key
        out_img, stats = _WARM_BEAUTY_PROCESSOR.process(image, cfg)
        return out_img, stats


def _check_execution_active(
    deadline_mono: Optional[float] = None,
    cancel_check: Optional[Callable[[], bool]] = None,
    required_dir: Optional[Path] = None,
) -> None:
    if deadline_mono is not None and time.monotonic() >= deadline_mono:
        raise PipelineError("RENDER_TIMEOUT", "Render deadline exceeded")
    if cancel_check is not None and cancel_check():
        raise PipelineError("RENDER_TIMEOUT", "Client disconnected before completion")
    if required_dir is not None and not required_dir.is_dir():
        raise PipelineError("RENDER_CANCELLED", "Render output directory removed")


def beauty_config_for(manifest):
    preset = manifest.get("beauty_preset")
    raw_overrides = manifest.get("beauty_config")
    runtime_env = manifest.get("runtime_env")
    merged_overrides: Dict[str, Any] = {}
    if isinstance(runtime_env, dict):
        if runtime_env.get("beauty_engine"):
            merged_overrides["engine"] = runtime_env["beauty_engine"]
        if runtime_env.get("lkmagic_bridge_url"):
            merged_overrides["lkmagic_url"] = runtime_env["lkmagic_bridge_url"]
        if "lkmagic_executable" in runtime_env and runtime_env["lkmagic_executable"]:
            merged_overrides["lkmagic_executable"] = str(runtime_env["lkmagic_executable"])
        if "lkmagic_auto_start" in runtime_env:
            merged_overrides["auto_start_lkmagic"] = bool(runtime_env["lkmagic_auto_start"])
    if isinstance(raw_overrides, dict):
        merged_overrides.update(raw_overrides)
    if preset is None and raw_overrides is None:
        return resolve_beauty_config("off")
    return resolve_beauty_config(preset, merged_overrides if merged_overrides else None)


def load_source(manifest, photo):
    path = Path(photo["path"])
    img, digest, raw = validate_and_load_jpeg(
        path,
        max_jpeg_bytes=int(manifest.get("max_jpeg_bytes", DEFAULT_MAX_JPEG_BYTES)),
        max_image_pixels=int(manifest.get("max_image_pixels", DEFAULT_MAX_IMAGE_PIXELS)),
    )
    if photo.get("sha256") and digest != photo["sha256"]:
        raise PipelineError("SOURCE_HASH_MISMATCH", f"Slot {photo['slot']} SHA-256 mismatch")
    normalized, icc = normalize_orientation_and_srgb(img, raw, int(photo["slot"]))
    return normalized, digest, icc


def write_cache_json(path, data, *, require_existing_parent: bool = False):
    atomic_write_bytes(path, json.dumps(data).encode("utf-8"), require_existing_parent=require_existing_parent)


def read_cache_json(path):
    try:
        return json.loads(path.read_bytes())
    except (OSError, ValueError):
        return None


def cache_key(value):
    return hashlib.sha256(json.dumps(value, sort_keys=True).encode("utf-8")).hexdigest()


def prepared_beauty(
    manifest,
    cfg,
    photos,
    *,
    deadline_mono: Optional[float] = None,
    cancel_check: Optional[Callable[[], bool]] = None,
    required_output_dir: Optional[Path] = None,
):
    """Prepare one engine-consistent batch and reuse lossless per-photo results."""
    _check_execution_active(deadline_mono, cancel_check, required_output_dir)
    folder = Path(manifest["beauty_cache_dir"])
    folder.mkdir(parents=True, exist_ok=True)
    settings = [PIPELINE_VERSION, cfg.to_dict(), cfg.lkmagic_url, "original-resolution"]
    hashes = [p["sha256"] for p in photos]
    settings_key = cache_key(settings)
    batch = cache_key([settings_key, hashes])
    marker = folder / "batch.json"
    cached_batch = read_cache_json(marker)
    native_retry = manifest.get("retry_lkmagic", False)
    use_fallback = (
        cfg.engine == "auto" and not native_retry and isinstance(cached_batch, dict)
        and cached_batch.get("settings") == settings_key and cached_batch.get("fallback")
    )
    engines = ["mediapipe"] if cfg.engine == "mediapipe" or use_fallback else ["lkmagic"]
    if cfg.engine == "auto" and engines == ["lkmagic"]:
        engines.append("mediapipe")

    failure = cached_batch.get("fallback") if use_fallback else None
    for engine in engines:
        _check_execution_active(deadline_mono, cancel_check, required_output_dir)
        results: Dict[int, Tuple[Union[Path, Image.Image], str, Dict[str, Any]]] = {}
        report_beauty_progress(manifest, folder, 0, len(photos))
        try:
            from beauty import BeautyProcessor
            from beauty.lkmagic_bridge import check_lkmagic_health
            actual = "lkmagic_native" if engine == "lkmagic" else engine
            engine_cfg = replace(cfg, engine=engine, auto_start_lkmagic=False, lkmagic_timeout_sec=25)
            local_processor = None if _DAEMON_MODE else BeautyProcessor(engine_cfg)
            try:
                for photo in photos:
                    _check_execution_active(deadline_mono, cancel_check, required_output_dir)
                    if not still_wanted(manifest, hashes):
                        return None, None, "Đã thay đổi lựa chọn Beauty."
                    slot = int(photo["slot"])
                    key = cache_key([settings, photo["sha256"], actual])
                    meta = folder / f"{key}.json"
                    pixels = folder / f"{key}.png"
                    hit = False
                    pixels_source: Union[Path, Image.Image] = pixels
                    entry_icc = "assumed_srgb"
                    entry_stats: Dict[str, Any] = {}

                    if _DAEMON_MODE:
                        ram_hit = _load_ram_beauty_entry(folder, key)
                        if ram_hit is not None:
                            pixels_source, entry_icc, entry_stats = ram_hit
                            hit = True

                    if not hit:
                        entry = read_cache_json(meta)
                        if isinstance(entry, dict):
                            try:
                                if sha256_file(pixels) == entry["pixels_sha256"]:
                                    hit = True
                                    pixels_source = pixels
                                    entry_icc = entry["icc_status"]
                                    entry_stats = entry["stats"]
                                    os.utime(meta)
                            except (KeyError, OSError):
                                pass

                    if not hit:
                        photo_cfg = engine_cfg
                        if deadline_mono is not None:
                            remaining = deadline_mono - time.monotonic()
                            if remaining <= 0:
                                raise PipelineError("RENDER_TIMEOUT", "Render deadline exceeded")
                            photo_cfg = replace(engine_cfg, lkmagic_timeout_sec=min(25.0, remaining))
                        if engine == "lkmagic" and not results:
                            status = check_lkmagic_health(
                                cfg.lkmagic_url,
                                timeout=min(0.8, max(0.1, photo_cfg.lkmagic_timeout_sec)),
                            )
                            if not (status.get("ok") and status.get("ready")):
                                raise RuntimeError("LK Magic chưa sẵn sàng.")
                        normalized, digest, icc = load_source(manifest, photo)
                        _check_execution_active(deadline_mono, cancel_check, required_output_dir)
                        if _DAEMON_MODE:
                            adjusted, stats = _process_with_warm_beauty(normalized, photo_cfg)
                        else:
                            assert local_processor is not None
                            adjusted, stats = local_processor.process(normalized, photo_cfg)
                        _check_execution_active(deadline_mono, cancel_check, required_output_dir)
                        if stats["status"] == "error_fallback":
                            raise RuntimeError(stats.get("error") or "Beauty thất bại.")
                        if sha256_file(Path(photo["path"])) != digest:
                            raise PipelineError("ORIGINAL_MUTATED", f"Original photo in slot {slot} was modified")
                        entry_icc = icc
                        entry_stats = stats
                        if _DAEMON_MODE:
                            if folder.is_dir():
                                _store_ram_beauty_entry(folder, key, adjusted, icc, stats)
                            pixels_source = adjusted
                        else:
                            pixels_tmp = pixels.with_suffix(".tmp")
                            try:
                                adjusted.save(pixels_tmp, format="PNG", compress_level=1)
                                os.replace(pixels_tmp, pixels)
                            finally:
                                pixels_tmp.unlink(missing_ok=True)
                            entry = {"stats": stats, "icc_status": icc, "pixels_sha256": sha256_file(pixels)}
                            write_cache_json(meta, entry)
                            os.utime(meta)
                            pixels_source = pixels

                    results[slot] = (pixels_source, entry_icc, {
                        **entry_stats, "engine": actual, "cache_hit": hit,
                        "duration_ms": 0 if hit else entry_stats["duration_ms"],
                    })
                    report_beauty_progress(manifest, folder, len(results), len(photos))
            finally:
                if local_processor is not None:
                    local_processor.close()
            _check_execution_active(deadline_mono, cancel_check, required_output_dir)
            fallback = failure if engine == "mediapipe" else None
            if folder.is_dir():
                with suppress(PipelineError):
                    write_cache_json(marker, {"batch": batch, "settings": settings_key, "fallback": fallback}, require_existing_parent=True)
                prune_beauty_cache(folder)
            return results, fallback, None
        except (ImportError, OSError, RuntimeError, ValueError) as exc:
            _check_execution_active(deadline_mono, cancel_check, required_output_dir)
            failure = f"{engine}: {exc}"[:360]
            log_err(failure)
            if native_retry:
                break

    if folder.is_dir():
        prune_beauty_cache(folder)
    return None, None, failure


def report_beauty_progress(manifest, folder, done, total):
    if (key := manifest.get("beauty_progress_key")) and folder.is_dir():
        with suppress(OSError, PipelineError):
            write_cache_json(
                folder / "progress.json",
                {"key": key, "done": done, "total": total},
                require_existing_parent=True,
            )


def still_wanted(manifest, hashes):
    """Stop pre-retouch between photos if the operator changed the snapshot."""
    if "beauty_session_id" not in manifest:
        return True
    import sqlite3
    with closing(sqlite3.connect(Path(manifest["database_path"]).resolve().as_uri() + "?mode=ro", uri=True)) as db:
        row = db.execute("SELECT state, ui_step, deleted_at, beauty_preset, beauty_smooth, "
                         "beauty_brighten, beauty_tone FROM sessions WHERE id=?",
                         (manifest["beauty_session_id"],)).fetchone()
        if not row or row[2] or row[:2] not in (("CAPTURED", 4), ("REVIEW", 4), ("RENDERING", 5)):
            return False
        values = manifest["beauty_config"]
        if row[3:] != (manifest["beauty_preset"], values["smooth"], values["brighten"], values["tone"]):
            return False
        sources = db.execute("SELECT p.sha256 FROM session_slots ss JOIN photos p "
                             "ON p.id=ss.current_photo_id WHERE ss.session_id=? ORDER BY ss.slot",
                             (manifest["beauty_session_id"],))
        return [source[0] for source in sources] == hashes


def prune_beauty_cache(folder):
    entries = sorted(folder.glob("*.json"), key=lambda p: p.stat().st_mtime_ns, reverse=True)
    for entry in [p for p in entries if p.stem not in ("batch", "progress")][12:]:
        entry.unlink(missing_ok=True)
        entry.with_suffix(".png").unlink(missing_ok=True)


def run_beauty_task(
    manifest,
    *,
    deadline_mono: Optional[float] = None,
    cancel_check: Optional[Callable[[], bool]] = None,
):
    _check_execution_active(deadline_mono, cancel_check)
    cfg = beauty_config_for(manifest)
    if manifest.get("task") == "check_lkmagic":
        import tempfile
        from beauty.lkmagic_bridge import retouch_via_lkmagic
        timeout_sec = 25.0
        if deadline_mono is not None:
            timeout_sec = min(25.0, max(0.1, deadline_mono - time.monotonic()))
        with tempfile.TemporaryDirectory(prefix="pb-beauty-check-") as tmp:
            image, _, _ = load_source(manifest, manifest["photos"][0])
            _, audit = retouch_via_lkmagic(
                np.asarray(image), replace(cfg, auto_start_lkmagic=False, lkmagic_timeout_sec=timeout_sec), Path(tmp)
            )
        return {"status": "ok", "audit": audit}
    photos = manifest["photos"]
    if not cfg.is_active() or not still_wanted(manifest, [p["sha256"] for p in photos]):
        return {"status": "ok", "skipped": True}
    results, fallback, error = prepared_beauty(
        manifest,
        cfg,
        photos,
        deadline_mono=deadline_mono,
        cancel_check=cancel_check,
    )
    return {"status": "ok", "prepared": bool(results), "fallback": fallback, "error": error}


def run_render_from_manifest(
    manifest: Dict[str, Any],
    *,
    deadline_mono: Optional[float] = None,
    cancel_check: Optional[Callable[[], bool]] = None,
) -> Dict[str, Any]:
    start_ns = time.monotonic_ns()
    _check_execution_active(deadline_mono, cancel_check)

    photos_input = manifest.get("photos")
    if not isinstance(photos_input, list) or not 1 <= len(photos_input) <= 4:
        raise PipelineError("MISSING_PHOTOS", "Manifest must provide 1 to 4 slot photos")

    template = manifest.get("template")
    if not isinstance(template, dict):
        template_path = manifest.get("template_path")
        if not template_path or not Path(template_path).is_file():
            raise PipelineError("INVALID_TEMPLATE", f"Template file not found: {template_path}")
        template = json.loads(Path(template_path).read_text(encoding="utf-8"))
    template_id = str(template.get("id", "vertical-three-v1"))
    template_version = str(template.get("version", "1.0.0"))

    lut_id = str(manifest.get("lut_id", "original"))
    lut_version = str(manifest.get("lut_version", "1.0.0"))
    lut_path = manifest.get("lut_path")
    lut_intensity = manifest.get("lut_intensity", 100)
    if type(lut_intensity) is not int or not 0 <= lut_intensity <= 100:
        raise PipelineError("INVALID_LUT", "LUT intensity must be an integer from 0 to 100")

    max_jpeg_bytes = int(manifest.get("max_jpeg_bytes", DEFAULT_MAX_JPEG_BYTES))
    max_image_pixels = int(manifest.get("max_image_pixels", DEFAULT_MAX_IMAGE_PIXELS))

    output_print_path = Path(manifest["output_print_path"])
    output_preview_path = Path(manifest["output_preview_path"])
    output_parent_existed_at_start = output_print_path.parent.is_dir()
    required_output_dir = output_print_path.parent if output_parent_existed_at_start else None

    # Load LUT if not original
    cube_lut: Optional[CubeLut] = None
    lut_hash = "identity-original"
    if lut_id != "original":
        if not lut_path:
            raise PipelineError("INVALID_LUT", f"Missing lut_path for preset '{lut_id}'")
        try:
            cube_lut = load_cube_lut_cached(lut_path, expected_size=33, require_unit_domain=True)
            lut_hash = cube_lut.sha256
        except LutParseError as exc:
            raise PipelineError("INVALID_LUT", f"LUT parse error for '{lut_id}': {exc}") from exc

    if manifest.get("lut_sha256") and manifest["lut_sha256"] != lut_hash:
        raise PipelineError("INTEGRITY_ERROR", "LUT resource hash mismatch")

    # Sort the contiguous slot definitions of the pinned layout.
    slots_spec = sorted(template["slots"], key=lambda s: int(s["slot"]))
    expected_slots = list(range(1, len(slots_spec) + 1))
    if not 1 <= len(slots_spec) <= 4 or [int(s["slot"]) for s in slots_spec] != expected_slots:
        raise PipelineError("INVALID_TEMPLATE", "Template must define contiguous slots 1..N, N <= 4")

    canvas_w = int(template["canvas"]["width"])
    canvas_h = int(template["canvas"]["height"])
    bg_rgb = tuple(int(c) for c in template["canvas"].get("background_rgb", [255, 255, 255]))

    if (canvas_w, canvas_h) != (1181, 1748):
        raise PipelineError("INVALID_TEMPLATE", "Canvas must be 1181x1748")
    for slot in slots_spec:
        x, y, w, h = (int(slot[k]) for k in ("x", "y", "width", "height"))
        if min(x, y) < 0 or min(w, h) < 1 or x+w > canvas_w or y+h > canvas_h:
            raise PipelineError("INVALID_TEMPLATE", "Slot is outside the canvas")
    for resource, expected_hash in template.get("resource_hashes", {}).items():
        if resource == "preview.jpg":
            continue
        path = template.get("overlay_path" if resource == "overlay.png" else "background_path")
        if not path or sha256_file(Path(path)) != expected_hash:
            raise PipelineError("INTEGRITY_ERROR", "Template resource hash mismatch")
    canvas = Image.new("RGB", (canvas_w, canvas_h), color=bg_rgb)
    if template.get("background_path"):
        with Image.open(template["background_path"]) as background:
            background.load()
            if background.size != (canvas_w, canvas_h):
                raise PipelineError("INVALID_TEMPLATE", "Background dimensions mismatch")
            canvas = Image.alpha_composite(canvas.convert("RGBA"), background.convert("RGBA")).convert("RGB")

    # Map input photos by layout slot.
    photos_by_slot: Dict[int, Dict[str, Any]] = {}
    for item in photos_input:
        slot_num = int(item["slot"])
        photos_by_slot[slot_num] = item

    if len(photos_input) != len(expected_slots) or sorted(photos_by_slot.keys()) != expected_slots:
        raise PipelineError("MISSING_PHOTOS", "Photos must match the layout slots exactly")

    beauty_cfg = beauty_config_for(manifest)
    prepared = fallback_reason = beauty_error = None
    use_cache = beauty_cfg.is_active() and bool(manifest.get("beauty_cache_dir"))
    if use_cache:
        prepared, fallback_reason, beauty_error = prepared_beauty(
            manifest,
            beauty_cfg,
            [photos_by_slot[slot] for slot in expected_slots],
            deadline_mono=deadline_mono,
            cancel_check=cancel_check,
            required_output_dir=required_output_dir,
        )
        if beauty_error and manifest.get("retry_lkmagic"):
            raise PipelineError("LK_MAGIC_RETOUCH_FAILED", beauty_error)

    beauty_processor = None
    beauty_load_error = beauty_error
    if beauty_cfg.is_active() and not use_cache and not _DAEMON_MODE:
        try:
            from beauty import BeautyProcessor
            beauty_processor = BeautyProcessor(beauty_cfg)
        except (ImportError, OSError) as exc:
            beauty_load_error = str(exc)
            log_err(f"Beauty unavailable, keeping source image: {exc}")
    beauty_audit: List[Dict[str, Any]] = []

    photo_hashes: List[str] = []
    icc_audit: List[Dict[str, Any]] = []

    try:
        for slot_spec in slots_spec:
            _check_execution_active(deadline_mono, cancel_check, required_output_dir)
            slot_num = int(slot_spec["slot"])
            slot_info = photos_by_slot[slot_num]
            photo_file = Path(slot_info["path"])

            if prepared is not None:
                pixels, icc_status, b_stats = prepared[slot_num]
                file_hash = sha256_file(photo_file)
                if file_hash != slot_info["sha256"]:
                    raise PipelineError("SOURCE_HASH_MISMATCH", f"Slot {slot_num} SHA-256 mismatch before render")
                if isinstance(pixels, Image.Image):
                    retouched_img = pixels.copy()
                else:
                    with Image.open(pixels) as cached:
                        cached.load()
                        retouched_img = cached.copy()
                beauty_audit.append({"slot": slot_num, **b_stats})
            else:
                normalized_img, file_hash, icc_status = load_source(manifest, slot_info)
                _check_execution_active(deadline_mono, cancel_check, required_output_dir)
                slot_beauty_cfg = beauty_cfg
                if deadline_mono is not None and beauty_cfg.is_active():
                    remaining = deadline_mono - time.monotonic()
                    if remaining <= 0:
                        raise PipelineError("RENDER_TIMEOUT", "Render deadline exceeded")
                    slot_beauty_cfg = replace(beauty_cfg, lkmagic_timeout_sec=min(beauty_cfg.lkmagic_timeout_sec, remaining))
                if slot_beauty_cfg.is_active() and _DAEMON_MODE and beauty_load_error is None:
                    try:
                        retouched_img, b_stats = _process_with_warm_beauty(normalized_img, slot_beauty_cfg)
                        _check_execution_active(deadline_mono, cancel_check, required_output_dir)
                        beauty_audit.append({"slot": slot_num, **b_stats})
                    except (ImportError, OSError) as exc:
                        _check_execution_active(deadline_mono, cancel_check, required_output_dir)
                        beauty_load_error = str(exc)
                        retouched_img = normalized_img
                        beauty_audit.append({
                            "slot": slot_num,
                            "status": "error_fallback",
                            "preset": beauty_cfg.preset,
                            "error": beauty_load_error,
                        })
                elif beauty_processor is not None:
                    retouched_img, b_stats = beauty_processor.process(normalized_img, slot_beauty_cfg)
                    _check_execution_active(deadline_mono, cancel_check, required_output_dir)
                    beauty_audit.append({"slot": slot_num, **b_stats})
                else:
                    retouched_img = normalized_img
                    beauty_audit.append({
                        "slot": slot_num,
                        "status": "error_fallback" if beauty_load_error else "bypassed",
                        "preset": beauty_cfg.preset,
                        "error": beauty_load_error,
                    })

            photo_hashes.append(file_hash)
            icc_audit.append({"slot": slot_num, "icc_status": icc_status})

            # Step 5: Apply 3D LUT BEFORE cropping/resizing into the collage slot
            processed_img = grade_rgb_image(retouched_img, cube_lut, lut_intensity)

            # Step 6: Center-crop and resize into slot dimensions
            slot_img = center_crop_and_resize(
                processed_img,
                int(slot_spec["width"]),
                int(slot_spec["height"]),
            )

            # Step 7: Paste onto canvas at slot coordinates
            canvas.paste(slot_img, (int(slot_spec["x"]), int(slot_spec["y"])))

            # Verify original file was not mutated
            if sha256_file(photo_file) != file_hash:
                raise PipelineError("ORIGINAL_MUTATED", f"Original photo in slot {slot_num} was modified")
    finally:
        if beauty_processor is not None:
            beauty_processor.close()

    if template.get("overlay_path"):
        with Image.open(template["overlay_path"]) as overlay:
            overlay.load()
            if overlay.size != (canvas_w, canvas_h) or overlay.mode != "RGBA":
                raise PipelineError("INVALID_TEMPLATE", "Overlay must be RGBA 1181x1748")
            canvas = Image.alpha_composite(canvas.convert("RGBA"), overlay).convert("RGB")

    if template.get("text_slots") and any(template.get("frame_text", {}).values()):
        from frame_text import frame_layer

        lettering = frame_layer((canvas_w, canvas_h), template["text_slots"], template["frame_text"])
        canvas = Image.alpha_composite(canvas.convert("RGBA"), lettering).convert("RGB")

    encoder_spec = template.get("encoder", {})
    quality = int(encoder_spec.get("quality", 95))
    subsampling = int(encoder_spec.get("subsampling", 0))
    dpi = int(encoder_spec.get("dpi", 300))
    preview_max_edge = int(encoder_spec.get("preview_max_edge_px", 1000))
    preview_quality = int(encoder_spec.get("preview_quality", 90))

    srgb_icc_bytes = get_srgb_icc_bytes()

    # Step 8: Export print.jpg (1181x1748, Q95, subsampling 0, 300 DPI, sRGB)
    _check_execution_active(deadline_mono, cancel_check, required_output_dir)
    print_bytes = encode_jpeg_bytes(
        canvas,
        quality=quality,
        subsampling=subsampling,
        dpi=dpi,
        icc_bytes=srgb_icc_bytes,
    )
    print_sha256 = hashlib.sha256(print_bytes).hexdigest()
    _check_execution_active(deadline_mono, cancel_check, required_output_dir)
    atomic_write_bytes(output_print_path, print_bytes, require_existing_parent=output_parent_existed_at_start)

    # Step 9: Derive preview.jpg from print.jpg without re-running LUT
    print_reloaded = Image.open(io.BytesIO(print_bytes))
    print_reloaded.load()
    pw, ph = print_reloaded.size
    scale = min(1.0, preview_max_edge / float(max(pw, ph)))
    preview_w = max(1, int(round(pw * scale)))
    preview_h = max(1, int(round(ph * scale)))
    preview_img = print_reloaded.resize((preview_w, preview_h), resample=Image.Resampling.LANCZOS)

    preview_bytes = encode_jpeg_bytes(
        preview_img,
        quality=preview_quality,
        subsampling=0,
        dpi=dpi,
        icc_bytes=srgb_icc_bytes,
    )
    preview_sha256 = hashlib.sha256(preview_bytes).hexdigest()
    _check_execution_active(deadline_mono, cancel_check, required_output_dir)
    atomic_write_bytes(
        output_preview_path,
        preview_bytes,
        require_existing_parent=output_parent_existed_at_start and output_preview_path.parent == output_print_path.parent,
    )

    input_signature = compute_input_signature(
        photo_hashes=photo_hashes,
        lut_id=lut_id,
        lut_version=lut_version,
        lut_hash=lut_hash,
        template_id=template_id,
        template_version=template_version,
        encoder_spec=encoder_spec,
        template_snapshot=template,
        lut_intensity=lut_intensity,
        beauty_spec=beauty_cfg.to_dict(),
    )

    duration_ms = int((time.monotonic_ns() - start_ns) // 1_000_000)

    return {
        "status": "ok",
        "image_path": str(output_print_path),
        "preview_path": str(output_preview_path),
        "width": canvas_w,
        "height": canvas_h,
        "preview_width": preview_w,
        "preview_height": preview_h,
        "sha256": print_sha256,
        "preview_sha256": preview_sha256,
        "bytes": len(print_bytes),
        "preview_bytes": len(preview_bytes),
        "lut_id": lut_id,
        "lut_version": lut_version,
        "lut_sha256": lut_hash,
        "template_id": template_id,
        "template_version": template_version,
        "beauty_preset": beauty_cfg.preset,
        "beauty_config": beauty_cfg.to_dict(),
        "beauty_audit": beauty_audit,
        "beauty_fallback": fallback_reason,
        "pipeline_version": PIPELINE_VERSION,
        "input_signature": input_signature,
        "source_photo_hashes": photo_hashes,
        "icc_audit": icc_audit,
        "duration_ms": duration_ms,
    }


def validate_jpeg_payload(
    jpeg_path: str | Path,
    max_bytes: int = DEFAULT_MAX_JPEG_BYTES,
    max_pixels: int = DEFAULT_MAX_IMAGE_PIXELS,
) -> Dict[str, Any]:
    img, file_hash, raw_bytes = validate_and_load_jpeg(jpeg_path, max_bytes, max_pixels)
    exif = img.getexif()
    orientation = int(exif.get(274, 1)) if exif else 1
    normalized, icc_status = normalize_orientation_and_srgb(img, raw_bytes, 1)
    w, h = normalized.size
    return {
        "status": "ok",
        "sha256": file_hash,
        "width": w,
        "height": h,
        "bytes": len(raw_bytes),
        "exif_orientation": orientation,
        "icc_status": icc_status,
    }


def main(argv: List[str]) -> int:
    if len(argv) < 2:
        log_err("Usage: render_pipeline.py <manifest.json> | --validate-jpeg <path>")
        return 2

    if argv[1] == "--validate-jpeg":
        if len(argv) < 3:
            log_err("Missing JPEG path")
            return 2
        max_bytes = int(argv[3]) if len(argv) >= 4 else DEFAULT_MAX_JPEG_BYTES
        max_pixels = int(argv[4]) if len(argv) >= 5 else DEFAULT_MAX_IMAGE_PIXELS
        try:
            sys.stdout.write(json.dumps(validate_jpeg_payload(argv[2], max_bytes, max_pixels)) + "\n")
            return 0
        except PipelineError as exc:
            log_err(f"{exc.code}: {exc.message}")
            sys.stdout.write(
                json.dumps({"status": "error", "code": exc.code, "message": exc.message}) + "\n"
            )
            return 1
        except Exception as exc:
            log_err(f"Unexpected validation error: {exc}")
            sys.stdout.write(
                json.dumps({"status": "error", "code": "INTERNAL_ERROR", "message": str(exc)}) + "\n"
            )
            return 1

    manifest_path = Path(argv[1])
    if not manifest_path.is_file():
        log_err(f"Manifest file not found: {manifest_path}")
        return 2

    try:
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        result = run_beauty_task(manifest) if manifest.get("task") else run_render_from_manifest(manifest)
        sys.stdout.write(json.dumps(result) + "\n")
        return 0
    except PipelineError as exc:
        log_err(f"{exc.code}: {exc.message}")
        sys.stdout.write(
            json.dumps({"status": "error", "code": exc.code, "message": exc.message}) + "\n"
        )
        return 1
    except Exception as exc:
        log_err(f"Unexpected render failure: {exc}")
        sys.stdout.write(
            json.dumps({"status": "error", "code": "RENDER_FAILED", "message": str(exc)}) + "\n"
        )
        return 1


if __name__ == "__main__":
    sys.exit(main(sys.argv))
