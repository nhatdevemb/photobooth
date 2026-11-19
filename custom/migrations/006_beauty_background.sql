CREATE TABLE beauty_diagnostic (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    status TEXT NOT NULL CHECK (status IN ('queued', 'running', 'passed', 'failed')),
    requested_at TEXT NOT NULL,
    completed_at TEXT,
    duration_ms INTEGER,
    error TEXT
);
