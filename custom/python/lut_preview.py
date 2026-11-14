"""Validate an uploaded CUBE and render the same RGB grading used by print jobs."""
import json
import sys
from pathlib import Path

from cube_lut import LutParseError, parse_cube_file
from render_pipeline import (
    PipelineError, atomic_write_bytes, encode_jpeg_bytes, get_srgb_icc_bytes,
    grade_rgb_image, normalize_orientation_and_srgb, sha256_file, validate_and_load_jpeg,
)


def main():
    try:
        path, output, amount = Path(sys.argv[1]), Path(sys.argv[2]), int(sys.argv[3])
        lut = parse_cube_file(path)
        source = Path(__file__).resolve().parents[1] / 'public/assets/samples/source-portrait.jpg'
        image, _, raw = validate_and_load_jpeg(source)
        normalized, _ = normalize_orientation_and_srgb(image, raw, 1)
        graded = grade_rgb_image(normalized, lut, amount)
        graded.thumbnail((1000, 1000))
        atomic_write_bytes(output, encode_jpeg_bytes(graded, 95, 0, 300, get_srgb_icc_bytes()))
        print(json.dumps({'status':'ok', 'title':lut.title, 'size':lut.size, 'sha256':lut.sha256, 'preview_sha256':sha256_file(output)}))
    except (LutParseError, PipelineError, ValueError, OSError) as error:
        print(json.dumps({'status':'error', 'code':'INVALID_LUT', 'message':str(error)}))
        sys.exit(1)


if __name__ == '__main__':
    main()
