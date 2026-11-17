import hashlib
import io
import json
import sys
from pathlib import Path

import numpy as np
import pytest
from PIL import Image, ImageDraw, ImageFilter, ImageOps

CUSTOM_DIR = Path(__file__).resolve().parent.parent.parent
PYTHON_DIR = CUSTOM_DIR / "python"
if str(PYTHON_DIR) not in sys.path:
    sys.path.insert(0, str(PYTHON_DIR))

from cube_lut import LutParseError, apply_cube_lut_rgb, parse_cube_file
from render_pipeline import (
    PipelineError,
    center_crop_and_resize,
    normalize_orientation_and_srgb,
    run_render_from_manifest,
    sha256_file,
    validate_and_load_jpeg,
)

PRESETS_DIR = CUSTOM_DIR / "presets"
FIXTURES_DIR = CUSTOM_DIR / "tests" / "fixtures"
TEMPLATE_PATH = CUSTOM_DIR / "templates" / "vertical-three-v1.json"


def make_cube_file(tmp_path: Path, name: str, size: int, fn) -> Path:
    lin = np.linspace(0.0, 1.0, size)
    lines = [
        f'TITLE "{name}"',
        "DOMAIN_MIN 0.0 0.0 0.0",
        "DOMAIN_MAX 1.0 1.0 1.0",
        f"LUT_3D_SIZE {size}",
    ]
    for b in lin:
        for g in lin:
            for r in lin:
                ro, go, bo = fn(r, g, b)
                lines.append(f"{ro:.6f} {go:.6f} {bo:.6f}")
    p = tmp_path / f"{name}.cube"
    p.write_text("\n".join(lines) + "\n", encoding="utf-8")
    return p


