# Photobooth Operator Edition — Planning Pack (Synced v1.1)

Planning baseline: 2026-11-08 (synced with `implementation-plan.md` v1.1 on 2026-11-09).

Files:
- [`windows-app.md`](windows-app.md) — Windows app plan (15/11/2026): Canon EOS R10 via digiCamControl, live view/capture/JPEG, offline installer; mock printing. Supersedes direct EDSDK integration for Windows; software prototype built and mock-tested; physical acceptance remains pending. See [`windows-test-report.md`](windows-test-report.md).
- `implementation-plan.md` — authoritative detailed implementation plan (M0–M4 simulated MVP + H1–H3 hardware gates).
- `requirements.md` — scope, decisions, MVP requirements, acceptance criteria.
- `architecture.md` — components, v4.5.1 integration map, APIs, session & print state machines, storage and risks.
- `roadmap.md` — phase delivery (M0–M4 & H1–H3), task backlog (`DEV-*` and `IMP-001..033`), hardware gates and Definition of Done.
- `test-cases.md` — 30 core test cases (`TC-01..TC-30`) + 18 mandatory integration/recovery cases (`IT-01..IT-18`).
- `adr/` — Architecture Decision Records (`ADR-001` through `ADR-005`).

Upstream baseline:
- Repository: https://github.com/PhotoboothProject/photobooth
- Tag: `v4.5.1`
- Commit SHA: `a8841409ed8d0db485191269d6c302505b67f64f`
- Docs: https://photoboothproject.github.io/ | https://photoboothproject.github.io/faq/
