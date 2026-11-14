# Photobooth Operator Edition — Roadmap & Backlog v1.1

Synced with `implementation-plan.md` v1.1 on 2026-11-09.

## Delivery Strategy

Deliver the complete simulated MVP (`M0`–`M4`, `IMP-001`..`IMP-033`) first using deterministic camera and printer simulators alongside the real SQLite state machine, real Python `.cube` LUT/collage pipeline, real auth/idempotency, and real LAN QR share flow. Hardware integration and physical acceptance (`H1`–`H3`) follow once physical equipment (Canon R50, Godox AD600BM, Canon SELPHY CP1500) is connected.

| Milestone | Scope | Dependencies | Estimate (dev days) | Exit Gate |
| --- | --- | --- | --- | --- |
| **M0** (`IMP-001..006`) | Baseline `v4.5.1`, skeleton, Docker/env, lockfiles, fixtures, CI, ADRs & doc sync | None | 2–3 | Clean checkout runs; upstream SHA matches; ADRs recorded |
| **M1** (`IMP-007..013`) | SQLite schema, Auth/CSRF/CLI, Session API, revision/idempotency, Worker, MockCameraAdapter | M0 | 4–6 | `TC-01..06`, `TC-16/17` (auth/path), `IT-01..03`, `IT-05`, `IT-15` pass |
| **M2** (`IMP-014..020`) | `.cube` LUT parser, EXIF/ICC→sRGB, presets, `vertical-three-v1` collage, finalize/reopen, benchmark | M1 | 4–6 | `TC-07..11`, `IT-04`, `IT-06` pass; deterministic output |
| **M3** (`IMP-021..026`) | PrintService, MockPrintAdapter, `UNKNOWN` resolve, operator/guest listener split, QR tokens, retention, backup/restore, health/logs | M1, M2 | 4–6 | `TC-12..19`, `TC-27..30` (mock), `IT-07..14`, `IT-16..18` pass |
| **M4** (`IMP-027..033`) | Vietnamese Operator UI, Playwright E2E, 30-session soak test, backup/restore rehearsal, docs & release report | M2, M3 | 5–8 | All non-HW P0 tests + 30-session soak (35 print receipts) pass |
| **H1–H3** | Real `gphoto2` + R50 + AD600BM, real CUPS + CP1500, Ubuntu kiosk/systemd & physical soak | Hardware + M4 | 7–12 | `HW-01..05` and `TC-20..24` verified on physical hardware |
| **TOTAL** | | | **26–41** | |

## Mapping Legacy `DEV-001..014` to `IMP-001..033`

- `DEV-001`, `DEV-002` → `M0` (`IMP-001`..`IMP-006`)
- `DEV-003`, `DEV-004`, `DEV-009` → `M1` (`IMP-007`..`IMP-013`)
- `DEV-005`, `DEV-006`, `DEV-007` → `M2` (`IMP-014`..`IMP-020`)
- `DEV-008`, `DEV-011`, `DEV-013` → `M3` (`IMP-021`..`IMP-026`)
- `DEV-010`, `DEV-014` → `M4` (`IMP-027`..`IMP-033`)
- `DEV-012` → `H1`–`H3`
