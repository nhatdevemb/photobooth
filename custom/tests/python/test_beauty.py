"""
Unit and Integration Tests for Beauty Retouch Engine.
Covers test cases BT-01 through BT-08 and BT-12 from plan/beauty-retouch.md.
"""

from __future__ import annotations

import sys
from pathlib import Path
import numpy as np
import pytest
from PIL import Image

# Ensure custom/python is on sys.path
PYTHON_DIR = Path(__file__).resolve().parent.parent.parent / "python"
if str(PYTHON_DIR) not in sys.path:
    sys.path.insert(0, str(PYTHON_DIR))

from beauty import (
    BeautyConfig,
    BeautyProcessor,
    FaceData,
    FaceLandmarkerDetector,
    SkinMaskGenerator,
    apply_skin_brightening,
    apply_skin_smoothing,
    apply_skin_tone_evening,
    resolve_beauty_config,
)

FIXTURES_DIR = Path(__file__).resolve().parent.parent / "fixtures"


# ---------------------------------------------------------------------------
# Configuration Tests
# ---------------------------------------------------------------------------

def test_beauty_config_presets() -> None:
    # Off preset
    cfg_off = resolve_beauty_config("off")
    assert cfg_off.preset == "off"
    assert cfg_off.smooth == 0.0
    assert cfg_off.brighten == 0.0
    assert cfg_off.tone == 0.0
    assert not cfg_off.is_active()

    # Natural preset
    cfg_nat = resolve_beauty_config("natural")
    assert cfg_nat.preset == "natural"
    assert cfg_nat.smooth == 0.20
    assert cfg_nat.brighten == 0.05
    assert cfg_nat.tone == 0.10
    assert cfg_nat.is_active()

    # Soft preset
    cfg_soft = resolve_beauty_config("soft")
    assert cfg_soft.preset == "soft"
    assert cfg_soft.smooth == 0.40
    assert cfg_soft.brighten == 0.10
    assert cfg_soft.tone == 0.15
    assert cfg_soft.is_active()

    # Korean Glow preset
    cfg_kg = resolve_beauty_config("korean-glow")
    assert cfg_kg.preset == "korean-glow"
    assert cfg_kg.smooth == 0.55
    assert cfg_kg.brighten == 0.15
    assert cfg_kg.tone == 0.20
    assert cfg_kg.is_active()

    # Aliases
    assert resolve_beauty_config("korean").preset == "korean-glow"
    assert resolve_beauty_config("none").preset == "off"


def test_beauty_config_clamping_and_overrides() -> None:
    # Overrides with percentage integers > 1
    cfg = resolve_beauty_config("natural", overrides={"smooth": 50, "brighten": 25, "tone": 30, "max_faces": 2})
    assert cfg.smooth == 0.50
    assert cfg.brighten == 0.25
    assert cfg.tone == 0.30
    assert cfg.max_faces == 2

    # Clamping out-of-bounds values
    cfg_clamped = BeautyConfig(smooth=1.5, brighten=-0.2, tone=2.0, max_faces=10)
    assert cfg_clamped.smooth == 1.0
    assert cfg_clamped.brighten == 0.0
    assert cfg_clamped.tone == 1.0
    assert cfg_clamped.max_faces == 4

    d = cfg.to_dict()
    assert d["preset"] == "natural"
    assert d["smooth"] == 0.5
    assert d["max_faces"] == 2


# ---------------------------------------------------------------------------
# Individual Filter Tests (Smoothing, Brightening, Tone Evening)
# ---------------------------------------------------------------------------

def test_skin_smoothing_zero_intensity() -> None:
    # BT-01: Zero smooth intensity does not alter the image
    img = np.random.randint(0, 256, (100, 100, 3), dtype=np.uint8)
    mask = np.ones((100, 100), dtype=np.float32)
    smoothed = apply_skin_smoothing(img, mask, smooth_intensity=0.0)
    np.testing.assert_array_equal(img, smoothed)


def test_skin_smoothing_empty_mask() -> None:
    img = np.random.randint(0, 256, (100, 100, 3), dtype=np.uint8)
    mask = np.zeros((100, 100), dtype=np.float32)
    smoothed = apply_skin_smoothing(img, mask, smooth_intensity=0.5)
    np.testing.assert_array_equal(img, smoothed)


