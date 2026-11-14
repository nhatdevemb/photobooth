-- Migration 001: Initial schema for Photobooth Operator Edition

CREATE TABLE IF NOT EXISTS users (
    id TEXT PRIMARY KEY,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL CHECK (role IN ('operator', 'admin')),
    disabled_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS auth_sessions (
    id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    csrf_token TEXT NOT NULL,
    ip_address TEXT NOT NULL,
    user_agent TEXT NOT NULL,
    created_at TEXT NOT NULL,
    last_activity_at TEXT NOT NULL,
    expires_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_auth_sessions_user ON auth_sessions(user_id);
CREATE INDEX IF NOT EXISTS idx_auth_sessions_expires ON auth_sessions(expires_at);

CREATE TABLE IF NOT EXISTS login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL,
    ip_address TEXT NOT NULL,
    attempted_at INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_login_attempts_lookup ON login_attempts(username, ip_address, attempted_at);

CREATE TABLE IF NOT EXISTS sessions (
    id TEXT PRIMARY KEY,
    state TEXT NOT NULL CHECK (state IN ('NEW', 'CAPTURING', 'CAPTURED', 'RENDERING', 'REVIEW', 'FINALIZED', 'CLOSED', 'CANCELLED')),
    pre_job_state TEXT NULL,
    revision INTEGER NOT NULL DEFAULT 1 CHECK (revision >= 1),
    selected_lut TEXT NOT NULL DEFAULT 'original',
    template_id TEXT NOT NULL DEFAULT 'vertical-three-v1',
    current_render_id TEXT NULL,
    current_final_id TEXT NULL,
    active_job_id TEXT NULL,
    created_by TEXT NOT NULL REFERENCES users(id),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    closed_at TEXT NULL,
    expires_at TEXT NULL,
    deleted_at TEXT NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_single_active_session
    ON sessions ((1))
    WHERE state NOT IN ('CLOSED', 'CANCELLED') AND deleted_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_sessions_created_at ON sessions(created_at DESC, id DESC);
CREATE INDEX IF NOT EXISTS idx_sessions_expires_at ON sessions(expires_at);

CREATE TABLE IF NOT EXISTS photos (
    id TEXT PRIMARY KEY,
    session_id TEXT NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
    slot INTEGER NOT NULL CHECK (slot IN (1, 2, 3)),
    version INTEGER NOT NULL CHECK (version >= 1),
    path TEXT NOT NULL,
    sha256 TEXT NOT NULL,
    width INTEGER NOT NULL,
    height INTEGER NOT NULL,
    bytes INTEGER NOT NULL,
    exif_orientation INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    UNIQUE (session_id, slot, version)
);

CREATE INDEX IF NOT EXISTS idx_photos_session_slot ON photos(session_id, slot, version DESC);

CREATE TABLE IF NOT EXISTS session_slots (
    session_id TEXT NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
    slot INTEGER NOT NULL CHECK (slot IN (1, 2, 3)),
    current_photo_id TEXT NULL REFERENCES photos(id),
    updated_at TEXT NOT NULL,
    PRIMARY KEY (session_id, slot)
);

CREATE TABLE IF NOT EXISTS jobs (
    id TEXT PRIMARY KEY,
    session_id TEXT NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
    type TEXT NOT NULL CHECK (type IN ('capture', 'retake', 'render', 'delete_session')),
    status TEXT NOT NULL CHECK (status IN ('QUEUED', 'RUNNING', 'SUCCEEDED', 'FAILED')),
    phase TEXT NOT NULL DEFAULT 'queued',
    progress INTEGER NOT NULL DEFAULT 0,
    accepted_revision INTEGER NOT NULL,
    attempt INTEGER NOT NULL DEFAULT 1,
    max_attempts INTEGER NOT NULL DEFAULT 1,
    payload TEXT NOT NULL,
    result TEXT NULL,
    error TEXT NULL,
    worker_id TEXT NULL,
    worker_pid INTEGER NULL,
    heartbeat_at TEXT NULL,
    created_at TEXT NOT NULL,
    started_at TEXT NULL,
    completed_at TEXT NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_single_active_session_job
    ON jobs (session_id)
    WHERE status IN ('QUEUED', 'RUNNING') AND type IN ('capture', 'retake', 'render');

CREATE INDEX IF NOT EXISTS idx_jobs_status_created ON jobs(status, created_at ASC);

CREATE TABLE IF NOT EXISTS renders (
    id TEXT PRIMARY KEY,
    session_id TEXT NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
    job_id TEXT NOT NULL REFERENCES jobs(id),
    lut_id TEXT NOT NULL,
    lut_version TEXT NOT NULL,
    template_id TEXT NOT NULL,
    template_version TEXT NOT NULL,
    pipeline_version TEXT NOT NULL,
    source_photo_ids TEXT NOT NULL,
    input_signature TEXT NOT NULL,
    image_path TEXT NOT NULL,
    preview_path TEXT NOT NULL,
    sha256 TEXT NOT NULL,
    preview_sha256 TEXT NOT NULL,
    width INTEGER NOT NULL,
    height INTEGER NOT NULL,
    duration_ms INTEGER NOT NULL DEFAULT 0,
    valid INTEGER NOT NULL DEFAULT 1 CHECK (valid IN (0, 1)),
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_renders_session ON renders(session_id, created_at DESC);

CREATE TABLE IF NOT EXISTS final_assets (
    id TEXT PRIMARY KEY,
    session_id TEXT NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
    render_id TEXT NOT NULL REFERENCES renders(id),
    path TEXT NOT NULL,
    sha256 TEXT NOT NULL,
    width INTEGER NOT NULL,
    height INTEGER NOT NULL,
    bytes INTEGER NOT NULL,
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_final_assets_session ON final_assets(session_id, created_at DESC);

CREATE TABLE IF NOT EXISTS print_requests (
    id TEXT PRIMARY KEY,
    session_id TEXT NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
    final_asset_id TEXT NOT NULL REFERENCES final_assets(id),
    copies INTEGER NOT NULL DEFAULT 1 CHECK (copies = 1),
    action TEXT NOT NULL CHECK (action IN ('print', 'reprint')),
    status TEXT NOT NULL CHECK (status IN ('PENDING', 'SUBMITTING', 'QUEUED', 'PRINTING', 'COMPLETED', 'FAILED', 'UNKNOWN')),
    adapter_job_id TEXT NULL,
    error_code TEXT NULL,
    error_message TEXT NULL,
    resolved_by TEXT NULL REFERENCES users(id),
    resolution_outcome TEXT NULL CHECK (resolution_outcome IN ('observed_completed', 'confirmed_not_printed')),
    resolution_note TEXT NULL,
    resolved_at TEXT NULL,
    created_by TEXT NOT NULL REFERENCES users(id),
    created_at TEXT NOT NULL,
    submitted_at TEXT NULL,
    updated_at TEXT NOT NULL,
    completed_at TEXT NULL,
    next_poll_at TEXT NULL,
    poll_attempts INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX IF NOT EXISTS idx_print_requests_session ON print_requests(session_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_print_requests_status ON print_requests(status, next_poll_at ASC);

CREATE TABLE IF NOT EXISTS share_tokens (
    id TEXT PRIMARY KEY,
    session_id TEXT NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
    final_asset_id TEXT NOT NULL REFERENCES final_assets(id),
    token_hash TEXT NOT NULL UNIQUE,
    created_by TEXT NOT NULL REFERENCES users(id),
    created_at TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    revoked_at TEXT NULL
);

CREATE INDEX IF NOT EXISTS idx_share_tokens_session ON share_tokens(session_id);
CREATE INDEX IF NOT EXISTS idx_share_tokens_final ON share_tokens(final_asset_id);

CREATE TABLE IF NOT EXISTS idempotency_records (
    actor_id TEXT NOT NULL,
    operation TEXT NOT NULL,
    idempotency_key TEXT NOT NULL,
    session_id TEXT NULL REFERENCES sessions(id) ON DELETE CASCADE,
    payload_hash TEXT NOT NULL,
    resource_id TEXT NOT NULL,
    status_code INTEGER NOT NULL,
    response_json TEXT NOT NULL,
    created_at TEXT NOT NULL,
    PRIMARY KEY (actor_id, operation, idempotency_key)
);

CREATE INDEX IF NOT EXISTS idx_idempotency_session ON idempotency_records(session_id);

CREATE TABLE IF NOT EXISTS audit_events (
    id TEXT PRIMARY KEY,
    actor_id TEXT NULL,
    session_id TEXT NULL,
    job_id TEXT NULL,
    action TEXT NOT NULL,
    outcome TEXT NOT NULL,
    safe_metadata TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_audit_events_session ON audit_events(session_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_audit_events_created ON audit_events(created_at DESC);

CREATE TABLE IF NOT EXISTS worker_state (
    worker_id TEXT PRIMARY KEY,
    pid INTEGER NOT NULL,
    hostname TEXT NOT NULL,
    mode TEXT NOT NULL,
    started_at TEXT NOT NULL,
    heartbeat_at TEXT NOT NULL,
    active_job_id TEXT NULL,
    status TEXT NOT NULL
);

