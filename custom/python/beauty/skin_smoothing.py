"""
Skin Smoothing Engine — Frequency Separation & Edge-Preserving Filtering
Preserves pores and skin micro-texture while eliminating blemishes and uneven spots.
"""

from __future__ import annotations

import cv2
import numpy as np


def apply_skin_smoothing(
    image_rgb: np.ndarray,
    skin_mask: np.ndarray,
    smooth_intensity: float = 0.20,
    texture_retention: float = 0.75,
) -> np.ndarray:
    """
    Smooth skin regions while preserving realistic pores and natural texture.

    :param image_rgb: uint8 array (H, W, 3) in RGB order.
    :param skin_mask: float32 array (H, W) in [0.0, 1.0].
    :param smooth_intensity: float in [0.0, 1.0].
    :param texture_retention: float in [0.0, 1.0] (fraction of high-frequency detail to preserve).
    :return: uint8 array (H, W, 3).
    """
    if smooth_intensity <= 0.0 or image_rgb.size == 0 or skin_mask.size == 0:
        return image_rgb

    intensity = float(max(0.0, min(1.0, smooth_intensity)))
    mask_max = float(skin_mask.max())
    if mask_max <= 1e-4:
        return image_rgb

    h, w = image_rgb.shape[:2]

    # Calculate filter parameters based on resolution and intensity
    # Base diameter 7..15 depending on image scale
    base_dim = min(h, w)
    d = max(7, min(15, int(round(base_dim * 0.008))))
    if d % 2 == 0:
        d += 1

    sigma_color = 20.0 + 35.0 * intensity
    sigma_space = 20.0 + 35.0 * intensity

    # 1. Frequency separation: extract high-frequency skin texture
    blur_k = max(5, int(round(base_dim * 0.004)))
    if blur_k % 2 == 0:
        blur_k += 1
    low_freq = cv2.GaussianBlur(image_rgb, (blur_k, blur_k), 0).astype(np.float32)
    high_freq = image_rgb.astype(np.float32) - low_freq

    # 2. Edge-preserving smoothing on base image
    smoothed_base = cv2.bilateralFilter(image_rgb, d=d, sigmaColor=sigma_color, sigmaSpace=sigma_space).astype(
        np.float32
    )

    # 3. Re-inject high-frequency skin detail (prevents plastic / airbrushed doll look)
    textured_smoothed = smoothed_base + (high_freq * float(texture_retention))
    textured_smoothed = np.clip(textured_smoothed, 0.0, 255.0)

    # 4. Alpha blend with original via skin mask weighted by intensity
    weight = np.clip(skin_mask[:, :, np.newaxis] * intensity, 0.0, 1.0)
    blended = (image_rgb.astype(np.float32) * (1.0 - weight)) + (textured_smoothed * weight)

    return np.rint(np.clip(blended, 0.0, 255.0)).astype(np.uint8)
