#!/usr/bin/env python3
"""
Extract all 40 LK Magic-WIN color filters (110001..110040) via LK Magic-WIN's native
aipic.dll engine (/api/v1/bake-batch), convert each baked 3D color lattice into a
smooth 33x33x33 .cube LUT file, generate portrait preview samples, and register
them in Photobooth's presets/manifest.json.
"""

from __future__ import annotations

import hashlib
import json
import sys
import tempfile
import time
import urllib.error
import urllib.request
from pathlib import Path
from typing import Any, Dict, List

import numpy as np
from PIL import Image

PYTHON_DIR = Path(__file__).resolve().parent
if str(PYTHON_DIR) not in sys.path:
    sys.path.insert(0, str(PYTHON_DIR))

from beauty import ensure_lkmagic_background, shutdown_lkmagic_background
from cube_lut import apply_cube_lut_rgb, parse_cube_file

CUSTOM_ROOT = PYTHON_DIR.parent
PRESETS_DIR = CUSTOM_ROOT / "presets"
SAMPLES_DIR = CUSTOM_ROOT / "public" / "assets" / "samples"
SOURCE_PORTRAIT = SAMPLES_DIR / "source-portrait.jpg"
MANIFEST_PATH = PRESETS_DIR / "manifest.json"

# All 40 official color filters from LK Magic-WIN (algorithms.json + runtime_hook_dict.json)
LKMAGIC_FILTERS: List[Dict[str, Any]] = [
    {"algo_id": 110019, "id": "lk-oxygen", "name": "LK · Trong trẻo (Oxygen)", "name_en": "LK Oxygen", "desc": "Tông màu trong trẻo, tươi mát tự nhiên (Oxygen - LK Magic)."},
    {"algo_id": 110020, "id": "lk-cute-hue", "name": "LK · Trời xanh (CuteHue)", "name_en": "LK CuteHue", "desc": "Sắc xanh trong sáng, dịu mát trẻ trung (CuteHue - LK Magic)."},
    {"algo_id": 110021, "id": "lk-soft", "name": "LK · Mềm mại (Soft)", "name_en": "LK Soft", "desc": "Chuyển sắc êm dịu, tương phản mềm mại (Soft - LK Magic)."},
    {"algo_id": 110022, "id": "lk-elite", "name": "LK · Sang nhẹ (Elite)", "name_en": "LK Elite", "desc": "Tông màu sang trọng, hiện đại (Elite - LK Magic)."},
    {"algo_id": 110023, "id": "lk-bamboo", "name": "LK · Sân vườn (Bamboo)", "name_en": "LK Bamboo", "desc": "Sắc xanh lá dịu dàng, thanh lịch (Bamboo - LK Magic)."},
    {"algo_id": 110024, "id": "lk-porcelain", "name": "LK · Hàn Quốc (Porcelain)", "name_en": "LK Porcelain", "desc": "Phong cách Hàn Quốc trắng sứ mịn màng (Porcelain - LK Magic)."},
    {"algo_id": 110025, "id": "lk-antique", "name": "LK · Cổ điển (Antique)", "name_en": "LK Antique", "desc": "Sắc màu cổ điển trầm ấm (Antique - LK Magic)."},
    {"algo_id": 110026, "id": "lk-filmic", "name": "LK · Phim nhựa (Filmic)", "name_en": "LK Filmic", "desc": "Chất màu phim nhựa giàu chiều sâu (Filmic - LK Magic)."},
    {"algo_id": 110027, "id": "lk-sylvan", "name": "LK · Rừng xanh (Sylvan)", "name_en": "LK Sylvan", "desc": "Phong cách thảo nguyên và rừng xanh (Sylvan - LK Magic)."},
    {"algo_id": 110028, "id": "lk-fairy", "name": "LK · Mộng mơ (Fairy)", "name_en": "LK Fairy", "desc": "Sắc thái lung linh, mộng mơ (Fairy - LK Magic)."},
    {"algo_id": 110029, "id": "lk-cinematic", "name": "LK · Điện ảnh (Cinematic)", "name_en": "LK Cinematic", "desc": "Tương phản và sắc độ chuẩn điện ảnh (Cinematic - LK Magic)."},
    {"algo_id": 110030, "id": "lk-retro", "name": "LK · Hoài cổ (Retro)", "name_en": "LK Retro", "desc": "Phong cách Retro hoài niệm (Retro - LK Magic)."},
    {"algo_id": 110031, "id": "lk-zenith", "name": "LK · Á Đông (Zenith)", "name_en": "LK Zenith", "desc": "Sắc thái nghệ thuật Á Đông (Zenith - LK Magic)."},
    {"algo_id": 110032, "id": "lk-sunburn", "name": "LK · Tạp chí (Sunburn)", "name_en": "LK Sunburn", "desc": "Màu tạp chí thời trang ấn tượng (Sunburn - LK Magic)."},
    {"algo_id": 110033, "id": "lk-brown", "name": "LK · Tông nâu cứng (Brown)", "name_en": "LK Brown", "desc": "Tông nâu trầm cá tính (Brown - LK Magic)."},
    {"algo_id": 110034, "id": "lk-pure", "name": "LK · Thuần khiết (Pure)", "name_en": "LK Pure", "desc": "Màu sắc thuần khiết, sạch sẽ (Pure - LK Magic)."},
    {"algo_id": 110035, "id": "lk-bright", "name": "LK · Trong sáng (Bright)", "name_en": "LK Bright", "desc": "Tăng cường độ sáng trong trẻo (Bright - LK Magic)."},
    {"algo_id": 110036, "id": "lk-highkey", "name": "LK · Mạnh mẽ (HighKey)", "name_en": "LK HighKey", "desc": "Tông sáng mạnh High-Key sắc nét (HighKey - LK Magic)."},
    {"algo_id": 110037, "id": "lk-vintage", "name": "LK · Xưa cũ (Vintage)", "name_en": "LK Vintage", "desc": "Màu ảnh nhuốm màu thời gian (Vintage - LK Magic)."},
    {"algo_id": 110038, "id": "lk-ashen", "name": "LK · Tông xám (Ashen)", "name_en": "LK Ashen", "desc": "Tông xám lạnh tối giản (Ashen - LK Magic)."},
    {"algo_id": 110039, "id": "lk-highkey2", "name": "LK · Tông cứng 2 (HighKey2)", "name_en": "LK HighKey 2", "desc": "Tương phản cao hiện đại (HighKey2 - LK Magic)."},
    {"algo_id": 110040, "id": "lk-modern", "name": "LK · Hiện đại (Modern)", "name_en": "LK Modern", "desc": "Sắc màu studio hiện đại (Modern - LK Magic)."},
    {"algo_id": 110001, "id": "lk-memory", "name": "LK · Hồi ức (Memory)", "name_en": "LK Memory", "desc": "Sắc màu kỷ niệm dịu nhẹ (Memory - LK Magic)."},
    {"algo_id": 110002, "id": "lk-maiden", "name": "LK · Thiếu nữ (Maiden)", "name_en": "LK Maiden", "desc": "Tông hồng đào trẻ trung nữ tính (Maiden - LK Magic)."},
    {"algo_id": 110003, "id": "lk-ruddy", "name": "LK · Hồng hào (Ruddy)", "name_en": "LK Ruddy", "desc": "Tôn sắc da hồng hào rạng rỡ (Ruddy - LK Magic)."},
    {"algo_id": 110004, "id": "lk-city", "name": "LK · Đô thị (City)", "name_en": "LK City", "desc": "Phong cách đường phố đô thị (City - LK Magic)."},
    {"algo_id": 110005, "id": "lk-shimmering", "name": "LK · Ánh nhẹ (Shimmering)", "name_en": "LK Shimmering", "desc": "Ánh sáng dịu nhẹ lung linh (Shimmering - LK Magic)."},
    {"algo_id": 110006, "id": "lk-redlips", "name": "LK · Môi đỏ (RedLips)", "name_en": "LK RedLips", "desc": "Tôn sắc môi đỏ và làn da sáng (RedLips - LK Magic)."},
    {"algo_id": 110007, "id": "lk-darktones", "name": "LK · Tông tối (DarkTones)", "name_en": "LK DarkTones", "desc": "Chiều sâu vùng tối Moody (DarkTones - LK Magic)."},
    {"algo_id": 110008, "id": "lk-nature", "name": "LK · Tự nhiên (Nature)", "name_en": "LK Nature", "desc": "Cân bằng màu tự nhiên hài hòa (Nature - LK Magic)."},
    {"algo_id": 110009, "id": "lk-fairness", "name": "LK · Trắng sáng (Fairness)", "name_en": "LK Fairness", "desc": "Sắc da trắng sáng mịn màng (Fairness - LK Magic)."},
    {"algo_id": 110010, "id": "lk-white-tea", "name": "LK · Trà trắng (WhiteTea)", "name_en": "LK WhiteTea", "desc": "Tông trà trắng thanh khiết (WhiteTea - LK Magic)."},
    {"algo_id": 110011, "id": "lk-strawberry", "name": "LK · Dâu tây (Strawberry)", "name_en": "LK Strawberry", "desc": "Sắc hồng dâu tây ngọt ngào (Strawberry - LK Magic)."},
    {"algo_id": 110012, "id": "lk-super-white", "name": "LK · Siêu trắng (SuperWhite)", "name_en": "LK SuperWhite", "desc": "Tông trắng sáng bật tông (SuperWhite - LK Magic)."},
    {"algo_id": 110013, "id": "lk-sea-reef", "name": "LK · Đá biển (SeaReef)", "name_en": "LK SeaReef", "desc": "Sắc xanh đại dương mát mẻ (SeaReef - LK Magic)."},
    {"algo_id": 110014, "id": "lk-walnut", "name": "LK · Óc chó (Walnut)", "name_en": "LK Walnut", "desc": "Tông nâu gỗ ấm áp (Walnut - LK Magic)."},
    {"algo_id": 110015, "id": "lk-orange-soda", "name": "LK · Cam tươi (OrangeSoda)", "name_en": "LK OrangeSoda", "desc": "Sắc cam năng động rực rỡ (OrangeSoda - LK Magic)."},
    {"algo_id": 110016, "id": "lk-daylight", "name": "LK · Ánh ngày (Daylight)", "name_en": "LK Daylight", "desc": "Ánh sáng ban ngày tự nhiên (Daylight - LK Magic)."},
    {"algo_id": 110017, "id": "lk-blue", "name": "LK · Xanh biếc (Blue)", "name_en": "LK Blue", "desc": "Tông xanh biếc dịu mắt (Blue - LK Magic)."},
    {"algo_id": 110018, "id": "lk-night-scene", "name": "LK · Cảnh đêm (NightScene)", "name_en": "LK NightScene", "desc": "Tương phản ánh đèn đêm huyền ảo (NightScene - LK Magic)."},
]

