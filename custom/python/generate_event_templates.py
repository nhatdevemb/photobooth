#!/usr/bin/env python3
"""
Deterministic generator for 10 bundled Wedding & Birthday editorial event frames.

Outputs packages under custom/templates/bundled/<template-id>/:
  - background.png (1181x1748 RGB PNG with subtle tactile paper texture)
  - overlay.png    (1181x1748 RGBA PNG drawn at 2x supersampling, strictly
                    transparent inside every slot's 50px safe inset)
  - preview.jpg    (rendered via the runtime render_pipeline using source-portrait.jpg)
  - template.json  (catalog metadata with verified SHA-256 hashes)
"""

from __future__ import annotations

import hashlib
import json
import math
import os
from pathlib import Path
from typing import Dict, List, Optional, Sequence, Tuple

import numpy as np
from PIL import Image, ImageDraw, ImageFilter, ImageFont

from render_pipeline import run_render_from_manifest

CUSTOM_DIR = Path(__file__).resolve().parent.parent
LAYOUTS_DIR = CUSTOM_DIR / "layouts"
TEMPLATES_DIR = CUSTOM_DIR / "templates" / "bundled"
SAMPLES_DIR = CUSTOM_DIR / "public" / "assets" / "samples"
BUNDLED_FONTS_DIR = CUSTOM_DIR / "public" / "assets" / "fonts"

CANVAS_W = 1181
CANVAS_H = 1748
SS = 2  # 2x supersampling for silky anti-aliased Bezier artwork and typography
SAFE_INSET = 50
MIN_CLEAR_INSET = 49  # Assert zero alpha inside (slot + 49px) so 50px check has clean margin

RGBA = Tuple[int, int, int, int]
RGB = Tuple[int, int, int]
Point = Tuple[float, float]


def resolve_font(candidates: Sequence[str], size: float) -> ImageFont.FreeTypeFont:
    """Resolve the first available TrueType/OpenType font at the scaled supersampled size."""
    search_dirs = [
        Path(os.environ.get("WINDIR", r"C:\Windows")) / "Fonts",
        BUNDLED_FONTS_DIR,
    ]
    px = max(8, int(round(size * SS)))
    for name in candidates:
        for base in search_dirs:
            path = base / name
            if path.is_file():
                try:
                    return ImageFont.truetype(str(path), px)
                except OSError:
                    continue
    return ImageFont.load_default()


FONT_SERIF_LUX = ("GARA.TTF", "pala.ttf", "georgia.ttf", "times.ttf", "BeVietnamPro-SemiBold.ttf")
FONT_SERIF_BOLD = ("georgiab.ttf", "timesbd.ttf", "pala.ttf", "BeVietnamPro-Bold.ttf")
FONT_SERIF_ITALIC = ("GARAIT.TTF", "palai.ttf", "georgiai.ttf", "timesi.ttf", "BeVietnamPro-Regular.ttf")
FONT_SCRIPT = ("VIVALDII.TTF", "FRSCRIPT.TTF", "segoesc.ttf", "GARAIT.TTF", "BeVietnamPro-Regular.ttf")
FONT_HAND = ("segoesc.ttf", "PRISTINA.TTF", "FRSCRIPT.TTF", "BeVietnamPro-Medium.ttf")
FONT_SANS_BOLD = ("BeVietnamPro-Bold.ttf", "BeVietnamPro-SemiBold.ttf", "segoeuib.ttf")
FONT_SANS_REG = ("BeVietnamPro-SemiBold.ttf", "BeVietnamPro-Regular.ttf", "segoeui.ttf")


def S(v: float) -> float:
    return v * SS


def pts_S(pts: Sequence[Point]) -> List[Tuple[float, float]]:
    return [(x * SS, y * SS) for x, y in pts]


def cubic_bezier(p0: Point, p1: Point, p2: Point, p3: Point, steps: int = 36) -> List[Point]:
    out: List[Point] = []
    for i in range(steps + 1):
        t = i / steps
        mt = 1.0 - t
        x = mt**3 * p0[0] + 3 * mt**2 * t * p1[0] + 3 * mt * t**2 * p2[0] + t**3 * p3[0]
        y = mt**3 * p0[1] + 3 * mt**2 * t * p1[1] + 3 * mt * t**2 * p2[1] + t**3 * p3[1]
        out.append((x, y))
    return out


def rotate_pts(pts: Sequence[Point], cx: float, cy: float, angle_deg: float) -> List[Point]:
    rad = math.radians(angle_deg)
    c, s = math.cos(rad), math.sin(rad)
    return [(cx + (x - cx) * c - (y - cy) * s, cy + (x - cx) * s + (y - cy) * c) for x, y in pts]


def transform_pts(
    pts: Sequence[Point], cx: float, cy: float, sx: float, sy: float, angle_deg: float = 0.0
) -> List[Point]:
    scaled = [(cx + x * sx, cy + y * sy) for x, y in pts]
    if abs(angle_deg) > 1e-6:
        return rotate_pts(scaled, cx, cy, angle_deg)
    return scaled


def draw_variable_stroke(
    draw: ImageDraw.ImageDraw,
    pts: Sequence[Point],
    widths: Sequence[float],
    fill: RGBA,
) -> None:
    """Draw a smooth calligraphic tapered curve by offsetting curve normals."""
    if len(pts) < 2:
        return
    left_side: List[Point] = []
    right_side: List[Point] = []
    n = len(pts)
    for i, (x, y) in enumerate(pts):
        p_prev = pts[max(0, i - 1)]
        p_next = pts[min(n - 1, i + 1)]
        dx = p_next[0] - p_prev[0]
        dy = p_next[1] - p_prev[1]
        length = math.hypot(dx, dy) or 1.0
        nx, ny = -dy / length, dx / length
        w = widths[i] * 0.5
        left_side.append((x + nx * w, y + ny * w))
        right_side.append((x - nx * w, y - ny * w))
    poly = left_side + list(reversed(right_side))
    draw.polygon(pts_S(poly), fill=fill)
    # Round endpoints subtly
    for idx in (0, -1):
        r = widths[idx] * 0.45
        if r >= 0.4:
            px, py = pts[idx]
            draw.ellipse((S(px - r), S(py - r), S(px + r), S(py + r)), fill=fill)


def draw_tracked_text(
    draw: ImageDraw.ImageDraw,
    cx: float,
    cy: float,
    text: str,
    font: ImageFont.FreeTypeFont,
    fill: RGBA,
    tracking: float = 3.0,
    anchor: str = "mm",
) -> float:
    """Render horizontally tracked text centered at cx with vertical anchor ('mm' or 'mt')."""
    if not text:
        return 0.0
    widths = [max(0.0, draw.textlength(ch, font=font)) for ch in text]
    step_gap = tracking * SS
    total_w = sum(widths) + step_gap * max(0, len(text) - 1)
    start_x = cx * SS - total_w * 0.5
    ascent, descent = font.getmetrics()
    base_y = cy * SS if anchor == "mt" else cy * SS - (ascent - descent) * 0.5
    x = start_x
    for ch, w in zip(text, widths):
        draw.text((x, base_y), ch, font=font, fill=fill, anchor="lt")
        x += w + step_gap
    return total_w / SS


