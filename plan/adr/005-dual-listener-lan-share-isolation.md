# ADR-005: Dual Listener Isolation and Offline HMAC Share Tokens

- **Status**: Accepted
- **Date**: 2026-11-09
- **Refs**: IMP-006, IMP-023, IMP-024

## Context

Guests connect to the local Wi-Fi/LAN to scan a QR code and download their finalized photo. Guests must never gain access to operator APIs (`/custom/api/*`), upstream Photobooth endpoints (`/api/*`, `/admin/*`), session listings, or unapproved original photos. Furthermore, the booth operates offline without WAN Internet.

## Decision

1. Enforce surface separation between the Operator listener (bound to loopback `127.0.0.1` by default) and the Guest LAN listener (bound to the LAN IP). The guest entrypoint only serves `/share/{token}`, `/share/{token}/download`, and static viewer assets; all other paths return `403`/`404`.
2. Build QR URLs exclusively from the validated `SHARE_BASE_URL` configuration (rejecting `localhost`, `127.0.0.0/8`, `::1`, and `0.0.0.0`), never from the HTTP `Host` header.
3. Generate deterministic token secrets using `HMAC-SHA256(SHARE_SIGNING_KEY, share_id)` so idempotent API replays can reconstruct the URL without storing plaintext tokens in `share_tokens` or `idempotency_records`. Database stores only `token_hash = SHA-256(token)`.
4. Render QR codes locally on the server (SVG/PNG) with zero external CDN fonts, scripts, or analytics, and set `Referrer-Policy: no-referrer` and `Cache-Control: no-store`.

## Consequences

- Complete isolation of operator controls from guest LAN clients.
- Offline-first QR generation and photo download with revocable, expiring tokens.