# 9x9x9 = 729 RGB lattice points arranged in 27x27 patches, each patch 40px wide x 50px high -> 1080x1350 grid
# Placed alongside source-portrait.jpg (1000x1333) on the left -> 2080x1350 composite image
LATTICE_N = 9
GRID_COLS = 27
GRID_ROWS = 27
PATCH_W = 40
PATCH_H = 50
LEFT_W = 1000


def build_hald_grid_image() -> Image.Image:
    """Create a 2080x1350 RGB image: left 1000x1350 unstretched portrait + right 1080x1350 27x27 patch grid."""
    levels = np.linspace(0.0, 255.0, LATTICE_N)
    # B slowest, G middle, R fastest
    b_grid, g_grid, r_grid = np.meshgrid(levels, levels, levels, indexing="ij")
    rgb_points = np.stack([r_grid.ravel(), g_grid.ravel(), b_grid.ravel()], axis=-1)
    rgb_uint8 = np.rint(rgb_points).astype(np.uint8).reshape((GRID_ROWS, GRID_COLS, 3))
    expanded = np.repeat(np.repeat(rgb_uint8, PATCH_H, axis=0), PATCH_W, axis=1)
    grid_img = Image.fromarray(expanded, mode="RGB")

    grid_w = GRID_COLS * PATCH_W
    grid_h = GRID_ROWS * PATCH_H
    canvas = Image.new("RGB", (LEFT_W + grid_w, grid_h), (128, 128, 128))
    if SOURCE_PORTRAIT.is_file():
        with Image.open(SOURCE_PORTRAIT) as p_im:
            canvas.paste(p_im.convert("RGB"), (0, 0))
    canvas.paste(grid_img, (LEFT_W, 0))
    return canvas


