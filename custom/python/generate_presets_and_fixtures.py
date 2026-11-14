#!/usr/bin/env python3
"""
Deterministic generator for:
1. Size-33 3D .cube LUT presets (korean-soft, film-400, vintage-fade) + manifest.json
2. Contact sheet and sample rendered outputs in custom/presets/
3. Deterministic camera fixture JPEGs (slots 1..3, versions v1..v4, EXIF orientation, ICC test cases)
4. Simulated liveview preview frames in custom/public/assets/mock-preview/
"""

from __future__ import annotations

import hashlib
import io
import json
from pathlib import Path
from typing import Callable, Tuple

import numpy as np
from PIL import Image, ImageCms, ImageDraw

from cube_lut import apply_cube_lut_rgb, parse_cube_file
from render_pipeline import encode_jpeg_bytes, get_srgb_icc_bytes, run_render_from_manifest

CUSTOM_DIR = Path(__file__).resolve().parent.parent
PRESETS_DIR = CUSTOM_DIR / "presets"
SAMPLES_DIR = PRESETS_DIR / "samples"
FIXTURES_DIR = CUSTOM_DIR / "tests" / "fixtures"
PREVIEW_FRAMES_DIR = CUSTOM_DIR / "public" / "assets" / "mock-preview"
TEMPLATE_PATH = CUSTOM_DIR / "templates" / "vertical-three-v1.json"


def write_cube_lut(
    path: Path,
    title: str,
    transform_fn: Callable[[np.ndarray, np.ndarray, np.ndarray], Tuple[np.ndarray, np.ndarray, np.ndarray]],
    size: int = 33,
) -> str:
    """
    Generate a .cube file of given size (default 33) with B outer, G middle, R inner loop.
    Returns SHA-256 hex digest of the written file.
    """
    lin = np.linspace(0.0, 1.0, size, dtype=np.float64)
    # Meshgrid in (B, G, R) order so flattening has R changing fastest
    b_grid, g_grid, r_grid = np.meshgrid(lin, lin, lin, indexing="ij")
    ro, go, bo = transform_fn(r_grid, g_grid, b_grid)
    ro = np.clip(ro, 0.0, 1.0).ravel()
    go = np.clip(go, 0.0, 1.0).ravel()
    bo = np.clip(bo, 0.0, 1.0).ravel()

    lines = [
        f'TITLE "{title}"',
        "# Photobooth Operator Edition Preset LUT (MIT License)",
        "# Project-authored preset; does not claim commercial film emulation.",
        "DOMAIN_MIN 0.0 0.0 0.0",
        "DOMAIN_MAX 1.0 1.0 1.0",
        f"LUT_3D_SIZE {size}",
    ]
    for r_v, g_v, b_v in zip(ro, go, bo):
        lines.append(f"{r_v:.6f} {g_v:.6f} {b_v:.6f}")

    content = ("\n".join(lines) + "\n").encode("utf-8")
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(content)
    return hashlib.sha256(content).hexdigest()


def transform_korean_soft(
    r: np.ndarray, g: np.ndarray, b: np.ndarray
) -> Tuple[np.ndarray, np.ndarray, np.ndarray]:
    """
    Korean Soft:
    - Lifted airy shadows (+0.04 black floor) and gentle gamma (0.90)
    - Soft highlight roll-off
    - Subtle warm-rose skin tone shift (+R, soft G, slightly airy B in midtones)
    - Slight pastel desaturation (90% chroma)
    """
    r1 = 0.04 + 0.93 * np.power(r, 0.88)
    g1 = 0.035 + 0.93 * np.power(g, 0.91)
    b1 = 0.045 + 0.92 * np.power(b, 0.92)

    # Soft highlight shoulder
    r1 = r1 - 0.04 * np.square(r1)
    g1 = g1 - 0.03 * np.square(g1)
    b1 = b1 - 0.02 * np.square(b1)

    luma = 0.299 * r1 + 0.587 * g1 + 0.114 * b1
    sat = 0.88
    ro = luma + (r1 - luma) * sat + 0.025 * (1.0 - np.abs(2.0 * luma - 1.0))
    go = luma + (g1 - luma) * sat + 0.008 * (1.0 - np.abs(2.0 * luma - 1.0))
    bo = luma + (b1 - luma) * sat + 0.015 * (1.0 - luma)
    return ro, go, bo


