#!/usr/bin/env python3
"""Sync the complete main-process source tree without reverting other ASAR files."""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import shutil
import struct
import uuid
from pathlib import Path

ROOT = Path(__file__).resolve().parent


def calc_integrity(data: bytes) -> dict:
    block_size = 4 * 1024 * 1024
    blocks = [hashlib.sha256(data[i:i + block_size]).hexdigest() for i in range(0, len(data), block_size)]
    return {
        "algorithm": "SHA256", "hash": hashlib.sha256(data).hexdigest(),
        "blockSize": block_size, "blocks": blocks or [hashlib.sha256(b"").hexdigest()],
    }


def sync_archive(source_dir: Path, asar_path: Path) -> tuple[Path, int]:
    # Read the CURRENT archive; a previous backup may lack later modules or fixes.
    raw = asar_path.read_bytes()
    _, header_size, _, json_len = struct.unpack("<4I", raw[:16])
    data_offset = 8 + header_size
    if json_len > header_size - 8 or data_offset > len(raw):
        raise ValueError("Invalid ASAR header")
    header = json.loads(raw[16:16 + json_len].decode("utf-8"))
    payload = bytearray(raw[data_offset:])
    files = sorted(file for file in (source_dir / "out/main").rglob("*") if file.is_file())
    if not files or not (source_dir / "out/main/index.js").is_file():
        raise ValueError("Source must contain out/main/index.js")

    for src in files:
        parts = src.relative_to(source_dir).parts
        node = header
        for part in parts[:-1]:
            node = node.setdefault("files", {}).setdefault(part, {"files": {}})
        leaves = node.setdefault("files", {})
        previous = leaves.get(parts[-1], {})
        if previous.get("unpacked") or previous.get("link"):
            raise ValueError(f"Refusing to repack an unpacked file or link: {src}")
        content = src.read_bytes()
        leaves[parts[-1]] = {"size": len(content), "offset": str(len(payload)), "integrity": calc_integrity(content)}
        payload.extend(content)

    encoded = json.dumps(header, separators=(",", ":")).encode("utf-8")
    padded = encoded + b"\0" * ((-len(encoded)) % 4)
    inner_size = 4 + len(padded)
    prefix = struct.pack("<4I", 4, 4 + inner_size, inner_size, len(encoded)) + padded
    # Keep a unique rollback copy of precisely the archive being replaced.
    backup = asar_path.with_name(asar_path.name + ".pre-sync-" + uuid.uuid4().hex + ".bak")
    shutil.copy2(asar_path, backup)
    temporary = asar_path.with_name(asar_path.name + "." + uuid.uuid4().hex + ".tmp")
    try:
        with temporary.open("xb") as output:
            output.write(prefix)
            output.write(payload)
        os.replace(temporary, asar_path)
    finally:
        temporary.unlink(missing_ok=True)
    return backup, len(files)


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", type=Path, default=ROOT / "app.asar")
    parser.add_argument("--archive", type=Path, default=ROOT / "resources/app.asar")
    args = parser.parse_args()
    backup, count = sync_archive(args.source.resolve(), args.archive.resolve())
    print(f"Synced {count} main-process files into {args.archive}")
    print(f"Rollback archive: {backup}")


if __name__ == "__main__":
    main()
