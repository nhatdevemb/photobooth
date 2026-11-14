# Photobooth Operator Edition — Architecture v1.1

Synced with `implementation-plan.md` v1.1 on 2026-11-09.

## 1. Principles

1. Pin `PhotoboothProject/photobooth` at tag `v4.5.1` (`a8841409ed8d0db485191269d6c302505b67f64f`). Keep upstream demo isolated under a separate profile; never expose upstream endpoints (`/api/*`, `/admin/*`) on the guest LAN listener.
2. Build the Operator extension inside `custom/` with its own SQLite database (WAL mode, `foreign_keys=ON`, `synchronous=FULL`, `busy_timeout=5000`), immutable Asset Store outside the webroot, and single CLI worker.
3. Never call upstream PHP endpoints over internal HTTP or `include` them as libraries. Never send finalized JPEGs through upstream `api/print.php`.
4. Original photos are immutable (`originals/slot-{1..3}/v{N}.jpg`); retakes create new versions. Finals (`finalized/{id}.jpg`) are byte-identical copies of approved `print.jpg` renders.
5. Session state and print request state are decoupled so sessions can be closed while prints are tracked, and ambiguous print submissions enter `UNKNOWN` without automatic retries.

## 2. Component Diagram

```
Operator Browser (Vietnamese UI)
              |
       Operator API PHP (/custom/api/*)
              |
       Session Service
          /        \
      SQLite      Asset Store (outside webroot)
         |
      Job Queue
         |
    Worker PHP CLI (single instance via flock)
      |        |         |
    Camera   Renderer   Printer
    Adapter  Python     Adapter
      |                   |
    Mock / gphoto2      Mock / CUPS

Guest Phone -> Share Gateway (/share/{token}) -> Locked Final JPEG
```

## 3. Upstream v4.5.1 Reference Map & Custom Extension Layout

### Upstream v4.5.1 reference files (do not use dev-branch `src/Collage.php` or `api/capture.php`):
- Capture reference: `api/takePic.php`
- Preview reference: `api/previewCamera.php` and `api/cameracontrol.py`
- Collage reference: `lib/collage.php` and `lib/collageConfig.php`
- Effects reference: `api/applyEffects.php`, `lib/applyEffects.php` and `lib/filter.php`
- Upstream print reference: `api/print.php`
- Device defaults reference: `lib/config.php`

### Custom extension layout (`custom/`):
- `custom/public/` — Operator front controller, API router (`/custom/api/*`), Guest share gateway (`/share/*`), and static UI assets.
- `custom/src/Auth/` — Session auth, CSRF, Origin check, login rate limiting, role authorization (`operator`, `admin`).
- `custom/src/Session/` — State machine, revision locking, idempotency service, slot management, finalize/reopen/close/cancel.
- `custom/src/Storage/` — SQLite connection/migration manager, `AssetStore` with atomic `fsync`+`rename` publishing and startup orphan reconciliation.
- `custom/src/Jobs/` — Job queue repository, worker claim/heartbeat, process group timeout supervision.
- `custom/src/Camera/` — `CameraAdapterInterface`, `MockCameraAdapter` (deterministic fixtures + fault injection), `Gphoto2CameraAdapter` (H1 target).
- `custom/src/Image/` — `Renderer` invoking `custom/python/render_pipeline.py` via `argv` and JSON manifest.
- `custom/src/Printer/` — `PrintAdapterInterface`, `MockPrintAdapter` (persistent atomic receipts + fault injection), `CupsPrintAdapter` (H2 target), `PrintService`.
- `custom/src/Share/` — HMAC-SHA256 token generation, local QR SVG/PNG generator, guest viewer/download controller.
- `custom/src/Maintenance/` — Health check service, structured JSON logger with rotation, retention/two-phase deletion, backup/restore.
- `custom/bin/` — CLI tools (`worker.php`, `migrate.php`, `manage-user.php`, `maintenance.php`, `backup.php`, `restore.php`, `benchmark-render.php`, `soak-test.php`).
- `custom/python/` — `cube_lut.py` (33³ `.cube` parser & vectorized trilinear interpolation) and `render_pipeline.py` (EXIF transpose, ICC→sRGB, LUT, crop, `vertical-three-v1` collage, `print.jpg` + `preview.jpg`).
- `custom/presets/` — Versioned `.cube` LUT files, metadata manifest, and contact sheets.
- `custom/templates/` — `vertical-three-v1.json`.
- `custom/migrations/` — Numbered SQLite migrations (`001_initial_schema.sql`).
- `custom/tests/` — PHPUnit, pytest, and Playwright test suites.
- `custom/deploy/` — Docker Compose, Apache configs (operator & guest listeners), systemd service units.

## 4. State Machines

### 4.1. Session State Machine
`NEW` → `CAPTURING` → `CAPTURED` (slots 1..3) → `RENDERING` → `REVIEW` → `FINALIZED` → `CLOSED` (or `CANCELLED` from `NEW`/`CAPTURED`/`REVIEW`).
- `reopen` transitions `FINALIZED` → `CAPTURED` when no unresolved print request exists.

### 4.2. Job & Print Request State Machines
- Content jobs (`capture`, `retake`, `render`, `delete_session`): `QUEUED` → `RUNNING` → `SUCCEEDED` | `FAILED`.
- Print requests: `PENDING` → `SUBMITTING` → `QUEUED` → `PRINTING` → `COMPLETED` | `FAILED` | `UNKNOWN`.

## 5. Runtime Asset Layout (`DATA_DIR`)

```
{DATA_DIR}/
  db/photobooth.sqlite
  sessions/{session_id}/
    originals/slot-1/v1.jpg
    originals/slot-2/v1.jpg
    originals/slot-2/v2.jpg
    originals/slot-3/v1.jpg
    rendered/{render_id}/print.jpg
    rendered/{render_id}/preview.jpg
    finalized/{final_asset_id}.jpg
    pending/{job_id}/...
  prints/receipts/{print_request_id}.json
  locks/worker.lock
  logs/app.jsonl
```
