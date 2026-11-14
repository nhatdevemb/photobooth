# ADR-001: Pinning Upstream Photobooth v4.5.1 and Adapter Boundaries

- **Status**: Accepted
- **Date**: 2026-11-09
- **Refs**: IMP-001, IMP-006

## Context

The project builds upon [PhotoboothProject/photobooth](https://github.com/PhotoboothProject/photobooth). Upstream `dev` branches refactored files into `src/Collage.php` and `src/Enum/ImageFilterEnum.php`, whereas the stable `v4.5.1` release (`a8841409ed8d0db485191269d6c302505b67f64f`) uses `api/takePic.php`, `api/previewCamera.php`, `api/cameracontrol.py`, `lib/collage.php`, `lib/applyEffects.php`, `api/print.php`, and `lib/config.php`.

Upstream PHP endpoints read superglobals (`$_POST`, `$_GET`), write directly to shared directories, and call `die()`/`exit()`. Notably, `api/print.php` can rotate, crop, overlay frames/text/QR, or alter the image before printing.

## Decision

1. Pin upstream to tag `v4.5.1` (`a8841409ed8d0db485191269d6c302505b67f64f`) and record baseline metadata and compatibility patches in `custom/upstream.json`.
2. Build all operator workflows inside `custom/` with its own SQLite metadata store, immutable asset store, and job queue.
3. Never chain business operations by making internal HTTP calls to upstream endpoints or `include`-ing upstream `api/*.php` scripts inside the operator backend.
4. Never route finalized printable JPEGs through upstream `api/print.php`; use our dedicated `PrintAdapter` (`MockPrintAdapter` in MVP simulation, `CupsPrintAdapter` in H2) so the exact finalized bytes are submitted unaltered.

## Consequences

- Upstream demo remains intact for reference and device comparison under a separate profile.
- Operator session invariants, immutable originals/finals, and print idempotency are strictly enforced by `custom/`.

