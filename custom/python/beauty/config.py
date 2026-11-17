"""
Beauty Retouch Engine — Configuration & Presets
"""

from __future__ import annotations

from dataclasses import dataclass, field
import os
from typing import Any, Dict, Optional


@dataclass
class BeautyConfig:
    preset: str = "natural"
    smooth: float = 0.20        # 0.0 to 1.0 (Skin smoothing intensity)
    brighten: float = 0.05      # 0.0 to 1.0 (Skin brightening intensity)
    tone: float = 0.10          # 0.0 to 1.0 (Skin tone evening intensity)
    max_faces: int = 4          # Maximum faces to detect and retouch (1 to 4)
    min_face_detection_confidence: float = 0.5
    min_face_presence_confidence: float = 0.5
    min_tracking_confidence: float = 0.5
    face_model_path: Optional[str] = None
    segmenter_model_path: Optional[str] = None
    enabled: bool = True
    engine: str = "auto"        # "auto" | "lkmagic" | "mediapipe"
    lkmagic_url: str = "http://127.0.0.1:5520"
    lkmagic_timeout_sec: float = 35.0
    auto_start_lkmagic: bool = True
    face_slim: bool = False     # Strictly disabled (no face/body reshaping)

    def __post_init__(self) -> None:
        # Clamp values
        self.smooth = float(max(0.0, min(1.0, self.smooth)))
        self.brighten = float(max(0.0, min(1.0, self.brighten)))
        self.tone = float(max(0.0, min(1.0, self.tone)))
        self.max_faces = int(max(1, min(4, self.max_faces)))
        self.face_slim = False
        raw_engine = str(self.engine or "auto").strip().lower()
        self.engine = raw_engine if raw_engine in ("auto", "lkmagic", "mediapipe") else "auto"
        if self.preset.lower() == "off" or (self.smooth == 0.0 and self.brighten == 0.0 and self.tone == 0.0):
            self.enabled = False

    def is_active(self) -> bool:
        return self.enabled and (self.smooth > 0.0 or self.brighten > 0.0 or self.tone > 0.0)

    def to_dict(self) -> Dict[str, Any]:
        return {
            "preset": self.preset,
            "smooth": round(self.smooth, 4),
            "brighten": round(self.brighten, 4),
            "tone": round(self.tone, 4),
            "max_faces": self.max_faces,
            "enabled": self.enabled,
            "engine": self.engine,
        }


BEAUTY_PRESETS: Dict[str, Dict[str, float]] = {
    "off": {
        "smooth": 0.0,
        "brighten": 0.0,
        "tone": 0.0,
    },
    "natural": {
        "smooth": 0.20,
        "brighten": 0.05,
        "tone": 0.10,
    },
    "soft": {
        "smooth": 0.40,
        "brighten": 0.10,
        "tone": 0.15,
    },
    "korean-glow": {
        "smooth": 0.55,
        "brighten": 0.15,
        "tone": 0.20,
    },
}

DEFAULT_PRESET = "natural"


def normalize_intensity(val: Any, default: float = 0.0) -> float:
    """Normalize input value whether supplied as 0..100 integer or 0.0..1.0 float."""
    if val is None:
        return default
    try:
        f = float(val)
        if f > 1.0:
            f = f / 100.0
        return max(0.0, min(1.0, f))
    except (TypeError, ValueError):
        return default


def resolve_beauty_config(
    preset_name: Optional[str] = None,
    overrides: Optional[Dict[str, Any]] = None,
    face_model_path: Optional[str] = None,
    segmenter_model_path: Optional[str] = None,
) -> BeautyConfig:
    """
    Resolve a BeautyConfig from preset name and optional custom parameter overrides.
    """
    raw_name = (preset_name or DEFAULT_PRESET).strip().lower()
    # Normalize aliases
    alias_map = {
        "none": "off",
        "disable": "off",
        "disabled": "off",
        "korean": "korean-glow",
        "korean_glow": "korean-glow",
        "koreanglow": "korean-glow",
    }
    canonical_name = alias_map.get(raw_name, raw_name)

    base = BEAUTY_PRESETS.get(canonical_name, BEAUTY_PRESETS[DEFAULT_PRESET])

    smooth = base["smooth"]
    brighten = base["brighten"]
    tone = base["tone"]
    max_faces = 4
    enabled = canonical_name != "off"
    engine = os.getenv("BEAUTY_ENGINE", "auto").strip().lower() or "auto"
    lkmagic_url = os.getenv("LKMAGIC_BRIDGE_URL", "http://127.0.0.1:5520").strip() or "http://127.0.0.1:5520"
    auto_start_lkmagic = (
        "PYTEST_CURRENT_TEST" not in os.environ
        and os.getenv("LKMAGIC_AUTO_START", "1").strip().lower() not in ("0", "false", "no", "off")
    )

    if overrides:
        if "smooth" in overrides:
            smooth = normalize_intensity(overrides["smooth"], smooth)
        if "brighten" in overrides:
            brighten = normalize_intensity(overrides["brighten"], brighten)
        if "tone" in overrides:
            tone = normalize_intensity(overrides["tone"], tone)
        if "max_faces" in overrides:
            try:
                max_faces = int(max(1, min(4, int(overrides["max_faces"]))))
            except (TypeError, ValueError):
                pass
        if "enabled" in overrides:
            enabled = bool(overrides["enabled"])
        if "engine" in overrides and overrides["engine"]:
            engine = str(overrides["engine"]).strip().lower()
        if "lkmagic_url" in overrides and overrides["lkmagic_url"]:
            lkmagic_url = str(overrides["lkmagic_url"]).strip()
        if "auto_start_lkmagic" in overrides:
            auto_start_lkmagic = bool(overrides["auto_start_lkmagic"])

    return BeautyConfig(
        preset=canonical_name,
        smooth=smooth,
        brighten=brighten,
        tone=tone,
        max_faces=max_faces,
        face_model_path=face_model_path,
        segmenter_model_path=segmenter_model_path,
        enabled=enabled,
        engine=engine,
        lkmagic_url=lkmagic_url,
        auto_start_lkmagic=auto_start_lkmagic,
        face_slim=False,
    )
