"""Rasterize event lettering identically for the operator preview and the final photo."""
import base64
import io
import json
import sys
from datetime import date
from functools import lru_cache
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

FONTS = Path(__file__).resolve().parent.parent / "public" / "assets" / "fonts"
LABELS = {"name": "Tên trên frame", "date": "Ngày", "wish": "Lời chúc"}
SCALE = 2


@lru_cache(maxsize=128)
def font_at(field, size):
    weight = "SemiBold" if field == "name" else "Regular"
    return ImageFont.truetype(str(FONTS / ("BeVietnamPro-" + weight + ".ttf")), size * SCALE)


def frame_layer(size, slots, fields, validate_only=False):
    scale = SCALE
    layer = Image.new("RGBA", (1, 1) if validate_only else tuple(n * scale for n in size))
    draw = ImageDraw.Draw(layer)
    for field, slot in slots.items():
        text = fields.get(field, "")
        if not text:
            continue
        if field == "date":
            text = date.fromisoformat(text).strftime("%d/%m/%Y")
        x, y, width, height = [slot[key] * scale for key in ("x", "y", "width", "height")]
        for px in range(slot["size"], slot["min_size"] - 1, -1):
            font = font_at(field, px)
            left, top, right, bottom = draw.textbbox((0, 0), text, font=font)
            if right - left <= width and bottom - top <= height:
                break
        else:
            raise ValueError(LABELS[field] + " quá dài để hiển thị rõ trên frame.")
        if not validate_only:
            draw.text((x + (width - right - left) / 2, y + (height - bottom - top) / 2),
                      text, font=font, fill=slot["color"])
    if not validate_only:
        return layer.resize(size, Image.Resampling.LANCZOS)
    return None


def main():
    request = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
    try:
        if request["preview"]:
            layer = frame_layer((1181, 1748), request["slots"][0], request["fields"])
            output = io.BytesIO()
            layer.save(output, format="PNG")
            result = {"status": "ok", "url": "data:image/png;base64," + base64.b64encode(output.getvalue()).decode()}
        else:
            for slots in request["slots"]:
                frame_layer((1181, 1748), slots, request["fields"], validate_only=True)
            result = {"status": "ok"}
    except ValueError as error:
        result = {"status": "error", "message": str(error)}
    print(json.dumps(result))


if __name__ == "__main__":
    main()
