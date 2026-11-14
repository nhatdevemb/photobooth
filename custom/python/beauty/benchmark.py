#!/usr/bin/env python3
"""
Beauty Retouch Engine — Benchmark Script
Measures throughput, CPU time, and memory overhead across beauty presets.
"""

from __future__ import annotations

import argparse
import sys
import time
from pathlib import Path
from typing import List

# Ensure custom/python is on sys.path
PYTHON_DIR = Path(__file__).resolve().parent.parent
if str(PYTHON_DIR) not in sys.path:
    sys.path.insert(0, str(PYTHON_DIR))

import cv2
import numpy as np
from PIL import Image

from beauty import BeautyProcessor, resolve_beauty_config


def run_benchmark(image_paths: List[Path], iterations: int = 3) -> None:
    print(f"=== Beauty Retouch Engine Benchmark ===")
    print(f"Iterations per photo: {iterations}")
    print(f"Photos to test: {len(image_paths)}")

    processor = BeautyProcessor()
    presets = ["natural", "soft", "korean-glow"]

    for path in image_paths:
        if not path.is_file():
            print(f"Skipping missing file: {path}")
            continue

        with Image.open(path) as img:
            img = img.convert("RGB")
            w, h = img.size
            mp_count = (w * h) / 1_000_000.0

        print(f"\n--- Testing: {path.name} ({w}x{h}, {mp_count:.2f} MP) ---")

        for preset in presets:
            cfg = resolve_beauty_config(preset)
            durations: List[float] = []
            faces_detected = 0

            for i in range(iterations):
                with Image.open(path) as img:
                    img_rgb = np.asarray(img.convert("RGB"), dtype=np.uint8)
                    t0 = time.perf_counter()
                    _, stats = processor.process(img_rgb, cfg)
                    dt = (time.perf_counter() - t0) * 1000.0
                    durations.append(dt)
                    faces_detected = stats.get("faces_detected", 0)

            avg_ms = sum(durations) / len(durations)
            min_ms = min(durations)
            max_ms = max(durations)
            print(
                f"  [{preset:12s}] Faces: {faces_detected} | "
                f"Avg: {avg_ms:6.1f} ms | Min: {min_ms:6.1f} ms | Max: {max_ms:6.1f} ms"
            )

    processor.close()
    print("\nBenchmark completed successfully.")


def main() -> int:
    parser = argparse.ArgumentParser(description="Beauty Retouch Engine Benchmark")
    parser.add_argument("images", nargs="*", help="Paths to sample JPEG photos")
    parser.add_argument("--iterations", type=int, default=3, help="Runs per preset")
    args = parser.parse_args()

    image_paths: List[Path] = []
    if args.images:
        image_paths = [Path(p) for p in args.images]
    else:
        # Look for default test fixtures
        fixtures_dir = Path(__file__).resolve().parent.parent.parent / "tests" / "fixtures"
        for slot in range(1, 4):
            candidate = fixtures_dir / f"slot-{slot}-v1.jpg"
            if candidate.is_file():
                image_paths.append(candidate)

    if not image_paths:
        print("No test images found. Please specify image paths.")
        return 1

    run_benchmark(image_paths, iterations=args.iterations)
    return 0


if __name__ == "__main__":
    sys.exit(main())