def test_skin_smoothing_texture_preservation() -> None:
    # BT-08: Ensure pores/high frequency texture is preserved and not plastic
    np.random.seed(42)
    base = np.full((120, 120, 3), 180, dtype=np.uint8)
    # Add noise simulating skin pore texture
    noise = np.random.randint(-15, 15, (120, 120, 3)).astype(np.int16)
    textured_skin = np.clip(base.astype(np.int16) + noise, 0, 255).astype(np.uint8)
    mask = np.ones((120, 120), dtype=np.float32)

    smoothed = apply_skin_smoothing(textured_skin, mask, smooth_intensity=0.55, texture_retention=0.8)

    # Standard deviation should be reduced (smoother), but NOT zero (pores preserved)
    orig_std = np.std(textured_skin)
    smoothed_std = np.std(smoothed)
    assert smoothed_std < orig_std
    assert smoothed_std > 2.0  # Not completely flattened/plastic


def test_skin_brightening_zero_intensity() -> None:
    img = np.random.randint(0, 256, (100, 100, 3), dtype=np.uint8)
    mask = np.ones((100, 100), dtype=np.float32)
    brightened = apply_skin_brightening(img, mask, brighten_intensity=0.0)
    np.testing.assert_array_equal(img, brightened)


def test_skin_brightening_highlight_rolloff() -> None:
    # BT-07: Highlight rolloff prevents blowing out high-luminance pixels
    # Create near-white pixels (e.g. 250) and midtone skin pixels (e.g. 150)
    midtones = np.full((50, 50, 3), 150, dtype=np.uint8)
    highlights = np.full((50, 50, 3), 250, dtype=np.uint8)
    mask = np.ones((50, 50), dtype=np.float32)

    mid_bright = apply_skin_brightening(midtones, mask, brighten_intensity=0.15)
    high_bright = apply_skin_brightening(highlights, mask, brighten_intensity=0.15)

    delta_mid = float(mid_bright.mean()) - 150.0
    delta_high = float(high_bright.mean()) - 250.0

    # Midtones should receive much more lift than highlights due to rolloff
    assert delta_mid > delta_high
    assert high_bright.max() <= 255


def test_skin_tone_evening_zero_intensity() -> None:
    img = np.random.randint(0, 256, (100, 100, 3), dtype=np.uint8)
    mask = np.ones((100, 100), dtype=np.float32)
    toned = apply_skin_tone_evening(img, mask, tone_intensity=0.0)
    np.testing.assert_array_equal(img, toned)


# ---------------------------------------------------------------------------
# Pipeline & Processor Tests
# ---------------------------------------------------------------------------

def test_processor_off_preset_noop() -> None:
    # BT-01: Off preset leaves any image byte-identical
    img_array = np.random.randint(0, 256, (200, 200, 3), dtype=np.uint8)
    processor = BeautyProcessor()
    cfg_off = resolve_beauty_config("off")

    processed, stats = processor.process(img_array, cfg_off)
    assert stats["status"] == "bypassed"
    np.testing.assert_array_equal(img_array, processed)
    processor.close()


def test_processor_no_faces_graceful_fallback() -> None:
    # BT-04: Image with no faces skips beauty without errors
    # Solid blue image (no faces)
    no_face_img = np.zeros((300, 300, 3), dtype=np.uint8)
    no_face_img[:, :, 2] = 200

    processor = BeautyProcessor()
    cfg_nat = resolve_beauty_config("natural")

    processed, stats = processor.process(no_face_img, cfg_nat)
    assert stats["status"] == "no_faces_detected"
    assert stats["faces_detected"] == 0
    np.testing.assert_array_equal(no_face_img, processed)
    processor.close()


def test_processor_missing_model_fallback() -> None:
    # BT-12: If model file is missing or invalid, fallback gracefully without crashing
    dummy_img = np.zeros((100, 100, 3), dtype=np.uint8)
    bad_cfg = BeautyConfig(
        preset="natural",
        smooth=0.2,
        face_model_path="/path/to/nonexistent_model.task",
    )

    processor = BeautyProcessor()
    processed, stats = processor.process(dummy_img, bad_cfg)
    assert stats["status"] == "error_fallback"
    assert "error" in stats
    np.testing.assert_array_equal(dummy_img, processed)
    processor.close()


def test_processor_with_fixture_photo() -> None:
    # Test with real fixture photo if available
    fixture_path = FIXTURES_DIR / "slot-1-v1.jpg"
    if not fixture_path.is_file():
        pytest.skip("Fixture photo not found")

    with Image.open(fixture_path) as img:
        orig = img.convert("RGB")
        w, h = orig.size

    with BeautyProcessor() as processor:
        for preset in ["natural", "soft", "korean-glow"]:
            cfg = resolve_beauty_config(preset)
            processed_pil, stats = processor.process(orig, cfg)

            assert isinstance(processed_pil, Image.Image)
            assert processed_pil.size == (w, h)
            assert stats["preset"] == preset
            assert "duration_ms" in stats