def test_identity_lut_exact_preservation(tmp_path: Path) -> None:
    lut_file = make_cube_file(tmp_path, "identity", 33, lambda r, g, b: (r, g, b))
    lut = parse_cube_file(lut_file, expected_size=33)
    assert lut.size == 33

    # Test full 0..255 ramp across all channels
    ramp = np.arange(256, dtype=np.uint8)
    grid_r, grid_g = np.meshgrid(ramp, ramp, indexing="ij")
    grid_b = (grid_r // 2 + grid_g // 2).astype(np.uint8)
    img = np.stack([grid_r, grid_g, grid_b], axis=-1)

    out = apply_cube_lut_rgb(img, lut)
    max_diff = int(np.max(np.abs(out.astype(np.int32) - img.astype(np.int32))))
    assert max_diff <= 1


def test_channel_ramp_ordering_r_fastest(tmp_path: Path) -> None:
    # Verify R changes fastest, then G, then B per .cube spec
    # Map (R, G, B) -> (B, R, G * 0.5)
    lut_file = make_cube_file(tmp_path, "swap", 33, lambda r, g, b: (b, r, g * 0.5))
    lut = parse_cube_file(lut_file, expected_size=33)

    sample = np.array([[[0.25, 0.50, 0.75]]], dtype=np.float32)
    out = apply_cube_lut_rgb(sample, lut)
    np.testing.assert_allclose(out[0, 0], [0.75, 0.25, 0.25], atol=1e-4)


@pytest.mark.parametrize(
    "bad_content,expected_msg",
    [
        ("TITLE \"NoSize\"\n0.0 0.0 0.0\n", "LUT_3D_SIZE"),
        ("LUT_1D_SIZE 33\n0.0 0.0 0.0\n", "1D LUT"),
        ("LUT_3D_SIZE 17\n0.0 0.0 0.0\n", "Expected LUT_3D_SIZE 33"),
        ("LUT_3D_SIZE 33\nDOMAIN_MIN -1.0 0.0 0.0\nDOMAIN_MAX 1.0 1.0 1.0\n", "domain must be 0..1"),
        ("LUT_3D_SIZE 33\n0.0 0.0 0.0\n1.0 1.0 1.0\n", "Expected 35937 data rows"),
        ("LUT_3D_SIZE 33\n0.0 1.5 0.0\n", "outside [0..1]"),
        ("LUT_3D_SIZE 33\n0.0 NaN 0.0\n", "Non-finite"),
    ],
)
def test_malformed_lut_rejected(tmp_path: Path, bad_content: str, expected_msg: str) -> None:
    p = tmp_path / "bad.cube"
    p.write_text(bad_content, encoding="utf-8")
    with pytest.raises(LutParseError) as exc_info:
        parse_cube_file(p, expected_size=33)
    assert expected_msg in str(exc_info.value)


def test_exif_orientation_and_icc_handling() -> None:
    # 1. EXIF orientation=6 (stored 600x1200, transposed to 1200x600)
    exif_path = FIXTURES_DIR / "exif-orientation-6.jpg"
    img, _, raw = validate_and_load_jpeg(exif_path)
    assert img.size == (600, 1200)
    norm_img, icc_status = normalize_orientation_and_srgb(img, raw, 1)
    assert norm_img.size == (1200, 600)
    assert icc_status == "converted_to_srgb"

    # 2. Missing ICC profile -> assumed_srgb
    no_icc_path = FIXTURES_DIR / "no-icc-profile.jpg"
    img2, _, raw2 = validate_and_load_jpeg(no_icc_path)
    _, icc_status2 = normalize_orientation_and_srgb(img2, raw2, 2)
    assert icc_status2 == "assumed_srgb"

    # 3. Declared but corrupt ICC profile -> INVALID_ICC_PROFILE
    corrupt_icc_path = FIXTURES_DIR / "corrupt-icc.jpg"
    img3, _, raw3 = validate_and_load_jpeg(corrupt_icc_path)
    with pytest.raises(PipelineError) as exc_info:
        normalize_orientation_and_srgb(img3, raw3, 3)
    assert exc_info.value.code == "INVALID_ICC_PROFILE"


def test_input_validation_limits_and_corrupt_jpeg() -> None:
    # Corrupt truncated JPEG
    with pytest.raises(PipelineError) as exc_info:
        validate_and_load_jpeg(FIXTURES_DIR / "corrupt-truncated.jpg")
    assert exc_info.value.code == "CORRUPT_JPEG"

    # File byte size limit
    with pytest.raises(PipelineError) as exc_info2:
        validate_and_load_jpeg(FIXTURES_DIR / "slot-1-v1.jpg", max_jpeg_bytes=1024)
    assert exc_info2.value.code == "IMAGE_TOO_LARGE"

    # Pixel count limit
    with pytest.raises(PipelineError) as exc_info3:
        validate_and_load_jpeg(FIXTURES_DIR / "slot-1-v1.jpg", max_image_pixels=100_000)
    assert exc_info3.value.code == "IMAGE_PIXELS_EXCEEDED"


def test_tc07_original_lut_preserves_colors_and_originals(tmp_path: Path) -> None:
    p1 = FIXTURES_DIR / "slot-1-v1.jpg"
    p2 = FIXTURES_DIR / "slot-2-v1.jpg"
    p3 = FIXTURES_DIR / "slot-3-v1.jpg"
    orig_hashes = [sha256_file(p) for p in (p1, p2, p3)]

    out_print = tmp_path / "print-orig.jpg"
    out_prev = tmp_path / "prev-orig.jpg"
    manifest = {
        "photos": [
            {"slot": 1, "path": str(p1), "sha256": orig_hashes[0]},
            {"slot": 2, "path": str(p2), "sha256": orig_hashes[1]},
            {"slot": 3, "path": str(p3), "sha256": orig_hashes[2]},
        ],
        "lut_id": "original",
        "lut_version": "1.0.0",
        "lut_path": None,
        "template_path": str(TEMPLATE_PATH),
        "output_print_path": str(out_print),
        "output_preview_path": str(out_prev),
    }
    res = run_render_from_manifest(manifest)
    assert res["width"] == 1181
    assert res["height"] == 1748
    assert res["preview_width"] == 676
    assert res["preview_height"] == 1000

    # Originals untouched
    assert [sha256_file(p) for p in (p1, p2, p3)] == orig_hashes

    # Compare Slot 1 crop region (x=35..1146, y=35..578) against direct center-crop of p1
    rendered = Image.open(out_print)
    assert rendered.size == (1181, 1748)
    assert rendered.info.get("dpi") == (300, 300)
    assert "icc_profile" in rendered.info
    assert not rendered.getexif()

    slot1_crop_from_collage = np.asarray(rendered.crop((35, 35, 35 + 1111, 35 + 543)), dtype=np.int32)
    expected_slot1 = np.asarray(center_crop_and_resize(Image.open(p1).convert("RGB"), 1111, 543), dtype=np.int32)
    mean_abs_diff = float(np.mean(np.abs(slot1_crop_from_collage - expected_slot1)))
    assert mean_abs_diff < 1.5  # Within JPEG Q95 codec tolerance


def test_tc08_and_tc09_presets_distinct_and_deterministic(tmp_path: Path) -> None:
    presets_manifest = json.loads((PRESETS_DIR / "manifest.json").read_text(encoding="utf-8"))
    output_hashes = {}
    arrays_by_preset = {}
    core_ids = {"original", "korean-soft", "film-400", "vintage-fade"}

    for preset in presets_manifest["presets"]:
        pid = preset["id"]
        if preset.get("file"):
            cube_file = PRESETS_DIR / preset["file"]
            assert cube_file.is_file(), f"Missing .cube file for preset {pid}"
            assert sha256_file(cube_file) == preset["sha256"], f"SHA-256 mismatch for preset {pid}"
            lut = parse_cube_file(cube_file, expected_size=33)
            assert lut.size == 33
        sample_file = CUSTOM_DIR / "public" / "assets" / "samples" / f"{pid}.jpg"
        assert sample_file.is_file(), f"Missing sample preview for preset {pid}"

        if pid not in core_ids:
            continue

        out_print = tmp_path / f"print-{pid}.jpg"
        out_prev = tmp_path / f"prev-{pid}.jpg"
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
        res1 = run_render_from_manifest(manifest)
        output_hashes[pid] = res1["sha256"]

        img = Image.open(out_print)
        arrays_by_preset[pid] = np.asarray(img, dtype=np.float32)

        # TC-09: Render a second time and verify identical deterministic SHA-256 and input_signature
        out_print2 = tmp_path / f"print-{pid}-2.jpg"
        out_prev2 = tmp_path / f"prev-{pid}-2.jpg"
        manifest["output_print_path"] = str(out_print2)
        manifest["output_preview_path"] = str(out_prev2)
        res2 = run_render_from_manifest(manifest)
        assert res2["sha256"] == res1["sha256"]
        assert res2["input_signature"] == res1["input_signature"]

    # TC-08: All 4 core presets produce distinct hashes and visibly distinct pixel arrays
    assert len(set(output_hashes.values())) == 4
    pids = list(arrays_by_preset.keys())
    for i in range(len(pids)):
        for j in range(i + 1, len(pids)):
            mae = float(np.mean(np.abs(arrays_by_preset[pids[i]] - arrays_by_preset[pids[j]])))
            assert mae > 3.5, f"Presets {pids[i]} and {pids[j]} are not sufficiently distinct (MAE={mae})"


def test_slot_order_strictly_enforced(tmp_path: Path) -> None:
    m_normal = {
        "photos": [
            {"slot": 1, "path": str(FIXTURES_DIR / "slot-1-v1.jpg")},
            {"slot": 2, "path": str(FIXTURES_DIR / "slot-2-v1.jpg")},
            {"slot": 3, "path": str(FIXTURES_DIR / "slot-3-v1.jpg")},
        ],
        "lut_id": "original",
        "lut_version": "1.0.0",
        "lut_path": None,
        "template_path": str(TEMPLATE_PATH),
        "output_print_path": str(tmp_path / "normal.jpg"),
        "output_preview_path": str(tmp_path / "normal-prev.jpg"),
    }
    m_swapped = {
        "photos": [
            {"slot": 1, "path": str(FIXTURES_DIR / "slot-3-v1.jpg")},
            {"slot": 2, "path": str(FIXTURES_DIR / "slot-2-v1.jpg")},
            {"slot": 3, "path": str(FIXTURES_DIR / "slot-1-v1.jpg")},
        ],
        "lut_id": "original",
        "lut_version": "1.0.0",
        "lut_path": None,
        "template_path": str(TEMPLATE_PATH),
        "output_print_path": str(tmp_path / "swapped.jpg"),
        "output_preview_path": str(tmp_path / "swapped-prev.jpg"),
    }
    r_norm = run_render_from_manifest(m_normal)
    r_swap = run_render_from_manifest(m_swapped)
    assert r_norm["sha256"] != r_swap["sha256"]
    assert r_norm["input_signature"] != r_swap["input_signature"]


def test_single_layout_composites_overlay_after_lut(tmp_path: Path) -> None:
    overlay = Image.new('RGBA', (1181, 1748), (0, 0, 0, 0))
    from PIL import ImageDraw
    ImageDraw.Draw(overlay).rectangle((0, 0, 100, 25), fill=(240, 20, 60, 255))
    overlay_path = tmp_path / 'overlay.png'
    overlay.save(overlay_path)
    manifest = {
        'photos': [{'slot': 1, 'path': str(FIXTURES_DIR / 'slot-1-v1.jpg')}],
        'template': {
            'id': 'sample-single', 'version': '1.0.0', 'layout_id': 'single',
            'canvas': {'width': 1181, 'height': 1748},
            'slots': [{'slot': 1, 'x': 35, 'y': 35, 'width': 1111, 'height': 1678}],
            'overlay_path': str(overlay_path),
            'resource_hashes': {'overlay.png': sha256_file(overlay_path)},
        },
        'lut_id': 'film-400', 'lut_path': str(PRESETS_DIR / 'film-400.cube'),
        'output_print_path': str(tmp_path / 'print.jpg'),
        'output_preview_path': str(tmp_path / 'preview.jpg'),
    }
    result = run_render_from_manifest(manifest)
    output = Image.open(result['image_path'])
    assert output.size == (1181, 1748)
    assert max(abs(a-b) for a, b in zip(output.getpixel((50, 10)), (240, 20, 60))) <= 3
    assert output.info['dpi'] == (300, 300)
    assert result['sha256'] == sha256_file(Path(result['image_path']))
    manifest['lut_sha256'] = '0' * 64
    with pytest.raises(PipelineError, match='LUT.*hash'):
        run_render_from_manifest(manifest)
    manifest.pop('lut_sha256')
    overlay.putpixel((50, 10), (0, 0, 0, 255))
    overlay.save(overlay_path)
    with pytest.raises(PipelineError, match='hash'):
        run_render_from_manifest(manifest)


def test_lut_intensity_zero_preserves_original_and_is_signed(tmp_path: Path) -> None:
    manifest = {
        'photos': [{'slot':1, 'path':str(FIXTURES_DIR/'slot-1-v1.jpg')}],
        'template': {'id':'single', 'version':'1.0.0', 'canvas':{'width':1181,'height':1748}, 'slots':[{'slot':1,'x':35,'y':35,'width':1111,'height':1678}]},
        'lut_id':'original', 'output_print_path':str(tmp_path/'print.jpg'), 'output_preview_path':str(tmp_path/'preview.jpg'),
    }
    original = run_render_from_manifest(manifest)
    manifest.update(lut_id='film-400', lut_path=str(PRESETS_DIR/'film-400.cube'), lut_intensity=0)
    zero = run_render_from_manifest(manifest)
    assert zero['sha256'] == original['sha256']
    manifest['lut_intensity'] = 80
    eighty = run_render_from_manifest(manifest)
    manifest['lut_intensity'] = 100
    full = run_render_from_manifest(manifest)
    assert len({zero['sha256'], eighty['sha256'], full['sha256']}) == 3
    assert len({zero['input_signature'], eighty['input_signature'], full['input_signature']}) == 3
    manifest['lut_intensity'] = 101
    with pytest.raises(PipelineError, match='intensity'):
        run_render_from_manifest(manifest)


@pytest.mark.parametrize('package', sorted((CUSTOM_DIR / 'templates' / 'bundled').glob('*/template.json')), ids=lambda p: p.parent.name)
def test_all_shipped_templates_render_exact_geometry_and_immutable_inputs(tmp_path: Path, package: Path) -> None:
    item = json.loads(package.read_text(encoding='utf-8'))
    layout = json.loads((CUSTOM_DIR / 'layouts' / f"{item['layout_id']}.json").read_text(encoding='utf-8'))
    count = layout['capture_count']
    source_colors = [(220, 30, 40), (30, 200, 60), (40, 60, 220), (230, 200, 30)]
    photos = []
    for slot in range(1, count+1):
        photo = tmp_path / f'{slot}.jpg'
        source = Image.new('RGB', (1200, 800), source_colors[slot-1])
        draw = ImageDraw.Draw(source)
        draw.rectangle((0,0,179,799), fill='white')
        draw.rectangle((1020,0,1199,799), fill='black')
        draw.rectangle((0,0,1199,99), fill=(180,40,170))
        draw.rectangle((0,700,1199,799), fill=(20,180,170))
        source.save(photo, quality=100, subsampling=0)
        photos.append({'slot': slot, 'path': str(photo), 'sha256': sha256_file(photo)})
    snapshot = dict(layout, id=item['id'], overlay_path=str(package.parent / 'overlay.png'), background_path=str(package.parent / 'background.png'), resource_hashes=item['hashes'])
    result = run_render_from_manifest({'template': snapshot, 'photos': photos, 'lut_id': 'original', 'output_print_path': str(tmp_path/'print.jpg'), 'output_preview_path': str(tmp_path/'preview.jpg')})
    image = Image.open(result['image_path'])
    for slot in layout['slots']:
        center = (slot['x']+slot['width']//2, slot['y']+slot['height']//2)
        assert max(abs(a-b) for a,b in zip(image.getpixel(center), source_colors[slot['slot']-1])) <= 4
        expected = ImageOps.fit(Image.open(photos[slot['slot']-1]['path']), (slot['width'], slot['height']), method=Image.Resampling.LANCZOS)
        # Compare an independent crop reference away from artwork at the slot edges.
        region = (50,50,slot['width']-50,slot['height']-50)
        actual = image.crop((slot['x'],slot['y'],slot['x']+slot['width'],slot['y']+slot['height'])).crop(region)
        assert np.abs(np.asarray(actual,dtype=float)-np.asarray(expected.crop(region),dtype=float)).mean() < 3
    overlay = Image.open(package.parent/'overlay.png')
    assert overlay.mode == 'RGBA'
    assert overlay.getchannel('A').getextrema()[0] == 0
    if item['style'] != 'minimal':
        opaque = np.argwhere(np.asarray(overlay.getchannel('A').filter(ImageFilter.MinFilter(9))) == 255)
        assert len(opaque) > 0
        y,x = opaque[len(opaque)//2]
        assert max(abs(a-b) for a,b in zip(image.getpixel((int(x),int(y))), overlay.getpixel((int(x),int(y)))[:3])) <= 12
    assert image.size == (1181,1748)
    assert image.info['dpi'] == (300,300)
    assert image.info['icc_profile']
    assert max(Image.open(result['preview_path']).size) == 1000
    preview = Image.open(result['preview_path'])
    expected_preview = image.resize(preview.size, Image.Resampling.LANCZOS)
    assert np.abs(np.asarray(preview,dtype=float)-np.asarray(expected_preview,dtype=float)).mean() < 3
    assert [sha256_file(Path(photo['path'])) for photo in photos] == [photo['sha256'] for photo in photos]
    second = run_render_from_manifest({'template': snapshot, 'photos': photos, 'lut_id': 'original', 'output_print_path': str(tmp_path/'again.jpg'), 'output_preview_path': str(tmp_path/'again-preview.jpg')})
    assert result['sha256'] == second['sha256']
    assert result['input_signature'] == second['input_signature']


def test_render_with_beauty_preset_and_determinism(tmp_path: Path) -> None:
    photos = [
        {"slot": 1, "path": str(FIXTURES_DIR / "slot-1-v1.jpg")},
        {"slot": 2, "path": str(FIXTURES_DIR / "slot-2-v1.jpg")},
        {"slot": 3, "path": str(FIXTURES_DIR / "slot-3-v1.jpg")},
    ]

    base_manifest = {
        "photos": photos,
        "lut_id": "original",
        "template_path": str(TEMPLATE_PATH),
        "output_print_path": str(tmp_path / "print_off.jpg"),
        "output_preview_path": str(tmp_path / "preview_off.jpg"),
        "beauty_preset": "off",
    }
    res_off = run_render_from_manifest(base_manifest)
    assert res_off["status"] == "ok"
    assert res_off["beauty_preset"] == "off"
    assert len(res_off["beauty_audit"]) == 3

    # Render with Natural Beauty preset
    beauty_manifest = dict(
        base_manifest,
        output_print_path=str(tmp_path / "print_natural_1.jpg"),
        output_preview_path=str(tmp_path / "preview_natural_1.jpg"),
        beauty_preset="natural",
    )
    res_nat_1 = run_render_from_manifest(beauty_manifest)
    assert res_nat_1["status"] == "ok"
    assert res_nat_1["beauty_preset"] == "natural"
    assert res_nat_1["beauty_config"]["smooth"] == 0.20
    assert len(res_nat_1["beauty_audit"]) == 3

    # Signatures must differ between off and natural
    assert res_nat_1["input_signature"] != res_off["input_signature"]

    # Re-rendering with identical settings must produce identical deterministic results
    beauty_manifest_2 = dict(
        base_manifest,
        output_print_path=str(tmp_path / "print_natural_2.jpg"),
        output_preview_path=str(tmp_path / "preview_natural_2.jpg"),
        beauty_preset="natural",
    )
    res_nat_2 = run_render_from_manifest(beauty_manifest_2)
    assert res_nat_2["sha256"] == res_nat_1["sha256"]
    assert res_nat_2["input_signature"] == res_nat_1["input_signature"]