def transform_film_400(
    r: np.ndarray, g: np.ndarray, b: np.ndarray
) -> Tuple[np.ndarray, np.ndarray, np.ndarray]:
    """
    Film 400:
    - Classic S-curve contrast
    - Cool cyan-green shadow undertint and warm golden highlight bias
    - Rich midtone color separation
    """
    def s_curve(x: np.ndarray) -> np.ndarray:
        return x * x * (3.0 - 2.0 * x)

    rc = 0.35 * r + 0.65 * s_curve(r)
    gc = 0.35 * g + 0.65 * s_curve(g)
    bc = 0.40 * b + 0.60 * s_curve(b)

    luma = 0.299 * rc + 0.587 * gc + 0.114 * bc
    shadow_weight = np.square(1.0 - luma)
    highlight_weight = np.square(luma)

    ro = rc + 0.045 * highlight_weight - 0.015 * shadow_weight
    go = gc + 0.020 * highlight_weight + 0.018 * shadow_weight
    bo = bc - 0.035 * highlight_weight + 0.025 * shadow_weight

    sat = 1.08
    luma2 = 0.299 * ro + 0.587 * go + 0.114 * bo
    ro = luma2 + (ro - luma2) * sat
    go = luma2 + (go - luma2) * sat
    bo = luma2 + (bo - luma2) * sat
    return ro, go, bo


def transform_vintage_fade(
    r: np.ndarray, g: np.ndarray, b: np.ndarray
) -> Tuple[np.ndarray, np.ndarray, np.ndarray]:
    """
    Vintage Fade:
    - Matte lifted blacks (0.08 floor) and compressed highlights (0.90 ceiling)
    - Muted saturation (72%)
    - Warm amber/sepia tint in midtones and faded plum/cool shadows
    """
    rf = 0.08 + 0.83 * r
    gf = 0.07 + 0.82 * g
    bf = 0.09 + 0.76 * b

    luma = 0.299 * rf + 0.587 * gf + 0.114 * bf
    sat = 0.72
    ro = luma + (rf - luma) * sat + 0.055 * luma
    go = luma + (gf - luma) * sat + 0.022 * luma
    bo = luma + (bf - luma) * sat - 0.045 * luma + 0.025 * (1.0 - luma)
    return ro, go, bo


