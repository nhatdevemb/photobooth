#!/usr/bin/env python3
"""
3D .cube LUT parser and vectorized trilinear interpolation using NumPy.
Conforms to Adobe Cube LUT Specification 1.0 (size 33, domain 0..1).
"""

from __future__ import annotations

import hashlib
from dataclasses import dataclass
from pathlib import Path
from typing import Optional, Tuple

import numpy as np


class LutParseError(ValueError):
    """Raised when a .cube file is malformed or violates constraints."""


@dataclass(frozen=True)
class CubeLut:
    title: str
    size: int
    domain_min: Tuple[float, float, float]
    domain_max: Tuple[float, float, float]
    # Shape: (size, size, size, 3) indexed as [r_idx, g_idx, b_idx, channel]
    table: np.ndarray
    sha256: str


def parse_cube_file(
    path: str | Path,
    expected_size: Optional[int] = 33,
    require_unit_domain: bool = True,
) -> CubeLut:
    """
    Parse and validate a 3D .cube LUT file.

    In the .cube specification, table rows are ordered with Red changing fastest,
    then Green, then Blue (B outer loop, G middle loop, R inner loop).
    """
    file_path = Path(path)
    if not file_path.is_file():
        raise LutParseError(f"LUT file not found: {file_path}")

    if file_path.stat().st_size > 5 * 1024 * 1024:
        raise LutParseError("LUT exceeds 5 MiB")
    raw_bytes = file_path.read_bytes()
    sha256 = hashlib.sha256(raw_bytes).hexdigest()

    try:
        text = raw_bytes.decode("utf-8-sig")
    except UnicodeDecodeError as exc:
        raise LutParseError("LUT file is not valid UTF-8") from exc

    title = file_path.stem
    lut_size: Optional[int] = None
    domain_min: Tuple[float, float, float] = (0.0, 0.0, 0.0)
    domain_max: Tuple[float, float, float] = (1.0, 1.0, 1.0)
    data_rows = []
    header_done = False
    seen_domains = set()

    for line_no, raw_line in enumerate(text.splitlines(), start=1):
        if len(raw_line) > 65536:
            raise LutParseError(f"Line {line_no}: Line too long")
        line = raw_line.strip()
        if not line or line.startswith("#"):
            continue

        upper = line.upper()
        if upper.startswith("TITLE"):
            if header_done:
                raise LutParseError(f"Line {line_no}: TITLE after data started")
            parts = line.split(None, 1)
            if len(parts) == 2:
                title = parts[1].strip().strip('"')
            continue

        if upper.startswith("LUT_1D_SIZE") or upper.startswith("LUT_1D_INPUT_RANGE"):
            raise LutParseError(f"Line {line_no}: 1D LUTs are not supported")

        if upper.startswith("LUT_3D_INPUT_RANGE"):
            raise LutParseError(f"Line {line_no}: Unsupported keyword LUT_3D_INPUT_RANGE")

        if upper.startswith("LUT_3D_SIZE"):
            if header_done:
                raise LutParseError(f"Line {line_no}: LUT_3D_SIZE after data started")
            if lut_size is not None:
                raise LutParseError(f"Line {line_no}: Duplicate LUT_3D_SIZE")
            parts = line.split()
            if len(parts) != 2:
                raise LutParseError(f"Line {line_no}: Invalid LUT_3D_SIZE syntax")
            try:
                lut_size = int(parts[1])
            except ValueError as exc:
                raise LutParseError(f"Line {line_no}: Non-integer LUT_3D_SIZE") from exc
            if lut_size < 2 or lut_size > 128:
                raise LutParseError(f"Line {line_no}: LUT_3D_SIZE out of bounds: {lut_size}")
            if expected_size is not None and lut_size != expected_size:
                raise LutParseError(
                    f"Line {line_no}: Expected LUT_3D_SIZE {expected_size}, got {lut_size}"
                )
            continue

        if upper.startswith("DOMAIN_MIN"):
            if 'min' in seen_domains:
                raise LutParseError(f"Line {line_no}: Duplicate DOMAIN_MIN")
            seen_domains.add('min')
            if header_done:
                raise LutParseError(f"Line {line_no}: DOMAIN_MIN after data started")
            parts = line.split()
            if len(parts) != 4:
                raise LutParseError(f"Line {line_no}: Invalid DOMAIN_MIN")
            try:
                domain_min = (float(parts[1]), float(parts[2]), float(parts[3]))
            except ValueError as exc:
                raise LutParseError(f"Line {line_no}: Non-float DOMAIN_MIN") from exc
            continue

        if upper.startswith("DOMAIN_MAX"):
            if 'max' in seen_domains:
                raise LutParseError(f"Line {line_no}: Duplicate DOMAIN_MAX")
            seen_domains.add('max')
            if header_done:
                raise LutParseError(f"Line {line_no}: DOMAIN_MAX after data started")
            parts = line.split()
            if len(parts) != 4:
                raise LutParseError(f"Line {line_no}: Invalid DOMAIN_MAX")
            try:
                domain_max = (float(parts[1]), float(parts[2]), float(parts[3]))
            except ValueError as exc:
                raise LutParseError(f"Line {line_no}: Non-float DOMAIN_MAX") from exc
            continue

        # Must be a triplet of floats
        if lut_size is None:
            raise LutParseError("Missing LUT_3D_SIZE before data")
        if len(data_rows) >= lut_size ** 3:
            raise LutParseError("Too many LUT data rows")
        header_done = True
        parts = line.split()
        if len(parts) != 3:
            raise LutParseError(f"Line {line_no}: Expected 3 float values, got {len(parts)}")
        try:
            r_val, g_val, b_val = float(parts[0]), float(parts[1]), float(parts[2])
        except ValueError as exc:
            raise LutParseError(f"Line {line_no}: Invalid float triplet") from exc

        if not (np.isfinite(r_val) and np.isfinite(g_val) and np.isfinite(b_val)):
            raise LutParseError(f"Line {line_no}: Non-finite value in LUT")

        if min(r_val, g_val, b_val) < -1e-5 or max(r_val, g_val, b_val) > 1.00001:
            raise LutParseError(
                f"Line {line_no}: LUT value outside [0..1]: ({r_val}, {g_val}, {b_val})"
            )

        data_rows.append((
            min(max(r_val, 0.0), 1.0),
            min(max(g_val, 0.0), 1.0),
            min(max(b_val, 0.0), 1.0),
        ))

    if lut_size is None:
        raise LutParseError("Missing LUT_3D_SIZE header")

    if require_unit_domain:
        if not np.allclose(domain_min, (0.0, 0.0, 0.0), atol=1e-6) or not np.allclose(
            domain_max, (1.0, 1.0, 1.0), atol=1e-6
        ):
            raise LutParseError(
                f"LUT domain must be 0..1, got {domain_min} .. {domain_max}"
            )
    else:
        for c_min, c_max in zip(domain_min, domain_max):
            if not (np.isfinite(c_min) and np.isfinite(c_max)) or c_max <= c_min:
                raise LutParseError(f"Invalid domain range: {domain_min} .. {domain_max}")

    expected_rows = lut_size ** 3
    if len(data_rows) != expected_rows:
        raise LutParseError(
            f"Expected {expected_rows} data rows for size {lut_size}, got {len(data_rows)}"
        )

    # Reshape from (B, G, R, 3) to (R, G, B, 3)
    arr = np.asarray(data_rows, dtype=np.float32).reshape((lut_size, lut_size, lut_size, 3))
    table = np.ascontiguousarray(arr.transpose((2, 1, 0, 3)))

    return CubeLut(
        title=title,
        size=lut_size,
        domain_min=domain_min,
        domain_max=domain_max,
        table=table,
        sha256=sha256,
    )


