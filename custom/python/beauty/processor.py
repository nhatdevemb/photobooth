"""
Beauty Processor — Master Coordinator for Beauty Retouch Pipeline
"""

from __future__ import annotations

import sys
import time
from typing import Any, Dict, List, Optional, Tuple, Union

import cv2
import numpy as np
from PIL import Image

from .config import BeautyConfig, resolve_beauty_config
from .face_detector import FaceData, FaceLandmarkerDetector
from .lkmagic_bridge import retouch_via_lkmagic
from .skin_mask import SkinMaskGenerator
from .skin_smoothing import apply_skin_smoothing
from .skin_brightening import apply_skin_brightening
from .skin_tone import apply_skin_tone_evening


class BeautyProcessor:
    """
    Orchestrates face detection, skin segmentation, smoothing, brightening,
    and tone evening with ROI acceleration and robust error isolation.
    """

    def __init__(
        self,
        config: Optional[BeautyConfig] = None,
        face_detector: Optional[FaceLandmarkerDetector] = None,
        skin_mask_gen: Optional[SkinMaskGenerator] = None,
    ) -> None:
        self.config = config or resolve_beauty_config()
        self._detector = face_detector
        self._mask_gen = skin_mask_gen
        self._closed = False

    def _ensure_models(self, cfg: BeautyConfig) -> None:
        if self._detector is None:
            self._detector = FaceLandmarkerDetector.from_config(cfg)
        if self._mask_gen is None:
            self._mask_gen = SkinMaskGenerator.from_config(cfg)

    def process(
        self,
        image_input: Union[Image.Image, np.ndarray],
        config: Optional[BeautyConfig] = None,
    ) -> Tuple[Union[Image.Image, np.ndarray], Dict[str, Any]]:
        """
        Process an image with Beauty Retouching.
        Accepts either PIL Image (RGB) or numpy array (H, W, 3 uint8 RGB).
        Returns a tuple: (result_image, audit_stats).
        Gracefully falls back to original image on errors or when 0 faces are found.
        """
        start_ns = time.monotonic_ns()
        cfg = config or self.config

        # Check input type
        is_pil = isinstance(image_input, Image.Image)
        if is_pil:
            image_rgb = np.asarray(image_input.convert("RGB"), dtype=np.uint8)
        else:
            image_rgb = image_input

        # Fast path: disabled or intensity all 0
        if not cfg.is_active() or image_rgb.size == 0:
            duration_ms = int((time.monotonic_ns() - start_ns) // 1_000_000)
            stats = {
                "status": "bypassed",
                "preset": cfg.preset,
                "faces_detected": 0,
                "duration_ms": duration_ms,
            }
            return (image_input, stats)

        try:
            # Explicit LK Magic engine mode (bypasses local MediaPipe pre-check)
            if cfg.engine == "lkmagic":
                lkmagic_rgb, lk_stats = retouch_via_lkmagic(image_rgb, cfg)
                lk_stats["faces_detected"] = lk_stats.get("faces_detected", 1)
                if is_pil:
                    return (Image.fromarray(lkmagic_rgb), lk_stats)
                return (lkmagic_rgb, lk_stats)

            self._ensure_models(cfg)
            orig_h, orig_w = image_rgb.shape[:2]

            # Step 1: Detect faces
            faces = self._detector.detect(image_rgb)
            if not faces:
                duration_ms = int((time.monotonic_ns() - start_ns) // 1_000_000)
                stats = {
                    "status": "no_faces_detected",
                    "preset": cfg.preset,
                    "faces_detected": 0,
                    "duration_ms": duration_ms,
                }
                return (image_input, stats)

            # Step 1b: Prefer LK Magic-WIN Native AI Engine (aipic.dll + aipic-host.exe) when in "auto" mode
            if cfg.engine == "auto":
                try:
                    lkmagic_rgb, lk_stats = retouch_via_lkmagic(image_rgb, cfg)
                    duration_ms = int((time.monotonic_ns() - start_ns) // 1_000_000)
                    lk_stats["faces_detected"] = len(faces)
                    lk_stats["duration_ms"] = duration_ms
                    if is_pil:
                        return (Image.fromarray(lkmagic_rgb), lk_stats)
                    return (lkmagic_rgb, lk_stats)
                except Exception as lk_exc:
                    sys.stderr.write(
                        f"[beauty_processor] LK Magic native bridge unavailable ({lk_exc}); falling back to local MediaPipe.\n"
                    )

            # Step 2: Generate Skin Mask with feature exclusion
            skin_mask = self._mask_gen.generate(image_rgb, faces)

            if float(skin_mask.max()) <= 1e-4:
                duration_ms = int((time.monotonic_ns() - start_ns) // 1_000_000)
                stats = {
                    "status": "empty_skin_mask",
                    "preset": cfg.preset,
                    "faces_detected": len(faces),
                    "duration_ms": duration_ms,
                }
                return (image_input, stats)

            # Step 3: Compute Union ROI of all faces with margin for efficient processing
            x_min = min(f.bbox[0] for f in faces)
            y_min = min(f.bbox[1] for f in faces)
            x_max = max(f.bbox[2] for f in faces)
            y_max = max(f.bbox[3] for f in faces)

            roi_margin_x = int((x_max - x_min) * 0.15)
            roi_margin_y = int((y_max - y_min) * 0.15)
            x1 = max(0, x_min - roi_margin_x)
            y1 = max(0, y_min - roi_margin_y)
            x2 = min(orig_w, x_max + roi_margin_x)
            y2 = min(orig_h, y_max + roi_margin_y)

            # Extract ROI
            roi_rgb = image_rgb[y1:y2, x1:x2].copy()
            roi_mask = skin_mask[y1:y2, x1:x2]

            # Step 4: Apply filters on ROI
            # 4a. Skin smoothing (frequency separation + bilateral)
            if cfg.smooth > 0.0:
                roi_rgb = apply_skin_smoothing(roi_rgb, roi_mask, smooth_intensity=cfg.smooth)

            # 4b. Skin brightening (CIELAB with highlight protection)
            if cfg.brighten > 0.0:
                roi_rgb = apply_skin_brightening(roi_rgb, roi_mask, brighten_intensity=cfg.brighten)

            # 4c. Skin tone evening (CIELAB chromatic evening)
            if cfg.tone > 0.0:
                roi_rgb = apply_skin_tone_evening(roi_rgb, roi_mask, tone_intensity=cfg.tone)

            # Re-insert processed ROI into output canvas
            output_rgb = image_rgb.copy()
            output_rgb[y1:y2, x1:x2] = roi_rgb

            duration_ms = int((time.monotonic_ns() - start_ns) // 1_000_000)
            stats = {
                "status": "success",
                "preset": cfg.preset,
                "faces_detected": len(faces),
                "smooth": round(cfg.smooth, 4),
                "brighten": round(cfg.brighten, 4),
                "tone": round(cfg.tone, 4),
                "duration_ms": duration_ms,
            }

            if is_pil:
                return (Image.fromarray(output_rgb), stats)
            return (output_rgb, stats)

        except Exception as exc:
            # Graceful isolation: never crash the photobooth pipeline on beauty errors
            sys.stderr.write(f"[beauty_processor] Warning: Beauty processing failed ({exc}). Retaining original image.\n")
            duration_ms = int((time.monotonic_ns() - start_ns) // 1_000_000)
            stats = {
                "status": "error_fallback",
                "error": str(exc),
                "preset": cfg.preset,
                "faces_detected": 0,
                "duration_ms": duration_ms,
            }
            return (image_input, stats)

    def close(self) -> None:
        if self._closed:
            return
        self._closed = True
        if self._detector is not None:
            self._detector.close()
            self._detector = None
        if self._mask_gen is not None:
            self._mask_gen.close()
            self._mask_gen = None

    def __enter__(self) -> BeautyProcessor:
        return self

    def __exit__(self, exc_type: Any, exc_val: Any, exc_tb: Any) -> None:
        self.close()

    def __del__(self) -> None:
        self.close()