def render_synthetic_card(
    width: int,
    height: int,
    slot_num: int,
    version_num: int,
    accent_rgb: Tuple[int, int, int],
    label_prefix: str = "SLOT",
) -> Image.Image:
    """
    Create a deterministic, rich test image with:
    - Smooth 2D color gradient background
    - Color checker patches (Red, Green, Blue, Warm Skin, Cool Sky, Neutral Gray, White, Black)
    - Clear geometric slot/version indicator bars and block digits
    """
    y_lin = np.linspace(0.0, 1.0, height, dtype=np.float32)[:, None]
    x_lin = np.linspace(0.0, 1.0, width, dtype=np.float32)[None, :]

    ar, ag, ab = [c / 255.0 for c in accent_rgb]
    r_ch = np.clip(0.15 + 0.55 * x_lin + 0.25 * ar * (1.0 - y_lin), 0.0, 1.0)
    g_ch = np.clip(0.15 + 0.55 * y_lin + 0.25 * ag * x_lin, 0.0, 1.0)
    b_ch = np.clip(0.20 + 0.45 * (1.0 - x_lin) + 0.30 * ab * y_lin, 0.0, 1.0)

    arr = np.stack([r_ch, g_ch, b_ch], axis=-1)
    img = Image.fromarray(np.rint(arr * 255.0).astype(np.uint8), mode="RGB")
    draw = ImageDraw.Draw(img)

    # Outer frame
    draw.rectangle([16, 16, width - 17, height - 17], outline=(255, 255, 255), width=6)
    draw.rectangle([28, 28, width - 29, height - 29], outline=accent_rgb, width=6)

    # Color checker row at bottom
    patches = [
        (220, 60, 60),    # Red
        (60, 190, 90),    # Green
        (55, 110, 225),   # Blue
        (235, 185, 155),  # Light Skin
        (195, 140, 110),  # Medium Skin
        (240, 200, 70),   # Warm Yellow
        (245, 245, 245),  # Highlight White
        (128, 128, 128),  # Mid Gray
        (32, 32, 36),     # Deep Shadow
    ]
    patch_y0 = height - 130
    patch_y1 = height - 45
    margin_x = 50
    avail_w = width - 2 * margin_x
    pw = avail_w // len(patches)
    for idx, p_col in enumerate(patches):
        x0 = margin_x + idx * pw + 4
        x1 = margin_x + (idx + 1) * pw - 4
        draw.rectangle([x0, patch_y0, x1, patch_y1], fill=p_col, outline=(255, 255, 255), width=2)

    # Header banner with slot & version badge
    draw.rectangle([50, 50, 520, 185], fill=(20, 24, 34), outline=accent_rgb, width=4)

    # Draw slot index bars (1, 2, or 3 big vertical bars)
    for s_i in range(slot_num):
        bx0 = 75 + s_i * 42
        draw.rectangle([bx0, 75, bx0 + 26, 160], fill=accent_rgb, outline=(255, 255, 255), width=2)

    # Draw version circles on the right of the banner
    for v_i in range(version_num):
        cx0 = 230 + v_i * 58
        draw.ellipse([cx0, 88, cx0 + 42, 130], fill=(255, 215, 70), outline=(255, 255, 255), width=2)

    # Text label using default bitmap font scaled onto a badge so it's readable
    badge = Image.new("RGB", (220, 36), color=(20, 24, 34))
    b_draw = ImageDraw.Draw(badge)
    b_draw.text((8, 4), f"{label_prefix} {slot_num}  |  VER v{version_num}", fill=(255, 255, 255))
    b_draw.text((8, 19), f"SEED {slot_num * 100 + version_num}", fill=(190, 210, 255))
    badge_scaled = badge.resize((440, 72), resample=Image.Resampling.NEAREST)
    img.paste(badge_scaled, (550, 75))

    return img


