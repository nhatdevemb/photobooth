"""Generate original, deterministic vector-style artwork and previews, entirely offline.

The shipped PNGs are the runtime assets; this script is for maintainers regenerating
catalog versions. Bump package versions before changing released artwork.
"""
from pathlib import Path
import hashlib
import json
import math
from PIL import Image, ImageDraw
from render_pipeline import run_render_from_manifest, normalize_orientation_and_srgb, validate_and_load_jpeg
from cube_lut import parse_cube_file, apply_cube_lut_rgb
from generate_presets_and_fixtures import render_synthetic_card
from render_pipeline import encode_jpeg_bytes, get_srgb_icc_bytes
import numpy as np

ROOT = Path(__file__).resolve().parents[1]
COLORS = {'minimal': (255, 255, 255), 'party': (240, 226, 255), 'floral': (255, 240, 244), 'cute': (238, 222, 253)}
NAMES = {'minimal': 'Tối giản', 'party': 'Party', 'floral': 'Hoa', 'cute': 'Cute'}


def flower(draw, x, y, color):
    for angle in range(0, 360, 60):
        px, py = x + 20*math.cos(math.radians(angle)), y + 20*math.sin(math.radians(angle))
        draw.ellipse((px-14, py-14, px+14, py+14), fill=color)
    draw.ellipse((x-9, y-9, x+9, y+9), fill=(255, 220, 100, 255))


def make_artwork(style, layout):
    background = Image.new('RGB', (1181, 1748), COLORS[style])
    overlay = Image.new('RGBA', background.size, (0, 0, 0, 0))
    draw = ImageDraw.Draw(overlay)
    if style == 'minimal':
        return background, overlay
    for slot in layout['slots']:
        x, y, w, h = (slot[k] for k in ('x', 'y', 'width', 'height'))
        draw.rounded_rectangle((x-9, y-9, x+w+8, y+h+8), radius=18, outline=(255,255,255,255), width=8)
    if style == 'party':
        for i in range(52):
            x, y = (i*197+13) % 1181, (i*337+11) % 1748
            # Only decorate the paper margins and gaps, leaving photo faces unobstructed.
            if any(s['x']+12 < x < s['x']+s['width']-12 and s['y']+12 < y < s['y']+s['height']-12 for s in layout['slots']):
                continue
            color = [(156,85,230,255),(255,182,75,255),(255,124,161,255)][i%3]
            draw.polygon([(x,y-12),(x+10,y+9),(x-11,y+9)], fill=color)
    elif style == 'floral':
        for slot in layout['slots']:
            x,y,w,h = (slot[k] for k in ('x','y','width','height'))
            for px,py in [(x+4,y+4),(x+w-4,y+h-4)]:
                draw.ellipse((px-32,py-7,px+32,py+7), fill=(120,173,144,255))
                flower(draw,px,py,(203,170,239,255))
    elif style == 'cute':
        paper = ImageDraw.Draw(background)
        for x in range(0,1181,44):
            for y in range(0,1748,44):
                if (x//44+y//44)%2 == 0:
                    paper.rectangle((x,y,x+43,y+43), fill=(248,239,255))
        for slot in layout['slots']:
            x,y = slot['x']+17,slot['y']+15
            draw.polygon([(x-24,y-8),(x-23,y-37),(x-4,y-22),(x+15,y-35),(x+27,y-6)],fill='white',outline=(168,110,204,255))
            draw.ellipse((x-27,y-20,x+28,y+21),fill='white',outline=(168,110,204,255),width=3)
            for dx in [-10,12]:
                draw.ellipse((x+dx-2,y-3,x+dx+2,y+2),fill=(91,52,124,255))
            draw.ellipse((x-2,y+4,x+3,y+8),fill=(243,153,199,255))
    return background, overlay


def main():
    samples=ROOT/'public/assets/samples'
    samples.mkdir(parents=True,exist_ok=True)
    raw,_,data=validate_and_load_jpeg(samples/'source-portrait.jpg')
    normalized,_=normalize_orientation_and_srgb(raw,data,1)
    for preset in ('original','korean-soft','film-400','vintage-fade'):
        image=normalized
        if preset != 'original':
            image=Image.fromarray(apply_cube_lut_rgb(np.asarray(normalized),parse_cube_file(ROOT/'presets'/f'{preset}.cube')))
        image.save(samples/f'{preset}.jpg',quality=95,subsampling=0)
    for layout_file in sorted((ROOT/'layouts').glob('*.json')):
        layout = json.loads(layout_file.read_text())
        layout_preview = Image.new('RGB',(1181,1748),(244,236,253))
        d=ImageDraw.Draw(layout_preview)
        for slot in layout['slots']:
            x,y,w,h=(slot[k] for k in ('x','y','width','height'))
            d.rounded_rectangle((x,y,x+w-1,y+h-1),radius=18,fill=(193,158,231))
        layout_preview.thumbnail((400,600))
        layout_preview.save(ROOT/'layouts'/f"{layout['id']}-preview.jpg",quality=90)
        for style in COLORS:
            ident = 'vertical-three-v1' if style == 'minimal' and layout['id'] == 'postcard-three' else f"{style}-{layout['id']}"
            directory=ROOT/'templates/bundled'/ident
            directory.mkdir(parents=True,exist_ok=True)
            bg,overlay=make_artwork(style,layout)
            bg.save(directory/'background.png')
            overlay.save(directory/'overlay.png')
            # Use the same pipeline as final rendering to generate truthful previews.
            spec=dict(layout,id=ident,overlay_path=str(directory/'overlay.png'),background_path=str(directory/'background.png'))
            photos=[{'slot':i,'path':str(samples/'source-portrait.jpg')} for i in range(1,layout['capture_count']+1)]
            rendered=run_render_from_manifest({'template':spec,'photos':photos,'lut_id':'original', 'output_print_path':str(directory/'sample.jpg'),'output_preview_path':str(directory/'preview.jpg')})
            (directory/'sample.jpg').unlink()
            metadata=dict(id=ident,name=NAMES[style],style=style,version='1.0.0',layout_id=layout['id'],layout_version='1.0.0',overlay='overlay.png',background='background.png',preview='preview.jpg',license='Original project artwork; MIT')
            metadata['hashes']={name:hashlib.sha256((directory/name).read_bytes()).hexdigest() for name in ('overlay.png','background.png','preview.jpg')}
            (directory/'template.json').write_text(json.dumps(metadata,ensure_ascii=False,indent=4)+'\n')
    # A distinct fourth mock capture; original fixtures remain byte-identical.
    for version in range(1,5):
        image=render_synthetic_card(1200,600,4,version,(140,65,190))
        (ROOT/'tests/fixtures'/f'slot-4-v{version}.jpg').write_bytes(encode_jpeg_bytes(image,quality=95,subsampling=0,dpi=300,icc_bytes=get_srgb_icc_bytes()))


if __name__ == '__main__':
    main()
