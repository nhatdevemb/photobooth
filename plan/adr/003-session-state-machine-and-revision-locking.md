# ADR-003: Session State Machine and Optimistic Revision Locking

- **Status**: Accepted
- **Date**: 2026-11-09
- **Refs**: IMP-006, IMP-009, IMP-012, IMP-019

## Context

In an operator-assisted booth, multiple tabs, rapid clicks, or network retries can cause conflicting mutations (e.g., retaking a slot while finalizing, or approving a stale preview after changing the LUT).

## Decision

1. Session lifecycle uses explicit states:
   `NEW` → `CAPTURING` → `CAPTURED` (with `accepted_slots` 1..3) → `RENDERING` → `REVIEW` → `FINALIZED` → `CLOSED` (or `CANCELLED`).
2. Every session mutation requires the client's known `revision` and an `Idempotency-Key`.
   - Replaying the same `(actor_id, operation, key)` with identical payload hash returns the stored response before checking the current revision.
   - Reusing the same key with a different payload returns `409 IDEMPOTENCY_CONFLICT`.
   - Stale revisions return `409 REVISION_CONFLICT`.
   - Concurrent content jobs return `409 SESSION_BUSY`.
3. Accepted jobs record `accepted_revision`; job completion commits results only if `active_job_id` and `accepted_revision` still match, and does not increment `revision` a second time.
4. Changing LUT immediately invalidates `current_render_id` so an operator can never finalize a preview from a previous LUT if the new render fails.
5. `reopen` transitions `FINALIZED` → `CAPTURED` (when no unresolved print exists), clearing `current_render_id` and `current_final_id` while preserving all historical finals and previously issued QR tokens.

## Consequences

- Multi-tab races and network retries are deterministic and safe.
- Original slot photos and finalized assets are strictly immutable.

