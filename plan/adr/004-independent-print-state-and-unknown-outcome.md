# ADR-004: Decoupled Print State Machine and Handling UNKNOWN Outcomes

- **Status**: Accepted
- **Date**: 2026-11-09
- **Refs**: IMP-006, IMP-021, IMP-022

## Context

There is no distributed two-phase commit between SQLite and a physical printer / CUPS spooler. If the worker crashes or times out after entering the `SUBMITTING` phase, automatically retrying the print job risks printing duplicate physical sheets, while Embedding print states directly into the session state machine prevents closing a session or tracking multiple reprints independently.

## Decision

1. Decouple print tracking from `sessions.state` into the `print_requests` table (`PENDING` → `SUBMITTING` → `QUEUED` → `PRINTING` → `COMPLETED`, or terminal `FAILED` / `UNKNOWN`).
2. Before invoking the print adapter, the worker verifies the finalized asset's SHA-256 and flushes `SUBMITTING` to SQLite.
3. If a crash or timeout occurs after `SUBMITTING` begins and the adapter cannot provide deterministic evidence of whether the job was queued, the request transitions to `UNKNOWN` and is never auto-retried.
4. Operators are warned in the UI when an `UNKNOWN` print request exists; an admin must explicitly resolve `UNKNOWN` via `/custom/api/prints/{id}/resolve` with `observed_completed` or `confirmed_not_printed` and a note.
5. Sessions may be closed while a print request is still `QUEUED`/`PRINTING`, and retention cleanup is blocked for any session with an unresolved print request (`PENDING`, `SUBMITTING`, `QUEUED`, `PRINTING`, `UNKNOWN`).

## Consequences

- Zero silent duplicate prints on timeout or restart.
- Full audit trail of initial prints, explicit reprints, and operator/admin resolutions.