def main() -> None:
    PRESETS_DIR.mkdir(parents=True, exist_ok=True)
    SAMPLES_DIR.mkdir(parents=True, exist_ok=True)
    FIXTURES_DIR.mkdir(parents=True, exist_ok=True)
    PREVIEW_FRAMES_DIR.mkdir(parents=True, exist_ok=True)

    # 1. Generate .cube LUT files
    ks_path = PRESETS_DIR / "korean-soft.cube"
    f4_path = PRESETS_DIR / "film-400.cube"
    vf_path = PRESETS_DIR / "vintage-fade.cube"

    ks_sha = write_cube_lut(ks_path, "Korean Soft v1.0.0", transform_korean_soft, size=33)
    f4_sha = write_cube_lut(f4_path, "Film 400 v1.0.0", transform_film_400, size=33)
    vf_sha = write_cube_lut(vf_path, "Vintage Fade v1.0.0", transform_vintage_fade, size=33)

    # Verify all 3 LUTs parse cleanly
    for p in (ks_path, f4_path, vf_path):
        parsed = parse_cube_file(p, expected_size=33, require_unit_domain=True)
        assert parsed.size == 33

    manifest_data = {
        "schema_version": "1.0.0",
        "default_lut": "original",
        "presets": [
            {
                "id": "original",
                "name": "Gốc (Original)",
                "name_en": "Original",
                "version": "1.0.0",
                "type": "identity",
                "file": None,
                "sha256": "identity-original",
                "size": 0,
                "license": "MIT",
                "description": "Giữ nguyên màu sắc chuẩn sRGB từ ảnh chụp, không áp dụng biến đổi LUT."
            },
            {
                "id": "korean-soft",
                "name": "Korean Soft",
                "name_en": "Korean Soft",
                "version": "1.0.0",
                "type": "cube_3d",
                "file": "korean-soft.cube",
                "sha256": ks_sha,
                "size": 33,
                "license": "MIT",
                "description": "Tông sáng trong trẻo, nâng nhẹ vùng tối (+4%), giảm nhẹ độ tương phản vùng sáng và ưu tiên sắc hồng ấm dịu cho tông da."
            },
            {
                "id": "film-400",
                "name": "Film 400",
                "name_en": "Film 400",
                "version": "1.0.0",
                "type": "cube_3d",
                "file": "film-400.cube",
                "sha256": f4_sha,
                "size": 33,
                "license": "MIT",
                "description": "Đường cong tương phản S-curve cổ điển, vùng tối ánh xanh lục-lam nhẹ và vùng sáng vàng ấm."
            },
            {
                "id": "vintage-fade",
                "name": "Vintage Fade",
                "name_en": "Vintage Fade",
                "version": "1.0.0",
                "type": "cube_3d",
                "file": "vintage-fade.cube",
                "sha256": vf_sha,
                "size": 33,
                "license": "MIT",
                "description": "Nền đen mờ (matte lifted blacks), giảm độ bão hòa màu (72%) và phủ sắc vàng hổ phách hoài cổ."
            }
        ]
    }
    (PRESETS_DIR / "manifest.json").write_text(
        json.dumps(manifest_data, indent=2, ensure_ascii=False) + "\n",
        encoding="utf-8",
    )

    # 2. Generate deterministic slot fixture JPEGs (1200x600 sRGB JPEGs)
    srgb_bytes = get_srgb_icc_bytes()
    slot_accents = {
        1: (230, 75, 95),   # Coral Red for Slot 1
        2: (55, 175, 135),  # Emerald Teal for Slot 2
        3: (70, 125, 235),  # Royal Blue for Slot 3
    }

    fixture_hashes = {}
    for slot_num in (1, 2, 3):
        for ver_num in (1, 2, 3, 4):
            # Slightly shift accent color per version so v1/v2/v3/v4 are visually & byte-distinct
            base_acc = slot_accents[slot_num]
            acc = (
                min(255, max(20, (base_acc[0] + (ver_num - 1) * 35) % 255)),
                min(255, max(20, (base_acc[1] + (ver_num - 1) * 45) % 255)),
                min(255, max(20, (base_acc[2] + (ver_num - 1) * 55) % 255)),
            )
            card = render_synthetic_card(1200, 600, slot_num, ver_num, acc)
            jpeg_bytes = encode_jpeg_bytes(card, quality=95, subsampling=0, dpi=300, icc_bytes=srgb_bytes)
            fname = f"slot-{slot_num}-v{ver_num}.jpg"
            fpath = FIXTURES_DIR / fname
            fpath.write_bytes(jpeg_bytes)
            fixture_hashes[fname] = hashlib.sha256(jpeg_bytes).hexdigest()

    # 3. Generate simulated liveview frames (3 frames for smooth preview animation)
    for f_idx in (1, 2, 3):
        frame_img = render_synthetic_card(
            960,
            540,
            slot_num=f_idx,
            version_num=1,
            accent_rgb=(80 + f_idx * 40, 160, 235 - f_idx * 30),
            label_prefix="LIVE PREVIEW",
        )
        frame_bytes = encode_jpeg_bytes(frame_img, quality=88, subsampling=0, dpi=150, icc_bytes=srgb_bytes)
        (PREVIEW_FRAMES_DIR / f"frame-{f_idx}.jpg").write_bytes(frame_bytes)

    # 4. Generate special test fixtures:
    # 4a. EXIF Orientation=6 (stored 600x1200 rotated 90 deg CCW, displays 1200x600 upright)
    upright = render_synthetic_card(1200, 600, 1, 1, (240, 120, 40), label_prefix="EXIF")
    rotated_ccw = upright.transpose(Image.Transpose.ROTATE_90)
    exif_obj = Image.Exif()
    exif_obj[274] = 6  # Orientation = 6 (Rotate 90 CW to restore)
    buf_exif = io.BytesIO()
    rotated_ccw.save(buf_exif, format="JPEG", quality=95, exif=exif_obj.tobytes(), icc_profile=srgb_bytes)
    (FIXTURES_DIR / "exif-orientation-6.jpg").write_bytes(buf_exif.getvalue())

    # 4b. Valid JPEG without ICC profile (triggers assumed_srgb audit)
    buf_no_icc = io.BytesIO()
    upright.save(buf_no_icc, format="JPEG", quality=92)
    (FIXTURES_DIR / "no-icc-profile.jpg").write_bytes(buf_no_icc.getvalue())

    # 4c. Valid JPEG with declared but corrupt ICC_PROFILE APP2 segment
    valid_bytes = buf_no_icc.getvalue()
    corrupt_icc_payload = b"ICC_PROFILE\x00\x01\x01" + b"CORRUPT_ICC_DATA_HEADER_NOT_VALID" * 5
    seg_len = len(corrupt_icc_payload) + 2
    app2_segment = b"\xff\xe2" + bytes([(seg_len >> 8) & 0xFF, seg_len & 0xFF]) + corrupt_icc_payload
    corrupt_icc_jpeg = valid_bytes[:2] + app2_segment + valid_bytes[2:]
    (FIXTURES_DIR / "corrupt-icc.jpg").write_bytes(corrupt_icc_jpeg)

    # 4d. Corrupt/truncated JPEG
    (FIXTURES_DIR / "corrupt-truncated.jpg").write_bytes(b"\xff\xd8\xff\xe0\x00\x10JFIF\x00TRUNCATED_GARBAGE")

    # 5. Generate sample collages for all 4 presets and build contact-sheet.jpg
    sample_cards = []
    for preset in manifest_data["presets"]:
        pid = preset["id"]
        out_print = SAMPLES_DIR / f"sample-{pid}.jpg"
        out_prev = SAMPLES_DIR / f"sample-{pid}-preview.jpg"
        manifest = {
            "photos": [
                {"slot": 1, "path": str(FIXTURES_DIR / "slot-1-v1.jpg")},
                {"slot": 2, "path": str(FIXTURES_DIR / "slot-2-v1.jpg")},
                {"slot": 3, "path": str(FIXTURES_DIR / "slot-3-v1.jpg")},
            ],
            "lut_id": pid,
            "lut_version": preset["version"],
            "lut_path": str(PRESETS_DIR / preset["file"]) if preset["file"] else None,
            "template_path": str(TEMPLATE_PATH),
            "output_print_path": str(out_print),
            "output_preview_path": str(out_prev),
        }
        run_render_from_manifest(manifest)
        prev_img = Image.open(out_prev)
        prev_img.load()
        sample_cards.append((preset["name"], prev_img))

    # Build a 4-column contact sheet
    thumb_w, thumb_h = 340, 504
    pad = 24
    header_h = 60
    sheet_w = pad + 4 * (thumb_w + pad)
    sheet_h = pad + header_h + thumb_h + pad
    sheet = Image.new("RGB", (sheet_w, sheet_h), color=(24, 26, 32))
    s_draw = ImageDraw.Draw(sheet)

    for idx, (pname, pimg) in enumerate(sample_cards):
        x0 = pad + idx * (thumb_w + pad)
        y0 = pad + header_h
        s_draw.rectangle([x0, pad, x0 + thumb_w, pad + 44], fill=(40, 45, 58), outline=(90, 105, 135), width=2)
        s_draw.text((x0 + 14, pad + 15), f"PRESET: {pname}", fill=(255, 255, 255))
        resized = pimg.resize((thumb_w, thumb_h), resample=Image.Resampling.LANCZOS)
        sheet.paste(resized, (x0, y0))

    sheet_bytes = encode_jpeg_bytes(sheet, quality=92, subsampling=0, dpi=300, icc_bytes=srgb_bytes)
    (PRESETS_DIR / "contact-sheet.jpg").write_bytes(sheet_bytes)

    (FIXTURES_DIR / "manifest.json").write_text(
        json.dumps({"fixtures": fixture_hashes}, indent=2) + "\n",
        encoding="utf-8",
    )
    print("Generated LUT presets, sample renders, contact sheet, and camera fixtures successfully.")


if __name__ == "__main__":
    main()

