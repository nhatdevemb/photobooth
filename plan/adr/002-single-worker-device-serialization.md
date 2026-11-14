# ADR-002: Single CLI Worker and Subprocess Image Pipeline

- **Status**: Accepted
- **Date**: 2026-11-09
- **Refs**: IMP-006, IMP-010, IMP-018

## Context

Physical camera operations (Canon R50 over USB/PTP via `gphoto2`) and 3D LUT rendering (1181 × 1748 px at 300 DPI) cannot safely run concurrently inside synchronous PHP-FPM/Apache HTTP requests. Browser reloads or network retries during synchronous capture/render would either hang the web server or cause USB device contention.

## Decision

1. HTTP API requests validate state, revision, CSRF, and `Idempotency-Key` inside a short SQLite transaction, enqueue a job, increment the session revision once, and return `202 Accepted` immediately.
2. A single PHP CLI worker (`custom/bin/worker.php`) guarded by an OS-level `flock` singleton lock claims queued jobs, updates heartbeats every 2 seconds, and serializes camera and render tasks.
3. Image processing and `.cube` 3D LUT trilinear interpolation run in an isolated Python subprocess (`custom/python/render_pipeline.py`) invoked via `proc_open` with `argv` (no shell interpolation) and a validated JSON manifest.
4. Process groups are tracked so timed-out tasks are terminated cleanly without system-wide `killall`.

## Consequences

- HTTP responses remain fast (≤ 0.5 s).
- Browser refreshes never trigger duplicate shutter releases or corrupt ongoing renders.
- SQLite transactions are never held open while waiting on hardware or Python rendering.

