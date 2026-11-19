"""Build lettering-ready versions of the bundled templates."""
import hashlib
import json
import shutil
import tempfile

from PIL import Image, ImageDraw

import generate_event_templates as event_art


def main():
    event_art.DRAW_LETTERING = False
    artwork = {spec["id"]: spec["builder"] for spec in event_art.TEMPLATE_SPECS}
    for package in sorted(event_art.TEMPLATES_DIR.iterdir()):
        manifest = package / "template.json"
        if not manifest.exists():
            continue
        template = json.loads(manifest.read_text(encoding="utf-8"))
        if template["version"] != "1.0.0":
            continue
        ident = template["id"]
        layout = json.loads((event_art.LAYOUTS_DIR / (template["layout_id"] + ".json")).read_text(encoding="utf-8"))
        category = ident.split("-", 1)[0]
        if category not in ("birthday", "wedding"):
            category = "other"
        target = event_art.TEMPLATES_DIR / (ident + "-v1.1.0")
        target.mkdir(exist_ok=True)
        for asset in template["hashes"]:
            shutil.copyfile(package / asset, target / asset)
        color = {"wedding": "#592936", "birthday": "#572849", "other": "#282536"}[category]
        if ident in artwork:
            _, hi = artwork[ident](layout)
            overlay = event_art.enforce_and_verify_overlay(hi, layout, ident)
        else:
            with Image.open(package / "overlay.png") as original:
                overlay = original.convert("RGBA")
        # Two narrow, opaque caption areas outside the photo slots keep lettering legible
        # against dark, floral and textured artwork.
        draw = ImageDraw.Draw(overlay)
        for top in (2, 1716):
            draw.rounded_rectangle((100, top, 1080, top + 30), radius=10, fill="#fffaf5")
        overlay.save(target / "overlay.png")
        template.update(version="1.1.0", category=category, text_slots={
            "name": {"x": 115, "y": 2, "width": 950, "height": 30, "size": 28, "min_size": 16, "color": color},
            "date": {"x": 115, "y": 1716, "width": 174, "height": 30, "size": 21, "min_size": 17, "color": color},
            "wish": {"x": 305, "y": 1716, "width": 760, "height": 30, "size": 23, "min_size": 16, "color": color},
        })
        sample = event_art.SAMPLES_DIR / "source-portrait.jpg"
        with tempfile.TemporaryDirectory(dir=target) as temp:
            event_art.run_render_from_manifest({
                "template": dict(layout, overlay_path=str(target / "overlay.png"),
                                 background_path=str(target / "background.png") if template.get("background") else None),
                "photos": [{"slot": s["slot"], "path": str(sample)} for s in layout["slots"]],
                "lut_id": "original",
                "output_print_path": temp + "/print.jpg",
                "output_preview_path": str(target / "preview.jpg"),
            })
        template["hashes"] = {name: hashlib.sha256((target / name).read_bytes()).hexdigest()
                              for name in template["hashes"]}
        (target / "template.json").write_text(json.dumps(template, ensure_ascii=False, indent=4) + "\n",
                                              encoding="utf-8")
        print(ident)


if __name__ == "__main__":
    main()