def decode_hald_grid_to_cube(img: Image.Image, title: str) -> str:
    """Extract the 9x9x9 lattice from the right 1080x1350 region and trilinearly upsample to 33x33x33 .cube."""
    rgb = img.convert("RGB")
    grid_w = GRID_COLS * PATCH_W
    grid_h = GRID_ROWS * PATCH_H
    target_w = LEFT_W + grid_w
    target_h = grid_h
    if rgb.size != (target_w, target_h):
        rgb = rgb.resize((target_w, target_h), resample=Image.Resampling.BILINEAR)
    grid_crop = rgb.crop((LEFT_W, 0, target_w, target_h))
    arr = np.asarray(grid_crop, dtype=np.float32) / 255.0
    # Sample inner 20x16 pixels (rows 15..35, cols 12..28) of each 50x40 patch to avoid boundary bleeding
    cells = arr.reshape((GRID_ROWS, PATCH_H, GRID_COLS, PATCH_W, 3))[:, 15:35, :, 12:28, :].mean(axis=(1, 3))
    lut9 = np.clip(cells.reshape((LATTICE_N, LATTICE_N, LATTICE_N, 3)), 0.0, 1.0)

    # Trilinearly interpolate (9, 9, 9, 3) -> (33, 33, 33, 3)
    coords = np.linspace(0.0, float(LATTICE_N - 1), 33, dtype=np.float32)
    b_g, g_g, r_g = np.meshgrid(coords, coords, coords, indexing="ij")

    b0 = np.clip(np.floor(b_g).astype(np.int32), 0, LATTICE_N - 2)
    g0 = np.clip(np.floor(g_g).astype(np.int32), 0, LATTICE_N - 2)
    r0 = np.clip(np.floor(r_g).astype(np.int32), 0, LATTICE_N - 2)
    b1 = b0 + 1
    g1 = g0 + 1
    r1 = r0 + 1

    fb = (b_g - b0)[..., None]
    fg = (g_g - g0)[..., None]
    fr = (r_g - r0)[..., None]

    c000 = lut9[b0, g0, r0]
    c001 = lut9[b0, g0, r1]
    c010 = lut9[b0, g1, r0]
    c011 = lut9[b0, g1, r1]
    c100 = lut9[b1, g0, r0]
    c101 = lut9[b1, g0, r1]
    c110 = lut9[b1, g1, r0]
    c111 = lut9[b1, g1, r1]

    c00 = c000 * (1.0 - fr) + c001 * fr
    c01 = c010 * (1.0 - fr) + c011 * fr
    c10 = c100 * (1.0 - fr) + c101 * fr
    c11 = c110 * (1.0 - fr) + c111 * fr
    c0 = c00 * (1.0 - fg) + c01 * fg
    c1 = c10 * (1.0 - fg) + c11 * fg
    lut33 = np.clip(c0 * (1.0 - fb) + c1 * fb, 0.0, 1.0).reshape((-1, 3))

    lines = [
        f'TITLE "{title}"',
        "LUT_3D_SIZE 33",
        "DOMAIN_MIN 0.0 0.0 0.0",
        "DOMAIN_MAX 1.0 1.0 1.0",
    ]
    for r, g, b in lut33:
        lines.append(f"{r:.6f} {g:.6f} {b:.6f}")
    return "\n".join(lines) + "\n"


