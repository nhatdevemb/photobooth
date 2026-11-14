# Photobooth Operator Edition — Test Cases v1.1

Synced with `implementation-plan.md` v1.1 on 2026-11-09.
Legend: `AUTO` = automated test (mockable devices); `HW` = requires physical Canon R50 / Godox AD600BM / SELPHY CP1500 (must NOT be marked PASS using mocks); `E2E` = full-stack workflow.

## 1. Core Test Cases (`TC-01` … `TC-30`)

| ID | Type | Priority | Milestone | Steps | Expected result |
| --- | --- | --- | --- | --- | --- |
| TC-01 | AUTO | P0 | M1 | Create session | Unique UUID, state `NEW`, revision 1, 3 empty slots. |
| TC-02 | AUTO | P0 | M1 | Capture sequential slots 1, 2, 3 | 3 distinct immutable JPEG files & hashes, state `CAPTURED` (`accepted_slots=3`). |
| TC-03 | AUTO | P0 | M1 | Try capturing a 4th slot (`slot=4` or `slot=0`) | `422 INVALID_SLOT`, no job queued, no file written. |
| TC-04 | AUTO | P0 | M1 | Retake slot 2 | Only slot 2 current photo changes (`v2`); slot 1 & 3 hashes unchanged. |
| TC-05 | AUTO | P0 | M1 | Retake slot 2 twice | Current version `v3`, prior versions `v1`/`v2` retained on disk and DB; no orphan file. |
| TC-06 | AUTO | P0 | M1 | Render with empty slot | Rejected with `422 MISSING_PHOTOS` (`INVALID_STATE` if not ready). |
| TC-07 | AUTO | P0 | M2 | Apply `original` LUT | Pixels/color values match source within JPEG codec tolerance. |
| TC-08 | AUTO | P0 | M2 | Apply each `.cube` preset (`korean-soft`, `film-400`, `vintage-fade`) | Three visibly distinct valid `1181×1748` images; original slot files unchanged. |
| TC-09 | AUTO | P0 | M2 | Render same originals/config twice | Identical `1181×1748` dimensions, 300 DPI, deterministic SHA-256 hash. |
| TC-10 | AUTO | P0 | M2 | Retake or change LUT after render | Previous render invalidated; old preview cannot be finalized. |
| TC-11 | AUTO | P0 | M2 | Finalize output | Asset immutable in `finalized/`, byte-identical SHA-256 to `print.jpg`, state `FINALIZED`. |
| TC-12 | AUTO | P0 | M3 | Print without finalization | Rejected (`422 INVALID_STATE` or asset not finalized). |
| TC-13 | AUTO | P0 | M3 | Double-click Print with same `Idempotency-Key` | Only one `print_request` and one adapter receipt created. |
| TC-14 | AUTO | P0 | M3 | Explicit Reprint (`action=reprint`) on finalized asset | New `print_request` points to same `final_asset_id` and SHA-256 hash. |
| TC-15 | AUTO | P0 | M3 | Simulate adapter timeout/crash after `SUBMITTING` | Outcome marked `UNKNOWN`; never automatically resubmitted. |
| TC-16 | AUTO | P0 | M1/M3 | Access operator API without login or without CSRF token | `401 AUTH_REQUIRED` / `403 CSRF_INVALID`; no mutation executed. |
| TC-17 | AUTO | P0 | M1/M3 | Tamper with asset ID, filename, or path traversal | Traversal denied (`400`/`403`/`404`); only authorized assets served. |
| TC-18 | AUTO | P0 | M3 | Expire or revoke QR share token | `/share/{token}` and `/share/{token}/download` return public error; admin still sees session. |
| TC-19 | E2E | P0 | M3/M4 (repeat H3) | Access QR URL from guest listener | Correct finalized JPEG downloads with zero WAN/CDN requests; QR host is LAN IP/hostname, never `localhost`. |
| TC-20 | HW | P0 | H1 | Run R50 capture via `gphoto2` | Correct JPEG resolution and EXIF orientation; no USB hang (`PENDING HARDWARE`). |
| TC-21 | HW | P0 | H1 | Preview 15 min then 10 shots | Preview resumes after captures; no persistent Device Busy (`PENDING HARDWARE`). |
| TC-22 | HW | P0 | H1 | Shoot 30 frames with AD600BM | Sync fires each time under tested exposure settings (`PENDING HARDWARE`). |
| TC-23 | HW | P0 | H2 | Print exact finalized file via CUPS on CP1500 | Correct photo, order, size, and margins (`PENDING HARDWARE`). |
| TC-24 | HW | P0 | H2 | Print neutral calibration sheet on CP1500 | Color accepted; record driver, settings, and physical sample (`PENDING HARDWARE`). |
| TC-25 | E2E | P0 | M4 (repeat H3) | Process crash / restart after finalized session | Originals and final recover intact; no print job silently resubmitted. |
| TC-26 | E2E | P0 | M4 (repeat H3) | Soak: 30 sessions × 3 shots, retake on ≥10 sessions, 5 reprints | 30 valid finals, 0 missing slots, 35 print receipts, 0 duplicate unintended prints. |
| TC-27 | AUTO | P0 | M3/M4 | Simulate low disk (< 2 GiB free) | Capture/render/print blocked with `503 DISK_LOW`; viewing/closing still works. |
| TC-28 | AUTO | P1 | M3 | Request print with `copies != 1` | Rejected with `422 INVALID_COPIES`. |
| TC-29 | AUTO | P1 | M3 | Retention cleanup purges expired closed/cancelled sessions | Expired sessions removed; active sessions and sessions with unresolved prints protected. |
| TC-30 | AUTO/HW | P1 | M1/M3 (repeat H1/H2) | Camera or printer disconnect mid-session | Recoverable `503 DEVICE_UNAVAILABLE` error; session state and prior photos remain intact. |