def apply_cube_lut_rgb(image_rgb: np.ndarray, lut: CubeLut) -> np.ndarray:
    """
    Apply a 3D CubeLut to an RGB image array via vectorized trilinear interpolation.

    Args:
        image_rgb: uint8 array of shape (H, W, 3) or float32 array in [0.0, 1.0].
        lut: Parsed CubeLut instance.

    Returns:
        uint8 array of shape (H, W, 3) if input was uint8, else float32 in [0.0, 1.0].
    """
    if image_rgb.ndim != 3 or image_rgb.shape[2] != 3:
        raise ValueError(f"Expected (H, W, 3) RGB array, got {image_rgb.shape}")

    input_is_uint8 = image_rgb.dtype == np.uint8
    if input_is_uint8:
        rgb = image_rgb.astype(np.float32) * (1.0 / 255.0)
    else:
        rgb = np.clip(image_rgb.astype(np.float32), 0.0, 1.0)

    d_min = np.asarray(lut.domain_min, dtype=np.float32)
    d_max = np.asarray(lut.domain_max, dtype=np.float32)
    norm = np.clip((rgb - d_min) / (d_max - d_min), 0.0, 1.0)

    scale = float(lut.size - 1)
    coords = norm * scale

    idx0 = np.floor(coords).astype(np.int32)
    np.clip(idx0, 0, lut.size - 2, out=idx0)
    idx1 = idx0 + 1
    frac = coords - idx0

    r0, g0, b0 = idx0[..., 0], idx0[..., 1], idx0[..., 2]
    r1, g1, b1 = idx1[..., 0], idx1[..., 1], idx1[..., 2]

    fr = frac[..., 0:1]
    fg = frac[..., 1:2]
    fb = frac[..., 2:3]

    tbl = lut.table
    c000 = tbl[r0, g0, b0]
    c100 = tbl[r1, g0, b0]
    c010 = tbl[r0, g1, b0]
    c110 = tbl[r1, g1, b0]
    c001 = tbl[r0, g0, b1]
    c101 = tbl[r1, g0, b1]
    c011 = tbl[r0, g1, b1]
    c111 = tbl[r1, g1, b1]

    c00 = c000 + (c100 - c000) * fr
    c10 = c010 + (c110 - c010) * fr
    c01 = c001 + (c101 - c001) * fr
    c11 = c011 + (c111 - c011) * fr

    c0 = c00 + (c10 - c00) * fg
    c1 = c01 + (c11 - c01) * fg

    out = c0 + (c1 - c0) * fb
    np.clip(out, 0.0, 1.0, out=out)

    if input_is_uint8:
        return np.rint(out * 255.0).astype(np.uint8)
    return out