def main() -> int:
    print("1. Ensuring LK Magic-WIN background bridge is running...")
    health = ensure_lkmagic_background(wait_seconds=35.0)
    if not (health.get("ok") and health.get("ready")):
        print("ERROR: LK Magic bridge not ready:", health)
        return 1

    try:
        with tempfile.TemporaryDirectory(prefix="pb_bake_luts_") as tmp_dir:
            tmp_root = Path(tmp_dir)
            hald_path = tmp_root / "hald_input.jpg"
            build_hald_grid_image().save(hald_path, format="JPEG", quality=100, subsampling=0)

            batch_items = []
            for f in LKMAGIC_FILTERS:
                batch_items.append(
                    {
                        "filter_id": f["algo_id"],
                        "value": 1.0,
                        "output_path": str(tmp_root / f"baked_{f['algo_id']}.png"),
                    }
                )

            print(f"2. Baking {len(batch_items)} color filters via aipic.dll...")
            start_t = time.monotonic()
            req = urllib.request.Request(
                "http://127.0.0.1:5520/api/v1/bake-batch",
                data=json.dumps({"input_path": str(hald_path), "filters": batch_items}).encode("utf-8"),
                headers={"Content-Type": "application/json"},
                method="POST",
            )
            opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
            try:
                with opener.open(req, timeout=240.0) as resp:
                    res_data = json.loads(resp.read().decode("utf-8"))
            except urllib.error.HTTPError as e:
                err_body = e.read().decode("utf-8", errors="replace")
                print(f"HTTP Error {e.code}: {err_body}")
                return 1

            if not res_data.get("ok"):
                print("ERROR baking batch:", res_data)
                return 1
            print(f"   Baked {res_data.get('count')} filters in {time.monotonic() - start_t:.2f}s!")

            with Image.open(SOURCE_PORTRAIT) as src_im:
                portrait_rgb = np.asarray(src_im.convert("RGB"), dtype=np.uint8)

            manifest = json.loads(MANIFEST_PATH.read_text(encoding="utf-8"))
            base_ids = {"original", "korean-soft", "film-400", "vintage-fade"}
            presets_list = [p for p in manifest.get("presets", []) if p.get("id") in base_ids]

            print("3. Writing .cube files and generating portrait preview samples...")
            for idx, f in enumerate(LKMAGIC_FILTERS, 1):
                baked_png = tmp_root / f"baked_{f['algo_id']}.png"
                with Image.open(baked_png) as b_img:
                    cube_text = decode_hald_grid_to_cube(b_img, f["name_en"])

                cube_filename = f"{f['id']}.cube"
                cube_path = PRESETS_DIR / cube_filename
                cube_bytes = cube_text.encode("utf-8")
                cube_path.write_bytes(cube_bytes)
                cube_sha256 = hashlib.sha256(cube_bytes).hexdigest()

                # Validate and render portrait sample (at default 80% intensity matching LK Magic)
                lut = parse_cube_file(cube_path)
                graded_full = apply_cube_lut_rgb(portrait_rgb, lut)
                blended = np.clip(
                    np.rint(portrait_rgb.astype(np.float32) * 0.20 + graded_full.astype(np.float32) * 0.80),
                    0.0,
                    255.0,
                ).astype(np.uint8)
                sample_path = SAMPLES_DIR / f"{f['id']}.jpg"
                Image.fromarray(blended, mode="RGB").save(sample_path, format="JPEG", quality=90, subsampling=0)

                presets_list.append(
                    {
                        "id": f["id"],
                        "name": f["name"],
                        "name_en": f["name_en"],
                        "version": "1.0.0",
                        "type": "cube_3d",
                        "file": cube_filename,
                        "sha256": cube_sha256,
                        "size": 33,
                        "intensity": 80,
                        "license": "Proprietary-LKMagic",
                        "description": f["desc"],
                    }
                )
                print(f"   [{idx:02d}/40] {f['algo_id']} -> {cube_filename} + {sample_path.name}")

            manifest["presets"] = presets_list
            MANIFEST_PATH.write_text(json.dumps(manifest, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
            print(f"4. Updated {MANIFEST_PATH} with {len(presets_list)} total presets!")
        return 0
    finally:
        shutdown_lkmagic_background()


if __name__ == "__main__":
    sys.exit(main())