## 2. Mandatory Integration & Recovery Cases (`IT-01` … `IT-18`)

| ID | Scenario | Expected Result |
| --- | --- | --- |
| IT-01 | Two tabs send retake with same `revision` | One succeeds (`202`), the other receives `409 REVISION_CONFLICT` or `409 SESSION_BUSY`. |
| IT-02 | Replay same `Idempotency-Key` after refresh/restart | Returns identical resource/response without creating extra capture or print. |
| IT-03 | Same `Idempotency-Key` with different slot/payload | Returns `409 IDEMPOTENCY_CONFLICT`, no job created. |
| IT-04 | Change LUT after previous render completed | Previous render invalidated; attempting to finalize old `render_id` fails (`422`). |
| IT-05 | Retake fails after 3 photos already captured | Preserves all 3 previous slot photos and leaves no broken references. |
| IT-06 | `reopen` and `finalize` a second time | Produces a second `final_asset` with a new ID; old QR token still serves the first final. |
| IT-07 | `close` session without printing | Session closes (`CLOSED`); QR token remains valid until its expiry/retention cap. |
| IT-08 | `close` session while print request is queued | Print continues tracking to completion; retention cleanup does not delete protected assets. |
| IT-09 | Worker crash while print is `SUBMITTING` | Reconciles via adapter evidence or marks `UNKNOWN`; never resubmits automatically. |
| IT-10 | Adapter loses job history after submission | Does not infer `COMPLETED` from job not found; marks `UNKNOWN`. |
| IT-11 | Final JPEG bytes modified/corrupted on disk | Print submission and guest download blocked with integrity error (`SHA-256` mismatch). |
| IT-12 | Guest accesses expired, revoked, or malformed token | Uniform public error page/JSON without leaking session ID or file path. |
| IT-13 | Guest listener requests `/custom/api/*`, `/api/*`, `/admin/*`, or private files | Denied (`403`/`404`) even if path is known. |
| IT-14 | Backup and restore to clean volume, then start worker | Asset hashes verified; historical prints are not re-submitted. |
| IT-15 | Two worker instances started simultaneously | Only one acquires `flock`; no job is executed twice concurrently. |
| IT-16 | Two-phase session delete interrupted mid-way | Resumes safely on next maintenance/worker pass; revoked tokens stay revoked. |
| IT-17 | Preview, UI, and guest viewer in offline LAN | Zero HTTP requests to external CDNs, fonts, or analytics. |
| IT-18 | Invalid hardware/mock adapter configuration | Startup/health fails explicitly; never silently falls back from `hardware` to `mock`. |