def make_textured_background(
    base_rgb: RGB,
    seed: int = 42,
    grain: float = 2.0,
    gradient_to: Optional[RGB] = None,
    gingham: Optional[Tuple[RGB, int, float]] = None,
) -> Image.Image:
    """Create a 1181x1748 RGB background with optional gradient, soft gingham, and paper grain."""
    if gradient_to is not None:
        t = np.linspace(0.0, 1.0, CANVAS_H, dtype=np.float32)[:, None, None]
        c0 = np.array(base_rgb, dtype=np.float32)[None, None, :]
        c1 = np.array(gradient_to, dtype=np.float32)[None, None, :]
        arr = c0 * (1.0 - t) + c1 * t
        arr = np.broadcast_to(arr, (CANVAS_H, CANVAS_W, 3)).copy()
    else:
        arr = np.full((CANVAS_H, CANVAS_W, 3), base_rgb, dtype=np.float32)

    if gingham is not None:
        alt_rgb, cell, strength = gingham
        yy, xx = np.ogrid[:CANVAS_H, :CANVAS_W]
        stripe_x = ((xx // cell) % 2 == 0).astype(np.float32)
        stripe_y = ((yy // cell) % 2 == 0).astype(np.float32)
        mask = (stripe_x + stripe_y) * 0.5 * strength
        delta = (np.array(alt_rgb, dtype=np.float32) - np.array(base_rgb, dtype=np.float32))[None, None, :]
        arr += mask[:, :, None] * delta

    if grain > 0:
        rng = np.random.default_rng(seed)
        noise = rng.normal(0.0, grain, size=(CANVAS_H, CANVAS_W, 1)).astype(np.float32)
        # Soft horizontal fiber texture
        fibers = rng.normal(0.0, grain * 0.5, size=(CANVAS_H, 1, 1)).astype(np.float32)
        arr += noise + fibers

    return Image.fromarray(np.clip(np.round(arr), 0, 255).astype(np.uint8), mode="RGB")


def draw_pastel_rainbow_bg(seed: int = 108) -> Image.Image:
    """Create a dreamy multi-stop vertical pastel gradient background with subtle bokeh glows."""
    stops = [
        (0.00, np.array((255, 239, 246), dtype=np.float32)),
        (0.35, np.array((243, 236, 255), dtype=np.float32)),
        (0.70, np.array((233, 246, 255), dtype=np.float32)),
        (1.00, np.array((255, 246, 229), dtype=np.float32)),
    ]
    t = np.linspace(0.0, 1.0, CANVAS_H, dtype=np.float32)
    grad = np.zeros((CANVAS_H, 1, 3), dtype=np.float32)
    for i in range(len(stops) - 1):
        t0, c0 = stops[i]
        t1, c1 = stops[i + 1]
        sel = (t >= t0) & (t <= t1)
        alpha = ((t[sel] - t0) / max(1e-6, t1 - t0))[:, None, None]
        grad[sel] = c0 + (c1 - c0) * alpha
    arr = np.broadcast_to(grad, (CANVAS_H, CANVAS_W, 3)).copy()
    rng = np.random.default_rng(seed)
    arr += rng.normal(0.0, 1.6, size=(CANVAS_H, CANVAS_W, 1)).astype(np.float32)
    return Image.fromarray(np.clip(np.round(arr), 0, 255).astype(np.uint8), mode="RGB")


def build_base_frame_matte(
    layout: dict,
    matte_color: RGBA,
    top_band: int = 35,
    bottom_band: int = 35,
    corner_radius: int = 14,
    slot_border_color: Optional[RGBA] = None,
    slot_border_width: float = 2.0,
    inner_gap_fill: bool = True,
) -> Image.Image:
    """
    Create a 2x supersampled RGBA overlay with transparent rounded slot cutouts.

    top_band / bottom_band control how far the top/bottom paper matte extends inward
    into the overall canvas edges while staying cleanly outside each slot's 50px safe inset.
    """
    hi = Image.new("RGBA", (CANVAS_W * SS, CANVAS_H * SS), matte_color)
    # Carve rounded slot openings in an alpha mask
    alpha = Image.new("L", (CANVAS_W * SS, CANVAS_H * SS), matte_color[3])
    adraw = ImageDraw.Draw(alpha)
    for slot in layout["slots"]:
        sx, sy, sw, sh = slot["x"], slot["y"], slot["width"], slot["height"]
        x0 = sx
        y0 = max(sy, top_band)
        x1 = sx + sw
        y1 = min(sy + sh, CANVAS_H - bottom_band)
        adraw.rounded_rectangle(
            (S(x0), S(y0), S(x1), S(y1)),
            radius=S(corner_radius),
            fill=0,
        )
    if not inner_gap_fill:
        # Leave gaps filled by background.png unless specified
        pass
    hi.putalpha(alpha)

    if slot_border_color is not None and slot_border_width > 0:
        draw = ImageDraw.Draw(hi)
        for slot in layout["slots"]:
            sx, sy, sw, sh = slot["x"], slot["y"], slot["width"], slot["height"]
            x0 = sx
            y0 = max(sy, top_band)
            x1 = sx + sw
            y1 = min(sy + sh, CANVAS_H - bottom_band)
            draw.rounded_rectangle(
                (S(x0), S(y0), S(x1), S(y1)),
                radius=S(corner_radius),
                outline=slot_border_color,
                width=max(2, int(round(slot_border_width * SS))),
            )
    return hi


def draw_coquette_bow(
    draw: ImageDraw.ImageDraw,
    cx: float,
    cy: float,
    scale: float,
    angle_deg: float,
    stroke: RGBA,
    fill: Optional[RGBA] = None,
    highlight: Optional[RGBA] = None,
    style: str = "filled",
    aspect_x: float = 1.0,
) -> None:
    """
    Draw a boutique coquette ribbon bow (either lush gouache-filled or crisp outlined ink).
    Canonical unscaled width is ~160px, height ~140px around (cx, cy).
    """

    def xf(pts: Sequence[Point]) -> List[Point]:
        return transform_pts(pts, cx, cy, scale * aspect_x, scale, angle_deg)

    # Ribbon tails flowing organically downward with soft S-curves
    left_tail = (
        cubic_bezier((-6, 6), (-22, 34), (-38, 62), (-28, 96), 28)
        + cubic_bezier((-28, 96), (-18, 72), (-10, 42), (0, 12), 28)
    )
    right_tail = (
        cubic_bezier((6, 6), (24, 38), (36, 66), (48, 92), 28)
        + cubic_bezier((48, 92), (24, 70), (14, 40), (2, 12), 28)
    )

    if style == "outline":
        # Double-stroke fine ink outline ribbons over opaque ivory/white ribbon body
        body_fill = fill or (255, 252, 247, 255)
        lw = max(1.6, 2.6 * scale)
        # Tails as graceful wavy ribbons
        for sign, dx_end, dy_end in ((-1, -46, 72), (1, 52, 68)):
            edge_a = cubic_bezier(
                (sign * 5, 4),
                (sign * 16, 28),
                (sign * 34, 48),
                (dx_end - sign * 8, dy_end),
                24,
            )
            edge_b = cubic_bezier(
                (dx_end + sign * 7, dy_end - 8),
                (sign * 42, 42),
                (sign * 22, 24),
                (sign * 9, 2),
                24,
            )
            poly = edge_a + [(dx_end, dy_end - 12)] + edge_b
            draw.polygon(pts_S(xf(poly)), fill=body_fill, outline=stroke, width=max(2, int(round(S(lw)))))

        # Wide left & right pleated bow loops
        for sign in (-1, 1):
            outer_loop = (
                cubic_bezier((0, 0), (sign * 26, -34), (sign * 78, -42), (sign * 72, -4), 28)
                + cubic_bezier((sign * 72, -4), (sign * 68, 22), (sign * 30, 18), (0, 5), 24)
            )
            draw.polygon(pts_S(xf(outer_loop)), fill=body_fill, outline=stroke, width=max(2, int(round(S(lw)))))
            # Inner pleat fold line for delicate silk depth
            fold = cubic_bezier((sign * 8, -2), (sign * 30, -15), (sign * 52, -16), (sign * 54, -4), 18)
            draw.line(pts_S(xf(fold)), fill=stroke, width=max(2, int(round(S(lw * 0.78)))))
            # Lower petite secondary loop
            sub_loop = (
                cubic_bezier((0, 4), (sign * 24, 14), (sign * 56, 30), (sign * 52, 8), 20)
                + cubic_bezier((sign * 52, 8), (sign * 46, 0), (sign * 20, -2), (0, 4), 16)
            )
            draw.polygon(pts_S(xf(sub_loop)), fill=body_fill, outline=stroke, width=max(2, int(round(S(lw * 0.85)))))

        knot = cubic_bezier((-9, -7), (-10, 7), (10, 7), (9, -7), 16) + cubic_bezier(
            (9, -7), (5, -11), (-5, -11), (-9, -7), 12
        )
        draw.polygon(pts_S(xf(knot)), fill=body_fill, outline=stroke, width=max(2, int(round(S(lw)))))
        return

    # "center_ribbon" style: butterfly silk bow with sweeping horizontal undulating tails along center grid gap
    if style == "center_ribbon":
        main_fill = fill or stroke
        rim_col = stroke
        # Wide undulating horizontal tails flowing along center gap y ~ cy
        for sgn in (-1, 1):
            tail = cubic_bezier((sgn * 8, 2), (sgn * 48, 12), (sgn * 88, -8), (sgn * 136, 4), 28)
            tail_w = [scale * (9.5 * (1.0 - 0.35 * (i / 28))) for i in range(29)]
            draw_variable_stroke(draw, xf(tail), tail_w, main_fill)
            # Subtle center silk contour along horizontal tail
            draw.line(pts_S(xf(tail)), fill=rim_col, width=max(2, int(round(S(1.1 * scale)))))
        # Two shorter vertical flutter ribbons down the vertical gap
        for sgn, dx_end in ((-1, -18), (1, 18)):
            vtail = cubic_bezier((sgn * 4, 6), (sgn * 10, 26), (dx_end * 0.8, 42), (dx_end, 58), 22)
            draw_variable_stroke(draw, xf(vtail), [scale * (6.8 - 2.6 * (i / 22)) for i in range(23)], main_fill)
            draw.line(pts_S(xf(vtail)), fill=rim_col, width=max(1, int(round(S(1.0 * scale)))))
        # Round butterfly loops
        for sign in (-1, 1):
            loop = (
                cubic_bezier((0, -2), (sign * 22, -32), (sign * 72, -36), (sign * 66, -3), 26)
                + cubic_bezier((sign * 66, -3), (sign * 60, 20), (sign * 26, 15), (0, 5), 22)
            )
            draw.polygon(pts_S(xf(loop)), fill=main_fill, outline=rim_col, width=max(2, int(round(S(1.8 * scale)))))
            fold = cubic_bezier((sign * 10, -2), (sign * 28, -14), (sign * 48, -14), (sign * 50, -3), 16)
            draw.line(pts_S(xf(fold)), fill=rim_col, width=max(1, int(round(S(1.2 * scale)))))
        knot = cubic_bezier((-9, -7), (-11, 7), (11, 7), (9, -7), 14) + cubic_bezier(
            (9, -7), (5, -11), (-5, -11), (-9, -7), 10
        )
        draw.polygon(pts_S(xf(knot)), fill=main_fill, outline=rim_col, width=max(2, int(round(S(1.8 * scale)))))
        return

    # "filled" woodcut / gouache style (matching Reference #1 bottom bow & Reference #2 wide silk bow)
    main_fill = fill or stroke
    bg_cut = highlight or (250, 245, 236, 255)

    # Flowing tails with variable width
    t1_pts = cubic_bezier((-6, 6), (-22, 36), (-36, 70), (-26, 108), 32)
    t1_w = [scale * (11.0 + 7.0 * math.sin(math.pi * i / 32) - 4.5 * (i / 32)) for i in range(33)]
    draw_variable_stroke(draw, xf(t1_pts), t1_w, main_fill)

    t2_pts = cubic_bezier((8, 6), (26, 38), (44, 72), (34, 104), 32)
    t2_w = [scale * (11.0 + 7.5 * math.sin(math.pi * i / 32) - 4.0 * (i / 32)) for i in range(33)]
    draw_variable_stroke(draw, xf(t2_pts), t2_w, main_fill)

    # Left and right double loops (upper wide lobe + inner negative cutout + lower gentle lobe)
    for sign in (-1, 1):
        upper = (
            cubic_bezier((0, -2), (sign * 26, -42), (sign * 86, -46), (sign * 80, -4), 32)
            + cubic_bezier((sign * 80, -4), (sign * 74, 24), (sign * 34, 18), (0, 6), 28)
        )
        draw.polygon(pts_S(xf(upper)), fill=main_fill)
        inner_cut = (
            cubic_bezier((sign * 14, -1), (sign * 32, -20), (sign * 60, -20), (sign * 56, -4), 22)
            + cubic_bezier((sign * 56, -4), (sign * 52, 9), (sign * 28, 7), (sign * 14, 1), 18)
        )
        draw.polygon(pts_S(xf(inner_cut)), fill=bg_cut)

        # Silk crease highlight inside loop
        inner_ribbon = cubic_bezier((sign * 10, 2), (sign * 28, 12), (sign * 58, 22), (sign * 64, 8), 22)
        w_ir = [scale * (7.0 * (0.6 + 0.4 * math.sin(math.pi * i / 22))) for i in range(23)]
        draw_variable_stroke(draw, xf(inner_ribbon), w_ir, main_fill)

        if highlight is not None and style == "silk":
            draw.line(
                pts_S(xf(cubic_bezier((sign * 16, -12), (sign * 40, -28), (sign * 68, -24), (sign * 70, -8), 18))),
                fill=highlight,
                width=max(2, int(round(S(1.8 * scale)))),
            )

    # Crisp outlines when silk style has darker border color distinct from fill
    if stroke != main_fill:
        for sign in (-1, 1):
            rim = cubic_bezier((0, -2), (sign * 26, -42), (sign * 86, -46), (sign * 80, -4), 28)
            rim2 = cubic_bezier((sign * 80, -4), (sign * 74, 24), (sign * 34, 18), (0, 6), 24)
            draw.line(pts_S(xf(rim + rim2)), fill=stroke, width=max(2, int(round(S(2.0 * scale)))))

    # Central gathered knot with cream fold accent
    knot_pts = (
        cubic_bezier((-11, -9), (-13, 8), (13, 8), (11, -9), 16)
        + cubic_bezier((11, -9), (6, -14), (-6, -14), (-11, -9), 12)
    )
    draw.polygon(pts_S(xf(knot_pts)), fill=main_fill, outline=stroke if stroke != main_fill else None, width=2)
    crease = cubic_bezier((-5, -3), (-2, 1), (2, 1), (5, -3), 12)
    draw.line(pts_S(xf(crease)), fill=bg_cut, width=max(2, int(round(S(2.0 * scale)))))


def draw_sparkle(draw: ImageDraw.ImageDraw, cx: float, cy: float, r: float, fill: RGBA, inner_ratio: float = 0.28) -> None:
    """Draw a sharp 4-point editorial sparkle star."""
    ir = r * inner_ratio
    pts = [
        (cx, cy - r),
        (cx + ir, cy - ir),
        (cx + r, cy),
        (cx + ir, cy + ir),
        (cx, cy + r),
        (cx - ir, cy + ir),
        (cx - r, cy),
        (cx - ir, cy - ir),
    ]
    draw.polygon(pts_S(pts), fill=fill)


def draw_heart(draw: ImageDraw.ImageDraw, cx: float, cy: float, size: float, fill: RGBA, outline: Optional[RGBA] = None) -> None:
    """Draw a smooth vector heart centered at (cx, cy)."""
    pts: List[Point] = []
    steps = 40
    for i in range(steps + 1):
        t = (i / steps) * 2.0 * math.pi
        x = 16 * (math.sin(t) ** 3)
        y = -(13 * math.cos(t) - 5 * math.cos(2 * t) - 2 * math.cos(3 * t) - math.cos(4 * t))
        pts.append((cx + x * (size / 18.0), cy + y * (size / 18.0)))
    draw.polygon(pts_S(pts), fill=fill, outline=outline, width=max(2, int(round(S(1.5)))) if outline else 1)


def draw_botanical_sprig(
    draw: ImageDraw.ImageDraw,
    cx: float,
    cy: float,
    length: float,
    angle_deg: float,
    leaf_fill: RGBA,
    stem_fill: RGBA,
    pearl_fill: Optional[RGBA] = None,
    pairs: int = 5,
) -> None:
    """Draw an organic olive / bridal botanical sprig with paired leaves and pearl buds."""
    rad = math.radians(angle_deg)
    ux, uy = math.cos(rad), math.sin(rad)
    px, py = -uy, ux
    stem = [
        (cx - ux * length * 0.5 + px * 2 * math.sin(i / 20 * math.pi), cy - uy * length * 0.5 + py * 2 * math.sin(i / 20 * math.pi))
        for i in range(21)
    ]
    stem = [
        (cx + ux * (-length * 0.5 + length * (i / 20)) + px * 3.5 * math.sin(math.pi * i / 20),
         cy + uy * (-length * 0.5 + length * (i / 20)) + py * 3.5 * math.sin(math.pi * i / 20))
        for i in range(21)
    ]
    draw.line(pts_S(stem), fill=stem_fill, width=max(2, int(round(S(2.0)))))
    for k in range(1, pairs + 1):
        t = k / (pairs + 0.8)
        bx, by = stem[min(20, int(round(t * 20)))]
        leaf_len = length * (0.20 - 0.04 * abs(t - 0.45))
        leaf_w = leaf_len * 0.34
        for side in (-1, 1):
            la = angle_deg + side * 42
            lrad = math.radians(la)
            lx, ly = math.cos(lrad), math.sin(lrad)
            nlx, nly = -ly, lx
            p0 = (bx, by)
            tip = (bx + lx * leaf_len, by + ly * leaf_len)
            c1 = (bx + lx * leaf_len * 0.45 + nlx * leaf_w, by + ly * leaf_len * 0.45 + nly * leaf_w)
            c2 = (bx + lx * leaf_len * 0.45 - nlx * leaf_w, by + ly * leaf_len * 0.45 - nly * leaf_w)
            poly = cubic_bezier(p0, c1, c1, tip, 12) + cubic_bezier(tip, c2, c2, p0, 12)
            draw.polygon(pts_S(poly), fill=leaf_fill)
        if pearl_fill is not None and k % 2 == 1:
            ox = bx + px * 5 * (1 if k % 4 == 1 else -1)
            oy = by + py * 5 * (1 if k % 4 == 1 else -1)
            r = 3.2
            draw.ellipse((S(ox - r), S(oy - r), S(ox + r), S(oy + r)), fill=pearl_fill)


def draw_wedding_rings_crest(
    draw: ImageDraw.ImageDraw, cx: float, cy: float, radius: float, color: RGBA, accent: RGBA
) -> None:
    """Draw a fine twin-ring & botanical emblem for wedding sidebars and center crests."""
    dx = radius * 0.46
    for sgn in (-1, 1):
        rx = cx + sgn * dx
        draw.ellipse(
            (S(rx - radius), S(cy - radius), S(rx + radius), S(cy + radius)),
            outline=color,
            width=max(2, int(round(S(2.6)))),
        )
    # Tiny diamond gem above intersection
    dy = cy - radius * 0.92
    draw_sparkle(draw, cx, dy, radius * 0.38, accent, inner_ratio=0.32)


def draw_birthday_cake(draw: ImageDraw.ImageDraw, cx: float, cy: float, scale: float, palette: dict) -> None:
    """Draw a charming two-tier coquette birthday cake with candles, frosting drip, and bows."""
    cream = palette["cake"]
    icing = palette["icing"]
    outline = palette["outline"]
    flame = palette["flame"]
    ribbon = palette["ribbon"]
    lw = max(2, int(round(S(2.2 * scale))))

    # Bottom tier
    bw, bh = 112 * scale, 48 * scale
    by0, by1 = cy + 6 * scale, cy + 6 * scale + bh
    draw.rounded_rectangle(
        (S(cx - bw * 0.5), S(by0), S(cx + bw * 0.5), S(by1)),
        radius=S(8 * scale),
        fill=cream,
        outline=outline,
        width=lw,
    )
    # Cake plate
    draw.rounded_rectangle(
        (S(cx - bw * 0.62), S(by1), S(cx + bw * 0.62), S(by1 + 9 * scale)),
        radius=S(4 * scale),
        fill=icing,
        outline=outline,
        width=lw,
    )
    # Top tier
    tw, th = 78 * scale, 40 * scale
    ty0, ty1 = by0 - th + 2 * scale, by0 + 2 * scale
    draw.rounded_rectangle(
        (S(cx - tw * 0.5), S(ty0), S(cx + tw * 0.5), S(ty1)),
        radius=S(8 * scale),
        fill=cream,
        outline=outline,
        width=lw,
    )
    # Piped scalloped frosting across top tier & bottom tier rims
    for y_rim, width_tier, n_sc in ((ty0 + 8 * scale, tw, 6), (by0 + 8 * scale, bw, 8)):
        step = width_tier / n_sc
        for k in range(n_sc):
            sx = cx - width_tier * 0.5 + step * k
            draw.chord(
                (S(sx), S(y_rim - 9 * scale), S(sx + step), S(y_rim + 7 * scale)),
                start=0,
                end=180,
                fill=icing,
                outline=outline,
                width=max(1, int(round(lw * 0.8))),
            )

    # 3 slender candles on top
    for dx in (-20 * scale, 0.0, 20 * scale):
        c_top = ty0 - 28 * scale
        draw.rounded_rectangle(
            (S(cx + dx - 3.5 * scale), S(c_top), S(cx + dx + 3.5 * scale), S(ty0)),
            radius=S(2 * scale),
            fill=icing,
            outline=outline,
            width=max(1, int(round(lw * 0.7))),
        )
        # Teardrop flame
        f_pts = cubic_bezier(
            (cx + dx, c_top - 15 * scale),
            (cx + dx + 6 * scale, c_top - 7 * scale),
            (cx + dx + 5 * scale, c_top - 2 * scale),
            (cx + dx, c_top - 2 * scale),
            12,
        ) + cubic_bezier(
            (cx + dx, c_top - 2 * scale),
            (cx + dx - 5 * scale, c_top - 2 * scale),
            (cx + dx - 6 * scale, c_top - 7 * scale),
            (cx + dx, c_top - 15 * scale),
            12,
        )
        draw.polygon(pts_S(f_pts), fill=flame)

    # Mini bow perched neatly on front bottom tier
    draw_coquette_bow(draw, cx, by0 + bh * 0.55, scale * 0.28, 0.0, stroke=outline, fill=ribbon, style="outline")


def draw_cherry_duo(draw: ImageDraw.ImageDraw, cx: float, cy: float, scale: float, berry: RGBA, stem: RGBA, bow: RGBA) -> None:
    """Draw a glossy pair of coquette cherries tied with a miniature ribbon bow."""
    s = scale
    # Curved green stems meeting at top knot
    for c1, c2, end in (
        ((cx - 8 * s, cy - 30 * s), (cx - 18 * s, cy - 10 * s), (cx - 20 * s, cy + 16 * s)),
        ((cx + 8 * s, cy - 28 * s), (cx + 22 * s, cy - 8 * s), (cx + 22 * s, cy + 20 * s)),
    ):
        draw.line(pts_S(cubic_bezier((cx, cy - 42 * s), c1, c2, end, 18)), fill=stem, width=max(2, int(round(S(3.2 * s)))))
    # Stem leaf
    leaf = cubic_bezier((cx, cy - 42 * s), (cx + 16 * s, cy - 56 * s), (cx + 32 * s, cy - 46 * s), (cx + 26 * s, cy - 36 * s), 14) + cubic_bezier(
        (cx + 26 * s, cy - 36 * s), (cx + 12 * s, cy - 34 * s), (cx + 4 * s, cy - 38 * s), (cx, cy - 42 * s), 14
    )
    draw.polygon(pts_S(leaf), fill=stem)
    # Glossy berries
    r = 17.5 * s
    for bx, by in ((cx - 21 * s, cy + 20 * s), (cx + 22 * s, cy + 24 * s)):
        draw.ellipse((S(bx - r), S(by - r), S(bx + r), S(by + r)), fill=berry)
        draw.ellipse((S(bx - r * 0.45), S(by - r * 0.5), S(bx - r * 0.12), S(by - r * 0.15)), fill=(255, 224, 232, 230))
    draw_coquette_bow(draw, cx, cy - 36 * s, 0.25 * s, 6.0, stroke=bow, fill=bow, style="filled")


def draw_balloon(
    draw: ImageDraw.ImageDraw,
    cx: float,
    cy: float,
    r: float,
    fill: RGBA,
    outline: Optional[RGBA],
    string_c: RGBA,
    tilt: float = 0.0,
    tail_len: float = 49.0,
) -> None:
    """Draw a glossy floating birthday balloon with curling tail string."""
    # Oval balloon
    oval = [
        (cx + r * 0.85 * math.cos(t), cy + r * 1.05 * math.sin(t))
        for t in [i / 36 * 2 * math.pi for i in range(37)]
    ]
    if abs(tilt) > 0.1:
        oval = rotate_pts(oval, cx, cy, tilt)
    draw.polygon(pts_S(oval), fill=fill, outline=outline, width=max(2, int(round(S(1.6)))) if outline else 1)
    # Specular highlight
    hx, hy = cx - r * 0.28, cy - r * 0.36
    draw.ellipse((S(hx - r * 0.16), S(hy - r * 0.28), S(hx + r * 0.14), S(hy + r * 0.14)), fill=(255, 255, 255, 165))
    # Balloon knot
    draw.polygon(
        pts_S(rotate_pts([(cx, cy + r * 1.04), (cx - 4, cy + r * 1.18), (cx + 4, cy + r * 1.18)], cx, cy, tilt)),
        fill=fill,
    )
    # Wavy string below
    start = rotate_pts([(cx, cy + r * 1.18)], cx, cy, tilt)[0]
    steps = max(4, int(round(tail_len / 3.8)))
    s_pts = [
        (start[0] + 5 * math.sin(i * 0.55), start[1] + (i / steps) * tail_len)
        for i in range(steps + 1)
    ]
    draw.line(pts_S(s_pts), fill=string_c, width=max(2, int(round(S(1.4)))))


def draw_bunting(draw: ImageDraw.ImageDraw, x0: float, y0: float, x1: float, y1: float, colors: Sequence[RGBA], count: int = 5, sag: float = 16.0) -> None:
    """Draw a pastel triangular bunting pennant garland along an upper margin."""
    chord_pts = []
    for i in range(count * 4 + 1):
        t = i / (count * 4)
        px = x0 + (x1 - x0) * t
        py = y0 + (y1 - y0) * t + sag * math.sin(math.pi * t)
        chord_pts.append((px, py))
    draw.line(pts_S(chord_pts), fill=(196, 178, 165, 255), width=max(2, int(round(S(1.5)))))
    for i in range(count):
        t0 = (i + 0.12) / count
        t1 = (i + 0.88) / count
        tm = 0.5 * (t0 + t1)
        ax = x0 + (x1 - x0) * t0
        ay = y0 + (y1 - y0) * t0 + sag * math.sin(math.pi * t0)
        bx = x0 + (x1 - x0) * t1
        by = y0 + (y1 - y0) * t1 + sag * math.sin(math.pi * t1)
        mx = x0 + (x1 - x0) * tm
        my = y0 + (y1 - y0) * tm + sag * math.sin(math.pi * tm) + 20.0
        draw.polygon(pts_S([(ax, ay), (bx, by), (mx, my)]), fill=colors[i % len(colors)])


def draw_cupcake(draw: ImageDraw.ImageDraw, cx: float, cy: float, scale: float, liner_c: RGBA, frosting_c: RGBA, flame_c: RGBA) -> None:
    """Draw an adorable mini birthday cupcake with lit candle."""
    s = scale
    # Trapezoid cup liner
    cup = [(cx - 14 * s, cy + 2 * s), (cx + 14 * s, cy + 2 * s), (cx + 10 * s, cy + 18 * s), (cx - 10 * s, cy + 18 * s)]
    draw.polygon(pts_S(cup), fill=liner_c)
    # Swirled frosting dome
    draw.ellipse((S(cx - 16 * s), S(cy - 10 * s), S(cx + 16 * s), S(cy + 6 * s)), fill=frosting_c)
    draw.ellipse((S(cx - 11 * s), S(cy - 16 * s), S(cx + 11 * s), S(cy - 2 * s)), fill=frosting_c)
    # Candle & flame
    draw.rectangle((S(cx - 2 * s), S(cy - 28 * s), S(cx + 2 * s), S(cy - 14 * s)), fill=(255, 255, 255, 255))
    draw.ellipse((S(cx - 3.2 * s), S(cy - 36 * s), S(cx + 3.2 * s), S(cy - 28 * s)), fill=flame_c)


# ---------------------------------------------------------------------------
# Individual Template Artwork Builders (5 Wedding + 5 Birthday = 10 total)
# ---------------------------------------------------------------------------

def build_wedding_burgundy_single(layout: dict) -> Tuple[Image.Image, Image.Image]:
    ivory = (250, 245, 236)
    ivory_a = (250, 245, 236, 255)
    burgundy = (110, 29, 40, 255)
    soft_burgundy = (146, 65, 76, 255)

    bg = make_textured_background(ivory, seed=11, grain=2.1)
    hi = build_base_frame_matte(layout, ivory_a, top_band=76, bottom_band=1748 - 1668, corner_radius=14, slot_border_color=burgundy, slot_border_width=2.0)
    draw = ImageDraw.Draw(hi)

    # Outer fine editorial double hairline inset
    draw.rounded_rectangle((S(14), S(14), S(CANVAS_W - 14), S(CANVAS_H - 14)), radius=S(8), outline=(218, 200, 186, 255), width=max(2, int(round(S(1.4)))))

    f_serif_top = resolve_font(FONT_SERIF_LUX, 22)
    f_script_bot = resolve_font(FONT_SCRIPT, 44)
    f_caps_bot = resolve_font(FONT_SERIF_LUX, 17)

    draw_tracked_text(draw, CANVAS_W / 2, 37, "SAVE THE DATE", f_serif_top, burgundy, tracking=7.5)
    # Small flanking divider ticks in top band
    draw.line(pts_S([(320, 37), (415, 37)]), fill=soft_burgundy, width=max(2, int(round(S(1.2)))))
    draw.line(pts_S([(766, 37), (861, 37)]), fill=soft_burgundy, width=max(2, int(round(S(1.2)))))

    draw.text((S(CANVAS_W / 2), S(1692)), "To Love and To Cherish", font=f_script_bot, fill=burgundy, anchor="mm")
    draw_tracked_text(draw, CANVAS_W / 2, 1722, "FOREVER  &  ALWAYS", f_caps_bot, soft_burgundy, tracking=5.5)

    # Signature Ref #1 style ivory-filled burgundy-outlined corner bows positioned cleanly in header/footer corners
    draw_coquette_bow(draw, 70, 44, scale=0.45, angle_deg=-26, stroke=burgundy, fill=ivory_a, style="outline", aspect_x=1.08)
    draw_coquette_bow(draw, CANVAS_W - 70, 1702, scale=0.45, angle_deg=-26, stroke=burgundy, fill=ivory_a, style="outline", aspect_x=1.08)

    return bg, hi


def build_wedding_burgundy_strip(layout: dict) -> Tuple[Image.Image, Image.Image]:
    ivory = (250, 245, 236)
    ivory_a = (250, 245, 236, 255)
    burgundy = (110, 29, 40, 255)
    soft_burgundy = (150, 72, 82, 255)
    warm_line = (212, 194, 178, 255)

    bg = make_textured_background(ivory, seed=22, grain=2.1)
    hi = build_base_frame_matte(layout, ivory_a, top_band=35, bottom_band=35, corner_radius=12, slot_border_color=burgundy, slot_border_width=2.0)
    draw = ImageDraw.Draw(hi)

    # Delicate editorial outer perimeter hairlines inside sidebars
    draw.rectangle((S(18), S(20), S(301), S(CANVAS_H - 20)), outline=warm_line, width=max(2, int(round(S(1.2)))))
    draw.rectangle((S(880), S(20), S(CANVAS_W - 18), S(CANVAS_H - 20)), outline=warm_line, width=max(2, int(round(S(1.2)))))

    # Left 319px sidebar (cx = 160): iconic large burgundy gouache coquette bow + editorial wedding suite
    draw_coquette_bow(draw, 160, 365, scale=1.32, angle_deg=-5, stroke=burgundy, fill=burgundy, highlight=ivory_a, style="filled")

    f_caps = resolve_font(FONT_SERIF_LUX, 25)
    f_script = resolve_font(FONT_SCRIPT, 44)
    f_mono = resolve_font(FONT_SERIF_ITALIC, 68)
    f_small = resolve_font(FONT_SERIF_LUX, 17)

    draw_tracked_text(draw, 160, 680, "SAVE THE DATE", f_caps, burgundy, tracking=3.2)
    draw.line(pts_S([(86, 725), (234, 725)]), fill=soft_burgundy, width=max(2, int(round(S(1.2)))))

    # Ornate wedding crest emblem
    draw_wedding_rings_crest(draw, 160, 885, 48, burgundy, soft_burgundy)
    draw.text((S(160), S(990)), "Love", font=f_script, fill=burgundy, anchor="mm")
    draw.text((S(160), S(1080)), "&", font=f_mono, fill=soft_burgundy, anchor="mm")

    draw.line(pts_S([(86, 1170), (234, 1170)]), fill=soft_burgundy, width=max(2, int(round(S(1.2)))))
    draw.text((S(160), S(1235)), "To Love", font=f_script, fill=burgundy, anchor="mm")
    draw.text((S(160), S(1290)), "and To Cherish", font=f_script, fill=burgundy, anchor="mm")
    draw_tracked_text(draw, 160, 1465, "FOREVER & ALWAYS", f_small, soft_burgundy, tracking=2.8)
    draw_botanical_sprig(draw, 160, 1590, 96, 90, burgundy, soft_burgundy, ivory_a, pairs=4)

    # Right 319px sidebar (cx = 1021): balanced vertical wedding typography + botanical + bow
    draw_tracked_text(draw, 1021, 165, "HAPPY WEDDING", f_caps, burgundy, tracking=3.0)
    draw.line(pts_S([(946, 210), (1096, 210)]), fill=soft_burgundy, width=max(2, int(round(S(1.2)))))
    draw.text((S(1021), S(280)), "Best Day Ever", font=f_script, fill=soft_burgundy, anchor="mm")

    draw_botanical_sprig(draw, 1021, 610, 260, -90, soft_burgundy, burgundy, (235, 220, 206, 255), pairs=6)
    draw_coquette_bow(draw, 1021, 740, scale=0.45, angle_deg=6, stroke=burgundy, fill=ivory_a, style="outline")

    draw_tracked_text(draw, 1021, 1020, "TWO HEARTS", f_small, burgundy, tracking=3.5)
    draw_tracked_text(draw, 1021, 1054, "ONE LOVE", f_small, soft_burgundy, tracking=4.0)
    draw_coquette_bow(draw, 1021, 1365, scale=1.08, angle_deg=8, stroke=burgundy, fill=ivory_a, style="outline")

    return bg, hi


def build_wedding_rose_grid(layout: dict) -> Tuple[Image.Image, Image.Image]:
    white = (255, 254, 254)
    white_a = (255, 255, 255, 255)
    rose = (166, 82, 112, 255)
    soft_rose = (192, 116, 144, 255)
    blush_fill = (249, 224, 232, 255)

    bg = make_textured_background(white, seed=33, grain=1.2)
    hi = build_base_frame_matte(layout, white_a, top_band=76, bottom_band=1748 - 1672, corner_radius=10, slot_border_color=soft_rose, slot_border_width=1.6)
    draw = ImageDraw.Draw(hi)

    f_title = resolve_font(FONT_SERIF_LUX, 27)
    f_sub = resolve_font(FONT_SERIF_ITALIC, 22)
    f_foot = resolve_font(FONT_SERIF_LUX, 21)

    # Top editorial typography matching Reference #2 right card
    draw_tracked_text(draw, CANVAS_W / 2, 24, "HAPPY WEDDING", f_title, rose, tracking=6.5)
    draw.text((S(CANVAS_W / 2), S(56)), "Forever  &  Always  ·  With Love", font=f_sub, fill=soft_rose, anchor="mm")
    draw.line(pts_S([(190, 56), (370, 56)]), fill=(234, 200, 212, 255), width=max(2, int(round(S(1.2)))))
    draw.line(pts_S([(811, 56), (991, 56)]), fill=(234, 200, 212, 255), width=max(2, int(round(S(1.2)))))

    # Bottom footer
    draw_tracked_text(draw, CANVAS_W / 2, 1695, "SAVE THE DATE", f_foot, rose, tracking=5.8)
    draw_heart(draw, CANVAS_W / 2, 1724, 8.5, rose)
    draw_tracked_text(draw, CANVAS_W / 2 - 148, 1724, "TRUE LOVE", resolve_font(FONT_SERIF_LUX, 15), soft_rose, tracking=4.0)
    draw_tracked_text(draw, CANVAS_W / 2 + 148, 1724, "BEST DAY", resolve_font(FONT_SERIF_LUX, 15), soft_rose, tracking=4.0)

    # Signature center-cross horizontal coquette silk bow at (590, 874) cleanly inside y=[815..933]
    draw_coquette_bow(draw, 590, 872, scale=0.72, angle_deg=0, stroke=rose, fill=blush_fill, highlight=white_a, style="center_ribbon", aspect_x=1.22)

    # Opposite outer margin bows safely tucked outside 50px slot interior
    draw_coquette_bow(draw, 64, 46, scale=0.38, angle_deg=-22, stroke=rose, fill=white_a, style="outline")
    draw_coquette_bow(draw, CANVAS_W - 64, 1702, scale=0.38, angle_deg=-22, stroke=rose, fill=white_a, style="outline")

    return bg, hi


def build_wedding_rose_postcard(layout: dict) -> Tuple[Image.Image, Image.Image]:
    white = (255, 254, 254)
    white_a = (255, 255, 255, 255)
    rose = (182, 102, 130, 255)
    soft_rose = (206, 138, 162, 255)

    bg = make_textured_background(white, seed=44, grain=1.2)
    # Keep top band shallow (42px) so heads in horizontal 2:1 postcard slots are completely clear
    hi = build_base_frame_matte(layout, white_a, top_band=42, bottom_band=1748 - 1672, corner_radius=8, slot_border_color=(232, 198, 210, 255), slot_border_width=1.4)
    draw = ImageDraw.Draw(hi)

    f_top = resolve_font(FONT_SERIF_LUX, 24)
    f_mid = resolve_font(FONT_SERIF_LUX, 21)
    f_bot = resolve_font(FONT_SERIF_LUX, 19)
    f_script = resolve_font(FONT_SCRIPT, 34)

    draw_tracked_text(draw, CANVAS_W / 2, 21, "HAPPY WEDDING", f_top, rose, tracking=6.5)

    # Centered editorial divider badge between slots 1 and 2 (inside safe y=566..614 band, x=280..901)
    draw.rounded_rectangle((S(295), S(570), S(886), S(610)), radius=S(20), fill=white_a, outline=(232, 196, 210, 255), width=max(2, int(round(S(1.4)))))
    draw_tracked_text(draw, CANVAS_W / 2, 590, "TO LOVE  &  TO CHERISH", f_mid, rose, tracking=4.5)

    # Subtle smaller divider badge between slots 2 and 3 (inside safe y=1143..1171 band)
    draw.rounded_rectangle((S(415), S(1143), S(766), S(1171)), radius=S(14), fill=white_a, outline=(232, 196, 210, 255), width=max(2, int(round(S(1.3)))))
    draw_tracked_text(draw, CANVAS_W / 2, 1157, "FOREVER BEGINS", resolve_font(FONT_SERIF_LUX, 15), soft_rose, tracking=4.8)

    # Footer below slot 3
    draw.text((S(CANVAS_W / 2), S(1694)), "Just Married", font=f_script, fill=rose, anchor="mm")
    draw_tracked_text(draw, CANVAS_W / 2, 1726, "SAVE THE DATE  ·  THE BEST DAY EVER", f_bot, soft_rose, tracking=4.2)

    # Opposite diagonal delicate bows hugging corners cleanly outside 50px inset
    draw_coquette_bow(draw, 58, 38, scale=0.35, angle_deg=-26, stroke=rose, fill=white_a, style="outline")
    draw_coquette_bow(draw, CANVAS_W - 58, 1702, scale=0.38, angle_deg=-26, stroke=rose, fill=white_a, style="outline")

    return bg, hi


def build_wedding_champagne_grid(layout: dict) -> Tuple[Image.Image, Image.Image]:
    champagne = (250, 246, 236)
    champagne_a = (250, 246, 236, 255)
    gold = (166, 129, 66, 255)
    light_gold = (206, 176, 122, 255)
    sage = (122, 142, 108, 255)
    ivory_pearl = (255, 253, 248, 255)

    bg = make_textured_background(champagne, seed=55, grain=2.0)
    hi = build_base_frame_matte(layout, champagne_a, top_band=76, bottom_band=1748 - 1672, corner_radius=12, slot_border_color=light_gold, slot_border_width=2.0)
    draw = ImageDraw.Draw(hi)

    # Fine outer champagne-gold frame border
    draw.rounded_rectangle((S(14), S(14), S(CANVAS_W - 14), S(CANVAS_H - 14)), radius=S(8), outline=light_gold, width=max(2, int(round(S(1.6)))))

    f_title = resolve_font(FONT_SERIF_LUX, 25)
    f_sub = resolve_font(FONT_SCRIPT, 40)
    f_foot = resolve_font(FONT_SERIF_LUX, 17)

    draw_tracked_text(draw, CANVAS_W / 2, 32, "WEDDING DAY", f_title, gold, tracking=7.0)
    draw_tracked_text(draw, CANVAS_W / 2, 58, "FOREVER  ·  ALWAYS", resolve_font(FONT_SERIF_LUX, 14), light_gold, tracking=5.5)
    draw_botanical_sprig(draw, 255, 42, 130, 0, sage, gold, ivory_pearl, pairs=4)
    draw_botanical_sprig(draw, CANVAS_W - 255, 42, 130, 180, sage, gold, ivory_pearl, pairs=4)

    # Center intersection crest (rings + flanking botanical sprigs cleanly inside y=[824..924])
    draw.ellipse((S(590 - 40), S(874 - 40), S(590 + 40), S(874 + 40)), fill=champagne_a, outline=gold, width=max(2, int(round(S(2.0)))))
    draw.ellipse((S(590 - 34), S(874 - 34), S(590 + 34), S(874 + 34)), outline=light_gold, width=max(2, int(round(S(1.0)))))
    draw_wedding_rings_crest(draw, 590, 876, 17, gold, light_gold)
    draw_botanical_sprig(draw, 468, 874, 145, 180, sage, gold, ivory_pearl, pairs=4)
    draw_botanical_sprig(draw, 712, 874, 145, 0, sage, gold, ivory_pearl, pairs=4)

    # Footer
    draw.text((S(CANVAS_W / 2), S(1692)), "Happily Ever After", font=f_sub, fill=gold, anchor="mm")
    draw_tracked_text(draw, CANVAS_W / 2, 1721, "CELEBRATING LOVE", f_foot, (158, 122, 60, 255), tracking=6.0)
    draw_sparkle(draw, 355, 1702, 8, light_gold)
    draw_sparkle(draw, CANVAS_W - 355, 1702, 8, light_gold)

    return bg, hi


def build_birthday_coquette_strip(layout: dict) -> Tuple[Image.Image, Image.Image]:
    buttercream = (255, 248, 232)
    buttercream_a = (255, 248, 232, 255)
    cherry = (202, 54, 82, 255)
    rose_pink = (232, 118, 148, 255)
    blush = (255, 224, 232, 255)
    sage = (108, 152, 102, 255)
    gold = (242, 174, 62, 255)

    # Soft bakery gingham cream check background
    bg = make_textured_background(buttercream, seed=66, grain=1.5, gingham=((255, 239, 206), 36, 0.55))
    hi = build_base_frame_matte(layout, buttercream_a, top_band=35, bottom_band=35, corner_radius=16, slot_border_color=cherry, slot_border_width=2.2)
    draw = ImageDraw.Draw(hi)

    # Re-apply subtle gingham feel inside sidebar overlays by keeping center sidebars slightly tinted cream border
    draw.rounded_rectangle((S(16), S(18), S(303), S(CANVAS_H - 18)), radius=S(18), fill=(255, 250, 238, 255), outline=(240, 214, 184, 255), width=max(2, int(round(S(1.8)))))
    draw.rounded_rectangle((S(878), S(18), S(CANVAS_W - 16), S(CANVAS_H - 18)), radius=S(18), fill=(255, 250, 238, 255), outline=(240, 214, 184, 255), width=max(2, int(round(S(1.8)))))

    f_title = resolve_font(FONT_SERIF_LUX, 26)
    f_script = resolve_font(FONT_HAND, 30)
    f_small = resolve_font(FONT_SANS_BOLD, 15)

    # Left sidebar: hero cherry coquette bow + HAPPY BIRTHDAY + tiered cake + cherry duo
    draw_coquette_bow(draw, 160, 220, scale=1.18, angle_deg=-4, stroke=cherry, fill=cherry, highlight=blush, style="filled")
    draw_tracked_text(draw, 160, 445, "HAPPY", f_title, cherry, tracking=4.5)
    draw_tracked_text(draw, 160, 488, "BIRTHDAY", f_title, cherry, tracking=4.0)
    draw.text((S(160), S(545)), "Make a Wish", font=f_script, fill=rose_pink, anchor="mm")

    draw_birthday_cake(
        draw, 160, 885, 1.38,
        {"cake": (255, 252, 246, 255), "icing": blush, "outline": cherry, "flame": gold, "ribbon": cherry},
    )

    draw_cherry_duo(draw, 160, 1295, 1.32, cherry, sage, rose_pink)
    draw_tracked_text(draw, 160, 1485, "SWEETEST DAY", f_small, cherry, tracking=3.5)
    draw_sparkle(draw, 72, 650, 10, gold)
    draw_sparkle(draw, 248, 1110, 11, rose_pink)
    draw_sparkle(draw, 84, 1560, 9, gold)

    # Right sidebar: festive balloons + CHEERS TO YOU + cupcakes + mini bows
    draw_balloon(draw, 975, 230, 40, blush, cherry, rose_pink, tilt=-8)
    draw_balloon(draw, 1068, 285, 36, (254, 234, 178, 255), gold, cherry, tilt=10)

    draw_tracked_text(draw, 1021, 560, "CHEERS", f_title, cherry, tracking=4.5)
    draw_tracked_text(draw, 1021, 602, "TO YOU", f_small, rose_pink, tracking=5.0)
    draw_coquette_bow(draw, 1021, 745, scale=0.65, angle_deg=6, stroke=cherry, fill=blush, style="silk")

    draw_cupcake(draw, 1021, 955, 2.0, (240, 194, 134, 255), blush, gold)
    draw.text((S(1021), S(1110)), "Celebrate!", font=f_script, fill=cherry, anchor="mm")

    draw_cherry_duo(draw, 1021, 1325, 1.05, cherry, sage, cherry)
    draw_tracked_text(draw, 1021, 1500, "SPECIAL MOMENTS", f_small, rose_pink, tracking=2.6)
    draw_sparkle(draw, 932, 460, 10, gold)
    draw_sparkle(draw, 1115, 830, 11, gold)
    draw_sparkle(draw, 945, 1190, 9, rose_pink)

    return bg, hi


def build_birthday_rose_grid(layout: dict) -> Tuple[Image.Image, Image.Image]:
    blush_bg = (255, 245, 248)
    matte = (255, 245, 248, 255)
    berry = (204, 72, 118, 255)
    pink = (244, 170, 198, 255)
    cream = (255, 255, 255, 255)
    gold = (240, 178, 72, 255)

    bg = make_textured_background(blush_bg, seed=77, grain=1.4)
    hi = build_base_frame_matte(layout, matte, top_band=76, bottom_band=1748 - 1672, corner_radius=16, slot_border_color=cream, slot_border_width=4.0)
    draw = ImageDraw.Draw(hi)

    # Crisp berry hairline inner frame around slots
    for slot in layout["slots"]:
        sx, sy, sw, sh = slot["x"], slot["y"], slot["width"], slot["height"]
        y0 = max(sy, 76)
        y1 = min(sy + sh, 1672)
        draw.rounded_rectangle((S(sx), S(y0), S(sx + sw), S(y1)), radius=S(16), outline=pink, width=max(2, int(round(S(1.8)))))

    f_title = resolve_font(FONT_SERIF_LUX, 26)
    f_sub = resolve_font(FONT_HAND, 23)
    f_foot = resolve_font(FONT_SANS_BOLD, 16)

    draw_tracked_text(draw, CANVAS_W / 2, 26, "HAPPY BIRTHDAY", f_title, berry, tracking=5.8)
    draw.text((S(CANVAS_W / 2), S(56)), "Make a Wish  ·  Best Day Ever", font=f_sub, fill=(214, 114, 148, 255), anchor="mm")
    draw_sparkle(draw, 225, 40, 10, gold)
    draw_sparkle(draw, CANVAS_W - 225, 40, 10, gold)
    draw_sparkle(draw, 195, 56, 6, berry)
    draw_sparkle(draw, CANVAS_W - 195, 56, 6, berry)

    # Center-cross festive berry-pink coquette silk bow at (590, 874)
    draw_coquette_bow(draw, 590, 872, scale=0.72, angle_deg=0, stroke=berry, fill=pink, highlight=cream, style="center_ribbon", aspect_x=1.22)

    # Scattered dainty sparkles & tiny hearts along outer side borders (x<=32 or x>=1149)
    for px, py in ((17, 260), (17, 640), (17, 1080), (17, 1460), (CANVAS_W - 17, 320), (CANVAS_W - 17, 740), (CANVAS_W - 17, 1140), (CANVAS_W - 17, 1510)):
        draw_sparkle(draw, px, py, 7, gold if py % 40 == 0 else pink)

    # Bottom footer
    draw_tracked_text(draw, CANVAS_W / 2, 1698, "CELEBRATE TODAY  ·  CHEERS TO YOU", f_foot, berry, tracking=4.5)
    draw_heart(draw, CANVAS_W / 2, 1728, 7.5, berry)

    # Corner bows
    draw_coquette_bow(draw, 68, 46, scale=0.35, angle_deg=-20, stroke=berry, fill=cream, style="outline")
    draw_coquette_bow(draw, CANVAS_W - 68, 1700, scale=0.35, angle_deg=-20, stroke=berry, fill=cream, style="outline")

    return bg, hi


def build_birthday_cake_postcard(layout: dict) -> Tuple[Image.Image, Image.Image]:
    vanilla = (255, 251, 240)
    vanilla_a = (255, 251, 240, 255)
    strawberry = (224, 82, 116, 255)
    apricot = (246, 174, 86, 255)
    sky = (116, 178, 224, 255)
    lavender = (168, 134, 214, 255)
    mint = (122, 186, 152, 255)
    cream_white = (255, 255, 255, 255)

    bg = make_textured_background(vanilla, seed=88, grain=1.5)
    hi = build_base_frame_matte(layout, vanilla_a, top_band=46, bottom_band=1748 - 1672, corner_radius=12, slot_border_color=(244, 206, 214, 255), slot_border_width=2.0)
    draw = ImageDraw.Draw(hi)

    f_top = resolve_font(FONT_SANS_BOLD, 24)
    f_pill = resolve_font(FONT_SANS_BOLD, 16)
    f_bot = resolve_font(FONT_HAND, 30)

    # Bunting pennants in upper corners & crisp HAPPY BIRTHDAY title at top (shallow y<=46)
    garland_colors = (strawberry, apricot, sky, mint, lavender)
    draw_bunting(draw, 14, 6, 240, 14, garland_colors, count=5, sag=11)
    draw_bunting(draw, CANVAS_W - 240, 14, CANVAS_W - 14, 6, list(reversed(garland_colors)), count=5, sag=11)
    draw_tracked_text(draw, CANVAS_W / 2, 23, "HAPPY BIRTHDAY!", f_top, strawberry, tracking=5.0)

    # Playful confetti dots along left/right 35px borders
    confetti_palette = (strawberry, apricot, sky, lavender, mint)
    for idx in range(18):
        cy_dot = 120 + idx * 88
        cx_dot = 17 if idx % 2 == 0 else CANVAS_W - 17
        col = confetti_palette[idx % len(confetti_palette)]
        if idx % 3 == 0:
            draw_sparkle(draw, cx_dot, cy_dot, 7.5, col)
        else:
            draw.ellipse((S(cx_dot - 5), S(cy_dot - 5), S(cx_dot + 5), S(cy_dot + 5)), fill=col)

    # Centered pastel divider banner between slot 1 and slot 2 (safe band y=566..614)
    draw.rounded_rectangle((S(350), S(568), S(831), S(612)), radius=S(22), fill=cream_white, outline=apricot, width=max(2, int(round(S(1.8)))))
    draw_tracked_text(draw, CANVAS_W / 2, 590, "MAKE A WISH", f_pill, strawberry, tracking=4.5)
    draw_cupcake(draw, 396, 590, 0.72, apricot, (250, 184, 204, 255), apricot)
    draw_cupcake(draw, 785, 590, 0.72, sky, (250, 184, 204, 255), apricot)

    # Secondary mini pill between slot 2 and slot 3 (safe band y=1143..1171)
    draw.rounded_rectangle((S(440), S(1143), S(741), S(1171)), radius=S(14), fill=cream_white, outline=lavender, width=max(2, int(round(S(1.5)))))
    draw_tracked_text(draw, CANVAS_W / 2, 1157, "SPECIAL DAY", resolve_font(FONT_SANS_BOLD, 15), lavender, tracking=4.5)

    # Bottom footer with glowing birthday candles row & calligraphic wish
    draw.text((S(CANVAS_W / 2), S(1706)), "Sweetest Birthday Wishes", font=f_bot, fill=strawberry, anchor="mm")
    for cx_c, col_c in ((220, sky), (255, apricot), (290, strawberry), (891, strawberry), (926, apricot), (961, lavender)):
        draw.rounded_rectangle((S(cx_c - 4), S(1692), S(cx_c + 4), S(1726)), radius=S(2), fill=col_c)
        draw.ellipse((S(cx_c - 4), S(1680), S(cx_c + 4), S(1690)), fill=(255, 198, 72, 255))

    return bg, hi


def build_birthday_gala_single(layout: dict) -> Tuple[Image.Image, Image.Image]:
    navy = (22, 28, 44)
    navy_a = (22, 28, 44, 255)
    gold = (229, 194, 112, 255)
    pale_gold = (246, 224, 168, 255)
    deep_gold = (176, 140, 68, 255)

    bg = make_textured_background(navy, seed=99, grain=1.8)
    hi = build_base_frame_matte(layout, navy_a, top_band=76, bottom_band=1748 - 1672, corner_radius=12, slot_border_color=gold, slot_border_width=2.2)
    draw = ImageDraw.Draw(hi)

    # Double luxury Art-Deco champagne foil frame
    draw.rounded_rectangle((S(12), S(12), S(CANVAS_W - 12), S(CANVAS_H - 12)), radius=S(8), outline=deep_gold, width=max(2, int(round(S(1.6)))))
    draw.rounded_rectangle((S(18), S(18), S(CANVAS_W - 18), S(CANVAS_H - 18)), radius=S(6), outline=gold, width=max(2, int(round(S(0.9)))))

    f_top = resolve_font(FONT_SERIF_LUX, 21)
    f_bot = resolve_font(FONT_SERIF_BOLD, 33)
    f_sub = resolve_font(FONT_SERIF_ITALIC, 21)

    draw_tracked_text(draw, CANVAS_W / 2, 41, "LET'S CELEBRATE", f_top, pale_gold, tracking=7.5)
    draw.line(pts_S([(230, 41), (370, 41)]), fill=deep_gold, width=max(2, int(round(S(1.2)))))
    draw.line(pts_S([(811, 41), (951, 41)]), fill=deep_gold, width=max(2, int(round(S(1.2)))))

    draw_tracked_text(draw, CANVAS_W / 2, 1692, "HAPPY BIRTHDAY", f_bot, gold, tracking=6.5)
    draw.text((S(CANVAS_W / 2), S(1720)), "Make a Wish  ·  Shine Bright", font=f_sub, fill=pale_gold, anchor="mm")

    # Foil corner sparkles & petite gold ribbon accents cleanly inside top/bottom corners
    for cx_s, cy_s, r_s, c_s in (
        (64, 42, 14, pale_gold),
        (106, 56, 7, gold),
        (CANVAS_W - 64, 42, 14, pale_gold),
        (CANVAS_W - 106, 56, 7, gold),
        (68, 1706, 15, pale_gold),
        (114, 1690, 7, gold),
        (CANVAS_W - 68, 1706, 15, pale_gold),
        (CANVAS_W - 114, 1690, 7, gold),
    ):
        draw_sparkle(draw, cx_s, cy_s, r_s, c_s)

    return bg, hi


def build_birthday_pastel_grid(layout: dict) -> Tuple[Image.Image, Image.Image]:
    bg = draw_pastel_rainbow_bg(seed=120)
    # Inherit the silky pastel rainbow gradient onto the outer frame + rounded slot spandrels
    hi = bg.convert("RGBA").resize((CANVAS_W * SS, CANVAS_H * SS), Image.Resampling.BILINEAR)
    alpha = Image.new("L", (CANVAS_W * SS, CANVAS_H * SS), 255)
    adraw = ImageDraw.Draw(alpha)
    for slot in layout["slots"]:
        sx, sy, sw, sh = slot["x"], slot["y"], slot["width"], slot["height"]
        y0 = max(sy, 76)
        y1 = min(sy + sh, 1672)
        adraw.rounded_rectangle((S(sx), S(y0), S(sx + sw), S(y1)), radius=S(20), fill=0)
    hi.putalpha(alpha)
    draw = ImageDraw.Draw(hi)

    lilac = (138, 102, 198, 255)
    soft_lilac = (182, 154, 226, 255)
    pink = (242, 142, 178, 255)
    sky = (128, 186, 232, 255)
    butter = (246, 202, 92, 255)
    cream_a = (255, 252, 255, 255)

    for slot in layout["slots"]:
        sx, sy, sw, sh = slot["x"], slot["y"], slot["width"], slot["height"]
        y0 = max(sy, 76)
        y1 = min(sy + sh, 1672)
        draw.rounded_rectangle((S(sx), S(y0), S(sx + sw), S(y1)), radius=S(20), outline=cream_a, width=max(4, int(round(S(5.0)))))
        draw.rounded_rectangle((S(sx), S(y0), S(sx + sw), S(y1)), radius=S(20), outline=soft_lilac, width=max(2, int(round(S(1.6)))))

    f_title = resolve_font(FONT_SANS_BOLD, 27)
    f_sub = resolve_font(FONT_HAND, 23)
    f_foot = resolve_font(FONT_SANS_BOLD, 17)

    draw_tracked_text(draw, CANVAS_W / 2, 27, "HAPPY BIRTHDAY", f_title, lilac, tracking=5.4)
    draw.text((S(CANVAS_W / 2), S(57)), "Sweet Moments  ·  Celebrate Today", font=f_sub, fill=pink, anchor="mm")

    # Pastel corner balloons tucked neatly inside upper corners (y<=74, safe above y=85)
    draw_balloon(draw, 38, 25, 16, pink, None, lilac, tilt=-12, tail_len=24.0)
    draw_balloon(draw, 70, 30, 13, sky, None, lilac, tilt=10, tail_len=20.0)
    draw_balloon(draw, CANVAS_W - 38, 25, 16, butter, None, lilac, tilt=12, tail_len=24.0)
    draw_balloon(draw, CANVAS_W - 70, 30, 13, soft_lilac, None, lilac, tilt=-10, tail_len=20.0)

    # Center cross pastel ribbon pill at (590, 874)
    draw.rounded_rectangle((S(445), S(850), S(735), S(898)), radius=S(24), fill=cream_a, outline=soft_lilac, width=max(2, int(round(S(1.8)))))
    draw_tracked_text(draw, 590, 874, "MAKE A WISH", resolve_font(FONT_SANS_BOLD, 16), lilac, tracking=4.2)
    draw_sparkle(draw, 468, 874, 8, butter)
    draw_sparkle(draw, 712, 874, 8, pink)

    # Pastel sprinkles along left & right 35px margins
    sprinkles = (pink, sky, butter, soft_lilac)
    for idx in range(16):
        py = 135 + idx * 96
        px = 17 if idx % 2 == 0 else CANVAS_W - 17
        col = sprinkles[idx % len(sprinkles)]
        draw_sparkle(draw, px, py, 8, col)

    # Bottom footer
    draw_tracked_text(draw, CANVAS_W / 2, 1700, "LET'S CELEBRATE  ·  BEST DAY EVER", f_foot, lilac, tracking=4.2)
    draw_heart(draw, 225, 1700, 9, pink)
    draw_heart(draw, CANVAS_W - 225, 1700, 9, sky)
    draw_sparkle(draw, 190, 1700, 7, butter)
    draw_sparkle(draw, CANVAS_W - 190, 1700, 7, butter)

    return bg, hi


TEMPLATE_SPECS = [
    {
        "id": "wedding-burgundy-single",
        "name": "Cưới · Nơ Rượu Vang (Đơn)",
        "style": "wedding-burgundy",
        "layout_id": "single",
        "builder": build_wedding_burgundy_single,
    },
    {
        "id": "wedding-burgundy-strip",
        "name": "Cưới · Nơ Rượu Vang (Dải 3)",
        "style": "wedding-burgundy",
        "layout_id": "strip-three",
        "builder": build_wedding_burgundy_strip,
    },
    {
        "id": "wedding-rose-grid",
        "name": "Cưới · Nơ Hồng Phấn (2×2)",
        "style": "wedding-rose",
        "layout_id": "grid-four",
        "builder": build_wedding_rose_grid,
    },
    {
        "id": "wedding-rose-postcard",
        "name": "Cưới · Hồng Phấn (Postcard)",
        "style": "wedding-rose",
        "layout_id": "postcard-three",
        "builder": build_wedding_rose_postcard,
    },
    {
        "id": "wedding-champagne-grid",
        "name": "Cưới · Vàng Champagne (2×2)",
        "style": "wedding-champagne",
        "layout_id": "grid-four",
        "builder": build_wedding_champagne_grid,
    },
    {
        "id": "birthday-coquette-strip",
        "name": "Sinh nhật · Nơ Kem Dâu (Dải 3)",
        "style": "birthday-coquette",
        "layout_id": "strip-three",
        "builder": build_birthday_coquette_strip,
    },
    {
        "id": "birthday-rose-grid",
        "name": "Sinh nhật · Nơ Hồng Pastel (2×2)",
        "style": "birthday-rose",
        "layout_id": "grid-four",
        "builder": build_birthday_rose_grid,
    },
    {
        "id": "birthday-cake-postcard",
        "name": "Sinh nhật · Bánh Kem Nến (Postcard)",
        "style": "birthday-cake",
        "layout_id": "postcard-three",
        "builder": build_birthday_cake_postcard,
    },
    {
        "id": "birthday-gala-single",
        "name": "Sinh nhật · Tiệc Đêm Vàng (Đơn)",
        "style": "birthday-gala",
        "layout_id": "single",
        "builder": build_birthday_gala_single,
    },
    {
        "id": "birthday-pastel-grid",
        "name": "Sinh nhật · Pastel Cầu Vồng (2×2)",
        "style": "birthday-pastel",
        "layout_id": "grid-four",
        "builder": build_birthday_pastel_grid,
    },
]


def enforce_and_verify_overlay(overlay_hi: Image.Image, layout: dict, ident: str) -> Image.Image:
    """
    Downsample 2x overlay cleanly to 1181x1748 RGBA and verify both:
      1. Zero alpha (>0) inside any slot's safe interior box (x+49 .. x+w-49).
      2. At least one 9x9 fully opaque block (alpha == 255) for test_pipeline verification.
    """
    overlay = overlay_hi.resize((CANVAS_W, CANVAS_H), Image.Resampling.LANCZOS)
    if overlay.mode != "RGBA":
        overlay = overlay.convert("RGBA")

    alpha_arr = np.array(overlay.getchannel("A"), dtype=np.uint8)
    for slot in layout["slots"]:
        x0 = slot["x"] + MIN_CLEAR_INSET
        y0 = slot["y"] + MIN_CLEAR_INSET
        x1 = slot["x"] + slot["width"] - MIN_CLEAR_INSET
        y1 = slot["y"] + slot["height"] - MIN_CLEAR_INSET
        region_max = int(alpha_arr[y0:y1, x0:x1].max())
        if region_max > 0:
            raise AssertionError(
                f"Template {ident} slot {slot['slot']} intrudes into safe zone "
                f"({x0},{y0})-({x1},{y1}) with max alpha={region_max}"
            )

    opaque = np.argwhere(np.asarray(overlay.getchannel("A").filter(ImageFilter.MinFilter(9))) == 255)
    if len(opaque) == 0:
        raise AssertionError(f"Template {ident} has no 9x9 opaque overlay area")
    return overlay


def main() -> None:
    sample_photo = SAMPLES_DIR / "source-portrait.jpg"
    if not sample_photo.is_file():
        raise FileNotFoundError(f"Missing sample portrait: {sample_photo}")

    layouts: Dict[str, dict] = {}
    for layout_file in sorted(LAYOUTS_DIR.glob("*.json")):
        data = json.loads(layout_file.read_text(encoding="utf-8"))
        layouts[data["id"]] = data

    for spec in TEMPLATE_SPECS:
        ident = spec["id"]
        layout = layouts[spec["layout_id"]]
        package_dir = TEMPLATES_DIR / ident
        package_dir.mkdir(parents=True, exist_ok=True)

        bg, overlay_hi = spec["builder"](layout)
        if bg.size != (CANVAS_W, CANVAS_H) or bg.mode != "RGB":
            bg = bg.convert("RGB").resize((CANVAS_W, CANVAS_H), Image.Resampling.LANCZOS)
        overlay = enforce_and_verify_overlay(overlay_hi, layout, ident)

        bg_path = package_dir / "background.png"
        ov_path = package_dir / "overlay.png"
        prev_path = package_dir / "preview.jpg"
        tmp_sample = package_dir / "_tmp_sample.jpg"

        bg.save(bg_path, format="PNG")
        overlay.save(ov_path, format="PNG")

        manifest_spec = dict(
            layout,
            id=ident,
            overlay_path=str(ov_path),
            background_path=str(bg_path),
        )
        photos = [{"slot": i, "path": str(sample_photo)} for i in range(1, layout["capture_count"] + 1)]
        run_render_from_manifest(
            {
                "template": manifest_spec,
                "photos": photos,
                "lut_id": "original",
                "output_print_path": str(tmp_sample),
                "output_preview_path": str(prev_path),
            }
        )
        if tmp_sample.exists():
            tmp_sample.unlink()

        metadata = {
            "id": ident,
            "name": spec["name"],
            "style": spec["style"],
            "version": "1.0.0",
            "layout_id": layout["id"],
            "layout_version": str(layout.get("version", "1.0.0")),
            "overlay": "overlay.png",
            "background": "background.png",
            "preview": "preview.jpg",
            "license": "Original project artwork; MIT",
            "hashes": {
                fname: hashlib.sha256((package_dir / fname).read_bytes()).hexdigest()
                for fname in ("overlay.png", "background.png", "preview.jpg")
            },
        }
        (package_dir / "template.json").write_text(
            json.dumps(metadata, ensure_ascii=False, indent=4) + "\n", encoding="utf-8"
        )
        print(f"Generated: {ident} ({spec['layout_id']}) -> {spec['name']}")


if __name__ == "__main__":
    main()
