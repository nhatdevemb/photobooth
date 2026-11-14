"""
Face Landmarker Detector — MediaPipe Vision Face Landmarker Wrapper
"""

from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path
from typing import List, Optional, Tuple

import cv2
import numpy as np
import mediapipe as mp
from mediapipe.tasks import python
from mediapipe.tasks.python import vision
from mediapipe.tasks.python.vision import FaceLandmarksConnections

from .config import BeautyConfig

DEFAULT_FACE_MODEL_PATH = Path(__file__).resolve().parent.parent.parent / "models" / "beauty" / "face_landmarker.task"

# Pre-extract landmark indices for facial components
LEFT_EYE_INDICES = sorted(
    {c.start for c in FaceLandmarksConnections.FACE_LANDMARKS_LEFT_EYE}
    | {c.end for c in FaceLandmarksConnections.FACE_LANDMARKS_LEFT_EYE}
)
RIGHT_EYE_INDICES = sorted(
    {c.start for c in FaceLandmarksConnections.FACE_LANDMARKS_RIGHT_EYE}
    | {c.end for c in FaceLandmarksConnections.FACE_LANDMARKS_RIGHT_EYE}
)
LIPS_INDICES = sorted(
    {c.start for c in FaceLandmarksConnections.FACE_LANDMARKS_LIPS}
    | {c.end for c in FaceLandmarksConnections.FACE_LANDMARKS_LIPS}
)
LEFT_EYEBROW_INDICES = sorted(
    {c.start for c in FaceLandmarksConnections.FACE_LANDMARKS_LEFT_EYEBROW}
    | {c.end for c in FaceLandmarksConnections.FACE_LANDMARKS_LEFT_EYEBROW}
)
RIGHT_EYEBROW_INDICES = sorted(
    {c.start for c in FaceLandmarksConnections.FACE_LANDMARKS_RIGHT_EYEBROW}
    | {c.end for c in FaceLandmarksConnections.FACE_LANDMARKS_RIGHT_EYEBROW}
)
FACE_OVAL_INDICES = sorted(
    {c.start for c in FaceLandmarksConnections.FACE_LANDMARKS_FACE_OVAL}
    | {c.end for c in FaceLandmarksConnections.FACE_LANDMARKS_FACE_OVAL}
)


@dataclass
class FaceData:
    bbox: Tuple[int, int, int, int]  # (xmin, ymin, xmax, ymax) in image pixel coords
    landmarks: np.ndarray             # (N, 2) in image pixel coords (int32)
    left_eye_hull: np.ndarray         # (K, 1, 2) convex hull
    right_eye_hull: np.ndarray        # (K, 1, 2) convex hull
    lips_hull: np.ndarray             # (K, 1, 2) convex hull
    left_eyebrow_hull: np.ndarray     # (K, 1, 2) convex hull
    right_eyebrow_hull: np.ndarray    # (K, 1, 2) convex hull
    face_oval_hull: np.ndarray        # (K, 1, 2) convex hull


