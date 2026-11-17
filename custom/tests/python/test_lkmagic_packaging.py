"""Packing must include dependencies and preserve the current native archive."""
import importlib.util
import json
import struct
from pathlib import Path


def test_sync_includes_main_dependencies_and_preserves_current_archive(tmp_path):
    script = Path(__file__).resolve().parents[3] / "LK Magic-WIN/sync-asar.py"
    spec = importlib.util.spec_from_file_location("sync_lkmagic_asar", script)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)

    source = tmp_path / "source"
    (source / "out/main/lk-activation").mkdir(parents=True)
    (source / "out/main/index.js").write_text("require('./lk-activation/export-access.cjs')")
    (source / "out/main/lk-activation/export-access.cjs").write_text("module.exports = {}")
    header = {"files": {
        "later-change.txt": {"size": 4, "offset": "0"},
        "native.node": {"size": 123, "unpacked": True},
    }}
    encoded = json.dumps(header).encode()
    padded = encoded + b"\0" * ((-len(encoded)) % 4)
    inner = 4 + len(padded)
    archive = tmp_path / "app.asar"
    original = struct.pack("<4I", 4, inner + 4, inner, len(encoded)) + padded + b"kept"
    archive.write_bytes(original)
    # A stale backup must never become the source for a subsequent sync.
    (tmp_path / "app.asar.bak").write_bytes(b"stale backup")
    backup, count = module.sync_archive(source, archive)
    assert backup.read_bytes() == original
    assert count == 2
    updated = archive.read_bytes()
    _, size, _, length = struct.unpack("<4I", updated[:16])
    result = json.loads(updated[16:16 + length])
    assert result["files"]["native.node"] == header["files"]["native.node"]
    unchanged = result["files"]["later-change.txt"]
    assert updated[8 + size + int(unchanged["offset"]):8 + size + int(unchanged["offset"]) + unchanged["size"]] == b"kept"
    activation = result["files"]["out"]["files"]["main"]["files"]["lk-activation"]["files"]["export-access.cjs"]
    assert activation["size"] > 0
