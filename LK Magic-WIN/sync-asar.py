#!/usr/bin/env python3
"""
Safely sync modified/new files from `LK Magic-WIN/app.asar/out/main` into `LK Magic-WIN/resources/app.asar`
while preserving all existing unpacked native module flags and structure.
"""
import hashlib
import json
import os
import shutil
import struct
from pathlib import Path

ROOT = Path(__file__).resolve().parent
SRC_DIR = ROOT / "app.asar"
ASAR_PATH = ROOT / "resources" / "app.asar"
BAK_PATH = ROOT / "resources" / "app.asar.bak"


def calc_integrity(data: bytes) -> dict:
    block_size = 4 * 1024 * 1024
    blocks = []
    for i in range(0, len(data), block_size):
        blocks.append(hashlib.sha256(data[i : i + block_size]).hexdigest())
    if not blocks:
        blocks.append(hashlib.sha256(b"").hexdigest())
    return {
        "algorithm": "SHA256",
        "hash": hashlib.sha256(data).hexdigest(),
        "blockSize": block_size,
        "blocks": blocks,
    }


def main() -> None:
    if not BAK_PATH.exists():
        shutil.copy2(ASAR_PATH, BAK_PATH)
        print(f"Created backup: {BAK_PATH}")

    raw = BAK_PATH.read_bytes()
    _, header_size, _, json_len = struct.unpack("<4I", raw[:16])
    data_offset = 8 + header_size
    header_json = raw[16 : 16 + json_len].decode("utf-8")
    header = json.loads(header_json)
    payload = bytearray(raw[data_offset:])

    files_to_sync = [
        ("out", "main", "index.js"),
        ("out", "main", "lk-photobooth-bridge", "main.cjs"),
    ]

    for rel_parts in files_to_sync:
        src_file = SRC_DIR.joinpath(*rel_parts)
        content = src_file.read_bytes()
        offset = len(payload)
        payload.extend(content)

        node = header
        for part in rel_parts[:-1]:
            if "files" not in node:
                node["files"] = {}
            if part not in node["files"]:
                node["files"][part] = {"files": {}}
            node = node["files"][part]

        leaf_name = rel_parts[-1]
        node.setdefault("files", {})[leaf_name] = {
            "size": len(content),
            "offset": str(offset),
            "integrity": calc_integrity(content),
        }
        print(f"Synced {'/'.join(rel_parts)} ({len(content)} bytes at offset {offset})")

    new_json_bytes = json.dumps(header, separators=(",", ":")).encode("utf-8")
    json_len_new = len(new_json_bytes)
    # Pad to 4-byte boundary
    pad_len = (4 - (json_len_new % 4)) % 4
    padded_json = new_json_bytes + (b"\x00" * pad_len)
    inner_size = 4 + len(padded_json)
    outer_size = 4 + inner_size
    new_prefix = struct.pack("<4I", 4, outer_size, inner_size, json_len_new) + padded_json

    tmp_asar = ASAR_PATH.with_suffix(".asar.tmp")
    with open(tmp_asar, "wb") as f:
        f.write(new_prefix)
        f.write(payload)
    os.replace(tmp_asar, ASAR_PATH)
    print(f"Successfully updated {ASAR_PATH} ({ASAR_PATH.stat().st_size} bytes)")


if __name__ == "__main__":
    main()
