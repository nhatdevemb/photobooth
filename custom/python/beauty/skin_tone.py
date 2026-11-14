"""
Skin Tone Evening Engine — CIELAB Chromatic Homogenization
Reduces local blotchiness, redness, and discoloration while preserving personal skin undertone.
"""

from __future__ import annotations

import cv2
import numpy as np


def apply_skin_tone_evening(
    image_rgb: np.ndarray,
    skin_mask: np.ndarray,
    tone_intensity: float = 0.10,
) -> np.ndarray:
    """
    Even out skin chromaticity (redness, blotchiness) in CIELAB color channels (A and B)
    without altering luminance (L channel).

    :param image_rgb: uint8 array (H, W, 3) in RGB order.
    :param skin_mask: float32 array (H, W) in [0.0, 1.0].
    :param tone_intensity: float in [0.0, 1.0].
    :return: uint8 array (H, W, 3).
    """
    if tone_intensity <= 0.0 or image_rgb.size == 0 or skin_mask.size == 0:
        return image_rgb

    intensity = float(max(0.0, min(1.0, tone_intensity)))
    mask_max = float(skin_mask.max())
    if mask_max <= 1e-4:
        return image_rgb

    h, w = image_rgb.shape[:2]
    base_dim = min(h, w)
    d = max(7, min(13, int(round(base_dim * 0.007))))
    if d % 2 == 0:
        d += 1

    # Convert to CIELAB
    lab = cv2.cvtColor(image_rgb, cv2.COLOR_RGB2LAB)
    l_channel, a_channel, b_channel = cv2.split(lab)

    # Smooth chromatic channels with edge-preserving bilateral filter
    sigma_color = 12.0 + 15.0 * intensity
    sigma_space = 12.0 + 15.0 * intensity

    a_smooth = cv2.bilateralFilter(a_channel, d=d, sigmaColor=sigma_color, sigmaSpace=sigma_space).astype(np.float32)
    b_smooth = cv2.bilateralFilter(b_channel, d=d, sigmaColor=sigma_color, sigmaSpace=sigma_space).astype(np.float32)

    # Blend weight based on skin mask and intensity (max blend 75% even at full intensity)
    weight = np.clip(skin_mask * (intensity * 0.75), 0.0, 1.0)

    a_out = (a_channel.astype(np.float32) * (1.0 - weight)) + (a_smooth * weight)
    b_out = (b_channel.astype(np.float32) * (1.0 - weight)) + (b_smooth * weight)

    lab_out = cv2.merge(
        [
            l_channel,
            np.clip(a_out, 0.0, 255.0).astype(np.uint8),
            np.clip(b_out, 0.0, 255.0).astype(np.uint8),
        ]
    )

    return cv2.cvtColor(lab_out, cv2.COLOR_LAB2RGB)
