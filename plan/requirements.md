# Photobooth Operator Edition — Requirements v1.1

Date: 2026-11-09 (synced with `implementation-plan.md` v1.1). Status: baseline approved from user decisions; MVP delivered with simulated camera/printer first; hardware acceptance held for H1–H3.

## 1. Scope and decisions

- Platform target: Ubuntu 24.04 LTS, PhotoboothProject/photobooth `v4.5.1` fork pinned to commit `a8841409ed8d0db485191269d6c302505b67f64f`.
- First delivery milestone: Full simulated MVP (`M0`–`M4`) using `MockCameraAdapter` and `MockPrintAdapter`, with real SQLite state machine, real Python Pillow/NumPy `.cube` LUT + collage pipeline, real authentication/CSRF/idempotency, real QR token & LAN download viewer. Simulated passes are never marked as hardware acceptance passes.
- Operator-controlled workflow (UI in Vietnamese, code/API identifiers in English): operator logs in, checks health, starts session, captures 3 slots (3s countdown), retakes any slot (1, 2, or 3) independently, chooses LUT filter, reviews finalized 3-photo vertical collage, manually confirms printing (1 copy per request in MVP), displays LAN QR, closes session, and browses session history.
- Layout: template `vertical-three-v1` on SELPHY CP1500 postcard media (100 × 148 mm vertical, 1181 × 1748 px at 300 DPI, 35 px border, 24 px vertical gap, slots `1111×543`, `1111×543`, `1111×544`).
- Filter choices: `original`, `korean-soft` (Korean Soft), `film-400` (Film 400), `vintage-fade` (Vintage Fade), applied to captured high-resolution JPEG via 33×33×33 `.cube` 3D LUT trilinear interpolation in NumPy before cropping into the collage.
- QR: local Wi-Fi/LAN only (`SHARE_BASE_URL`, rejecting `localhost`/`127.0.0.0/8`/`::1`/`0.0.0.0`), HMAC-SHA256 signed tokens with 24h TTL (capped by 7-day session retention).
- Hardware planned for H1–H3 validation: Canon EOS R50, Godox AD600BM, trigger/adapter, Canon SELPHY CP1500, Ubuntu laptop/PC.

## 2. Functional requirements

| ID | Priority | Requirement | Acceptance |
| --- | --- | --- | --- |
| FR-01 | P0 | Live preview | Preview available in operator UI (simulated frame stream in M0–M4, real liveview in H1); failure surfaced without freezing controls. |
| FR-02 | P0 | Session creation | Generate UUID session id; enforce single active session; persist state, revision=1, and 3 empty slots. |
| FR-03 | P0 | Capture 3 photos | Save immutable JPEG originals in slots 1..3 after 3s countdown, validating JPEG integrity and SHA-256 before updating slot pointer. |
| FR-04 | P0 | Individual retake | Retake chosen slot only; other two slot checksums unchanged; prior versions retained on disk and in `photos` table; failed retake preserves existing slot photo and valid render. |
| FR-05 | P0 | Three-photo collage | Correct source order (1, 2, 3), `vertical-three-v1` template (1181×1748 px, 300 DPI, JPEG Q95, subsampling 0) plus derived `preview.jpg` (max edge 1000 px). |
| FR-06 | P0 | LUT filters | Original + three `.cube` presets (size 33, domain 0..1), non-destructive processing; changing LUT immediately invalidates stale render. |
| FR-07 | P0 | Final review | Operator reviews rendered output and finalizes; finalized JPEG is copied byte-for-byte from `print.jpg` (matching SHA-256) without re-encoding. Supports `reopen` before printing. |
| FR-08 | P0 | Manual print | Authorized operator confirms in dialog; independent `print_requests` state machine (`PENDING → SUBMITTING → QUEUED → PRINTING → COMPLETED / FAILED / UNKNOWN`); 1 copy per request in MVP. |
| FR-09 | P0 | Reprint | Explicit `action=reprint` on any finalized asset without recapture/re-LUT; warns if an `UNKNOWN` print request exists; admin can resolve `UNKNOWN` (`observed_completed` / `confirmed_not_printed`). |
| FR-10 | P0 | Local QR | QR generated offline points to configured LAN `SHARE_BASE_URL` (`/share/{token}`) and permits controlled download of the single finalized JPEG (`EXIF/GPS` stripped, `sRGB` & DPI retained). |
| FR-11 | P0 | Session history | Cursor-paginated session list; view slot originals, finalized assets, print history, and reissue QR or reprint. |
| FR-12 | P0 | Device health | Mode (`mock`/`hardware`), DB/migrations, worker heartbeat, camera, preview, printer queue, and disk free space visible; actionable Vietnamese alerts. |
| FR-13 | P0 | Restart safety | Pending/orphan file reconciliation on startup; no corrupted finals or automatic duplicate prints after app/worker restart. |
| FR-14 | P1 | Print copy count | MVP enforces `copies=1` (`422 INVALID_COPIES` otherwise); multi-copy UI deferred post-MVP. |
| FR-15 | P1 | Config & maintenance | CLI user management, hourly retention cleanup (7 days after close/cancel), two-phase delete, SQLite + asset backup/restore, and JSON log rotation (14 days / 200 MiB). |

## 3. Nonfunctional requirements and targets

- Offline first: capture, filter, collage, print, and LAN QR viewer work with zero WAN/CDN requests.
- Security: authenticated operator/admin access (`password_hash`, HttpOnly SameSite=Lax cookies, 8h idle timeout, login rate limit 5 failed attempts / 15 min per username+IP); `X-CSRF-Token` + `Origin` check on mutations; `Idempotency-Key` on all business POSTs; strict operator vs guest listener isolation.
- Reliability: single CLI worker with OS `flock` and SQLite job claiming; atomic file writes (`fsync` + `rename`); disk low guard (< 2 GiB free blocks new capture/render/print).
- Performance targets: UI reaction ≤ 0.5 s, API enqueue ≤ 0.5 s, 3-image collage + LUT render ≤ 10 s.
- Soak testing: 30 sessions × 3 shots (with retakes on ≥ 10 sessions, 30 initial prints + 5 reprints = 35 receipts), 0 missing/corrupt finals, 0 unintended duplicate prints.