class FaceLandmarkerDetector:
    """
    Wrapper around MediaPipe FaceLandmarker for robust multi-face detection (1-4 faces).
    """

    def __init__(
        self,
        model_path: Optional[str | Path] = None,
        max_faces: int = 4,
        min_detection_confidence: float = 0.5,
        min_presence_confidence: float = 0.5,
        min_tracking_confidence: float = 0.5,
        inference_max_edge: int = 1024,
    ) -> None:
        self._landmarker: Optional[vision.FaceLandmarker] = None
        self.max_faces = max_faces
        self.inference_max_edge = inference_max_edge

        if model_path is None:
            model_path = os.getenv("BEAUTY_FACE_MODEL_PATH") or DEFAULT_FACE_MODEL_PATH
        self.model_path = Path(model_path)
        if not self.model_path.is_file():
            raise FileNotFoundError(f"Face landmarker model file not found: {self.model_path}")

        base_options = python.BaseOptions(model_asset_path=str(self.model_path))
        options = vision.FaceLandmarkerOptions(
            base_options=base_options,
            running_mode=vision.RunningMode.IMAGE,
            num_faces=self.max_faces,
            min_face_detection_confidence=min_detection_confidence,
            min_face_presence_confidence=min_presence_confidence,
            min_tracking_confidence=min_tracking_confidence,
            output_face_blendshapes=False,
            output_facial_transformation_matrixes=False,
        )
        self._landmarker = vision.FaceLandmarker.create_from_options(options)

    @classmethod
    def from_config(cls, config: BeautyConfig) -> FaceLandmarkerDetector:
        return cls(
            model_path=config.face_model_path,
            max_faces=config.max_faces,
            min_detection_confidence=config.min_face_detection_confidence,
            min_presence_confidence=config.min_face_presence_confidence,
            min_tracking_confidence=config.min_tracking_confidence,
        )

    def detect(self, image_rgb: np.ndarray) -> List[FaceData]:
        """
        Detect faces and extract landmarks and feature contours.
        image_rgb: np.ndarray uint8 of shape (H, W, 3) in RGB order.
        """
        if self._landmarker is None:
            raise RuntimeError("FaceLandmarkerDetector has been closed.")

        orig_h, orig_w = image_rgb.shape[:2]
        if orig_h <= 0 or orig_w <= 0:
            return []

        # Scale down for fast CPU inference if larger than inference_max_edge
        scale = 1.0
        max_dim = max(orig_h, orig_w)
        if self.inference_max_edge > 0 and max_dim > self.inference_max_edge:
            scale = self.inference_max_edge / float(max_dim)
            scaled_w = max(1, int(round(orig_w * scale)))
            scaled_h = max(1, int(round(orig_h * scale)))
            infer_img = cv2.resize(image_rgb, (scaled_w, scaled_h), interpolation=cv2.INTER_LINEAR)
        else:
            infer_img = image_rgb

        # Ensure C-contiguous uint8
        if not infer_img.flags["C_CONTIGUOUS"]:
            infer_img = np.ascontiguousarray(infer_img)

        mp_image = mp.Image(image_format=mp.ImageFormat.SRGB, data=infer_img)
        detection_result = self._landmarker.detect(mp_image)

        if not detection_result.face_landmarks:
            return []

        faces: List[FaceData] = []
        for face_landmarks in detection_result.face_landmarks[: self.max_faces]:
            # Convert normalized landmarks back to original image pixel coordinates
            pts = np.array(
                [[int(round(lm.x * orig_w)), int(round(lm.y * orig_h))] for lm in face_landmarks],
                dtype=np.int32,
            )
            pts[:, 0] = np.clip(pts[:, 0], 0, orig_w - 1)
            pts[:, 1] = np.clip(pts[:, 1], 0, orig_h - 1)

            # Bounding box with margin
            x_min = int(pts[:, 0].min())
            y_min = int(pts[:, 1].min())
            x_max = int(pts[:, 0].max())
            y_max = int(pts[:, 1].max())

            w_box = x_max - x_min
            h_box = y_max - y_min
            pad_x = int(round(w_box * 0.15))
            pad_y = int(round(h_box * 0.15))

            bbox = (
                max(0, x_min - pad_x),
                max(0, y_min - pad_y),
                min(orig_w, x_max + pad_x),
                min(orig_h, y_max + pad_y),
            )

            # Compute convex hulls for key facial components
            left_eye_hull = cv2.convexHull(pts[LEFT_EYE_INDICES])
            right_eye_hull = cv2.convexHull(pts[RIGHT_EYE_INDICES])
            lips_hull = cv2.convexHull(pts[LIPS_INDICES])
            left_eyebrow_hull = cv2.convexHull(pts[LEFT_EYEBROW_INDICES])
            right_eyebrow_hull = cv2.convexHull(pts[RIGHT_EYEBROW_INDICES])
            face_oval_hull = cv2.convexHull(pts[FACE_OVAL_INDICES])

            faces.append(
                FaceData(
                    bbox=bbox,
                    landmarks=pts,
                    left_eye_hull=left_eye_hull,
                    right_eye_hull=right_eye_hull,
                    lips_hull=lips_hull,
                    left_eyebrow_hull=left_eyebrow_hull,
                    right_eyebrow_hull=right_eyebrow_hull,
                    face_oval_hull=face_oval_hull,
                )
            )

        return faces

    def close(self) -> None:
        if self._landmarker is not None:
            try:
                self._landmarker.close()
            except Exception:
                pass
            self._landmarker = None

    def __enter__(self) -> FaceLandmarkerDetector:
        return self

    def __exit__(self, exc_type: Any, exc_val: Any, exc_tb: Any) -> None:
        self.close()

    def __del__(self) -> None:
        self.close()
