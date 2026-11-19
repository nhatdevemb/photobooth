CREATE TABLE event_settings (
    id TEXT PRIMARY KEY,
    configuration TEXT NOT NULL DEFAULT '{}',
    print_limit INTEGER CHECK (print_limit > 0),
    revision INTEGER NOT NULL DEFAULT 1,
    is_current INTEGER NOT NULL CHECK (is_current IN (0,1))
);
CREATE UNIQUE INDEX idx_current_event ON event_settings(is_current) WHERE is_current = 1;
INSERT INTO event_settings (id, is_current) VALUES ('default', 1);

CREATE TABLE event_brand_images (
    id TEXT PRIMARY KEY,
    data_uri TEXT NOT NULL
);

ALTER TABLE sessions ADD COLUMN event_id TEXT NOT NULL DEFAULT 'default';
ALTER TABLE sessions ADD COLUMN event_configuration TEXT NOT NULL DEFAULT '{}';

ALTER TABLE print_requests RENAME TO print_requests_old;
DROP INDEX idx_print_requests_session;
DROP INDEX idx_print_requests_status;

CREATE TABLE print_requests (
    id TEXT PRIMARY KEY,
    session_id TEXT NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
    final_asset_id TEXT NOT NULL REFERENCES final_assets(id),
    copies INTEGER NOT NULL DEFAULT 1 CHECK (copies BETWEEN 1 AND 10),
    adapter_mode TEXT NOT NULL DEFAULT 'legacy' CHECK (adapter_mode IN ('legacy', 'mock', 'hardware')),
    completed_copies INTEGER CHECK (completed_copies BETWEEN 0 AND copies),
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

INSERT INTO print_requests (
    id, session_id, final_asset_id, copies, action, status, adapter_job_id, error_code, error_message,
    resolved_by, resolution_outcome, resolution_note, resolved_at, created_by, created_at,
    submitted_at, updated_at, completed_at, next_poll_at, poll_attempts
) SELECT * FROM print_requests_old;
DROP TABLE print_requests_old;

-- Keep completed totals when retention removes a session and its print requests.
CREATE TABLE event_deleted_print_totals (
    event_id TEXT NOT NULL REFERENCES event_settings(id),
    adapter_mode TEXT NOT NULL,
    copies INTEGER NOT NULL,
    PRIMARY KEY (event_id, adapter_mode)
);

CREATE TRIGGER archive_session_print_totals BEFORE DELETE ON sessions
BEGIN
    INSERT INTO event_deleted_print_totals (event_id, adapter_mode, copies)
    SELECT OLD.event_id, adapter_mode, SUM(COALESCE(completed_copies, copies))
    FROM print_requests WHERE session_id = OLD.id AND status = 'COMPLETED'
    GROUP BY adapter_mode
    ON CONFLICT(event_id, adapter_mode) DO UPDATE SET copies = copies + excluded.copies;
END;

CREATE INDEX idx_sessions_event ON sessions(event_id);
