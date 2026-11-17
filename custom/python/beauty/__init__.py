"""
Beauty Retouch Engine for Photobooth Operator Edition.
"""

from .config import BEAUTY_PRESETS, BeautyConfig, resolve_beauty_config
from importlib import import_module


def __getattr__(name):
    """JPEG validation and Beauty Off must not load optional AI/native DLLs."""
    modules = {
        "FaceData": "face_detector", "FaceLandmarkerDetector": "face_detector",
        "SkinMaskGenerator": "skin_mask", "apply_skin_smoothing": "skin_smoothing",
        "apply_skin_brightening": "skin_brightening", "apply_skin_tone_evening": "skin_tone",
        "BeautyProcessor": "processor",
        "check_lkmagic_health": "lkmagic_bridge",
        "ensure_lkmagic_background": "lkmagic_bridge",
        "shutdown_lkmagic_background": "lkmagic_bridge",
        "retouch_via_lkmagic": "lkmagic_bridge",
    }
    if name not in modules:
        raise AttributeError(name)
    value = getattr(import_module("." + modules[name], __name__), name)
    globals()[name] = value
    return value

__all__ = [
    "BeautyConfig",
    "BEAUTY_PRESETS",
    "resolve_beauty_config",
    "FaceData",
    "FaceLandmarkerDetector",
    "SkinMaskGenerator",
    "apply_skin_smoothing",
    "apply_skin_brightening",
    "apply_skin_tone_evening",
    "BeautyProcessor",
    "check_lkmagic_health",
    "ensure_lkmagic_background",
    "shutdown_lkmagic_background",
    "retouch_via_lkmagic",
]
