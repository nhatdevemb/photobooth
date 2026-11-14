"""
Skin Mask Generator — MediaPipe SelfieMulticlass & Facial Component Exclusion
"""

from __future__ import annotations

import os
from pathlib import Path
from typing import Any, List, Optional

import cv2
import numpy as np
import mediapipe as mp
from mediapipe.tasks import python
from mediapipe.tasks.python import vision

from .config import BeautyConfig
from .face_detector import FaceData

DEFAULT_SEGMENTER_MODEL_PATH = (
    Path(__file__).resolve().parent.parent.parent / "models" / "beauty" / "selfie_multiclass_256x256.tflite"
)

# In selfie_multiclass_256x256.tflite:
# 0: background, 1: hair, 2: body-skin, 3: face-skin, 4: clothes, 5: others
FACE_SKIN_CATEGORY_INDEX = 3


class SkinMaskGenerator:
    """
    Generates a feathered skin mask specifically for face regions,
    strictly excluding eyes, lips, and eyebrows to preserve facial sharpness.
    """

    def __init__(
        self,
        model_path: Optional[str | Path] = None,
        confidence_threshold: float = 0.25,
    ) -> None:
        self._segmenter: Optional[vision.ImageSegmenter] = None
        self.confidence_threshold = confidence_threshold

        if model_path is None:
            model_path = os.getenv("BEAUTY_SEGMENTER_MODEL_PATH") or DEFAULT_SEGMENTER_MODEL_PATH
        self.model_path = Path(model_path)
        if not self.model_path.is_file():
            raise FileNotFoundError(f"Segmenter model file not found: {self.model_path}")

        base_options = python.BaseOptions(model_asset_path=str(self.model_path))
        options = vision.ImageSegmenterOptions(
            base_options=base_options,
            running_mode=vision.RunningMode.IMAGE,
            output_category_mask=False,
            output_confidence_masks=True,
        )
        self._segmenter = vision.ImageSegmenter.create_from_options(options)

    @classmethod
    def from_config(cls, config: BeautyConfig) -> SkinMaskGenerator:
        return cls(model_path=config.segmenter_model_path)

    def generate(self, image_rgb: np.ndarray, faces: List[FaceData]) -> np.ndarray:
        """
        Generate a float32 mask [0.0, 1.0] of shape (H, W).
        If no faces are provided, returns an all-zero mask.
        """
        if self._segmenter is None:
            raise RuntimeError("SkinMaskGenerator has been closed.")

        orig_h, orig_w = image_rgb.shape[:2]
        if orig_h <= 0 or orig_w <= 0 or not faces:
            return np.zeros((orig_h, orig_w), dtype=np.float32)

        # Ensure C-contiguous
        if not image_rgb.flags["C_CONTIGUOUS"]:
            image_rgb = np.ascontiguousarray(image_rgb)

        mp_image = mp.Image(image_format=mp.ImageFormat.SRGB, data=image_rgb)
        result = self._segmenter.segment(mp_image)

        if not result.confidence_masks or len(result.confidence_masks) <= FACE_SKIN_CATEGORY_INDEX:
            return np.zeros((orig_h, orig_w), dtype=np.float32)

        # Extract face-skin confidence mask (category 3)
        raw_mask = result.confidence_masks[FACE_SKIN_CATEGORY_INDEX].numpy_view()
        if raw_mask.ndim == 3 and raw_mask.shape[2] == 1:
            raw_mask = raw_mask.squeeze(axis=2)

        # Resize to original resolution
        if (raw_mask.shape[1], raw_mask.shape[0]) != (orig_w, orig_h):
            skin_mask = cv2.resize(raw_mask, (orig_w, orig_h), interpolation=cv2.INTER_LINEAR)
        else:
            skin_mask = raw_mask.copy()

        # Apply confidence threshold
        skin_mask = np.where(skin_mask >= self.confidence_threshold, skin_mask, 0.0).astype(np.float32)

        # 1. Restrict to detected face bounds (face oval hulls dilated slightly)
        face_region_mask = np.zeros((orig_h, orig_w), dtype=np.uint8)
        for face in faces:
            # Fill face oval hull
            if len(face.face_oval_hull) >= 3:
                cv2.fillPoly(face_region_mask, [face.face_oval_hull], 255)
            else:
                x1, y1, x2, y2 = face.bbox
                cv2.rectangle(face_region_mask, (x1, y1), (x2, y2), 255, -1)

        # Dilate face region slightly to include temple, jawline, forehead
        kernel_face_radius = max(5, int(round(min(orig_h, orig_w) * 0.02)))
        kernel_face = cv2.getStructuringElement(
            cv2.MORPH_ELLIPSE, (kernel_face_radius * 2 + 1, kernel_face_radius * 2 + 1)
        )
        face_region_mask = cv2.dilate(face_region_mask, kernel_face)

        # Intersect skin mask with face regions
        skin_mask = skin_mask * (face_region_mask.astype(np.float32) / 255.0)

        # 2. Exclude sensitive regions: eyes, lips, eyebrows
        exclusion_mask = np.zeros((orig_h, orig_w), dtype=np.uint8)
        for face in faces:
            for hull in (
                face.left_eye_hull,
                face.right_eye_hull,
                face.lips_hull,
                face.left_eyebrow_hull,
                face.right_eyebrow_hull,
            ):
                if len(hull) >= 3:
                    cv2.fillPoly(exclusion_mask, [hull], 255)

        # Dilate exclusion mask slightly to cover eye lashes, iris, lip borders
        kernel_excl_radius = max(3, int(round(min(orig_h, orig_w) * 0.006)))
        kernel_excl = cv2.getStructuringElement(
            cv2.MORPH_ELLIPSE, (kernel_excl_radius * 2 + 1, kernel_excl_radius * 2 + 1)
        )
        exclusion_mask = cv2.dilate(exclusion_mask, kernel_excl)

        # Subtract exclusion regions
        skin_mask = skin_mask * (1.0 - (exclusion_mask.astype(np.float32) / 255.0))

        # 3. Gaussian feathering for smooth natural transition edges
        feather_radius = max(3, int(round(min(orig_h, orig_w) * 0.008)))
        if feather_radius % 2 == 0:
            feather_radius += 1
        skin_mask = cv2.GaussianBlur(skin_mask, (feather_radius, feather_radius), 0)

        return np.clip(skin_mask, 0.0, 1.0).astype(np.float32)

    def close(self) -> None:
        if self._segmenter is not None:
            try:
                self._segmenter.close()
            except Exception:
                pass
            self._segmenter = None

    def __enter__(self) -> SkinMaskGenerator:
        return self

    def __exit__(self, exc_type: Any, exc_val: Any, exc_tb: Any) -> None:
        self.close()

    def __del__(self) -> None:
        self.close()
