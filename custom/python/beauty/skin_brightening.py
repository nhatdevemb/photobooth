"""
Skin Brightening Engine — CIELAB Luminance Enhancement with Highlight Rolloff
Safely brightens skin without blowing out existing highlights or altering non-skin tones.
"""

from __future__ import annotations

import cv2
import numpy as np


def apply_skin_brightening(
    image_rgb: np.ndarray,
    skin_mask: np.ndarray,
    brighten_intensity: float = 0.05,
    max_luminance_lift: float = 40.0,
) -> np.ndarray:
    """
    Selectively brighten skin luminance in CIELAB color space with highlight protection.

    :param image_rgb: uint8 array (H, W, 3) in RGB order.
    :param skin_mask: float32 array (H, W) in [0.0, 1.0].
    :param brighten_intensity: float in [0.0, 1.0].
    :param max_luminance_lift: maximum delta L at full intensity (default 40.0 in 0..255 range).
    :return: uint8 array (H, W, 3).
    """
    if brighten_intensity <= 0.0 or image_rgb.size == 0 or skin_mask.size == 0:
        return image_rgb

    intensity = float(max(0.0, min(1.0, brighten_intensity)))
    mask_max = float(skin_mask.max())
    if mask_max <= 1e-4:
        return image_rgb

    # Convert RGB to CIELAB
    lab = cv2.cvtColor(image_rgb, cv2.COLOR_RGB2LAB).astype(np.float32)
    l_channel, a_channel, b_channel = cv2.split(lab)

    # Normalized luminance factor [0.0, 1.0]
    l_norm = l_channel / 255.0

    # Highlight rolloff: smoothly dampens brightening as pixels approach full white
    # (1 - l_norm^2) preserves specular highlights and prevents blowout
    rolloff = np.clip(1.0 - (l_norm ** 2), 0.0, 1.0)

    # Shadow protection: dampens boost on deep shadows
    shadow_guard = np.clip(l_norm * 4.0, 0.0, 1.0)

    # Compute luminance delta
    delta_l = (max_luminance_lift * intensity) * rolloff * shadow_guard * skin_mask

    # Apply delta
    l_channel = np.clip(l_channel + delta_l, 0.0, 255.0)

    # Merge and convert back to RGB
    lab_out = cv2.merge([l_channel, a_channel, b_channel]).astype(np.uint8)
    return cv2.cvtColor(lab_out, cv2.COLOR_LAB2RGB)
