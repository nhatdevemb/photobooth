-- Preserve existing sessions and immutable assets while allowing one to four captures.
ALTER TABLE sessions ADD COLUMN layout_id TEXT NOT NULL DEFAULT 'postcard-three';
ALTER TABLE sessions ADD COLUMN layout_version TEXT NOT NULL DEFAULT '1.0.0';
ALTER TABLE sessions ADD COLUMN template_version TEXT NOT NULL DEFAULT '1.0.0';
ALTER TABLE sessions ADD COLUMN capture_count INTEGER NOT NULL DEFAULT 3 CHECK (capture_count BETWEEN 1 AND 4);
ALTER TABLE sessions ADD COLUMN configuration_locked INTEGER NOT NULL DEFAULT 0 CHECK (configuration_locked IN (0, 1));
ALTER TABLE sessions ADD COLUMN ui_step INTEGER NOT NULL DEFAULT 1 CHECK (ui_step BETWEEN 1 AND 5);
UPDATE sessions SET configuration_locked = 1, ui_step = CASE WHEN state IN ('RENDERING','REVIEW','FINALIZED','CLOSED') THEN 5 ELSE 4 END
 WHERE EXISTS (SELECT 1 FROM photos WHERE photos.session_id = sessions.id)
    OR EXISTS (SELECT 1 FROM jobs WHERE jobs.session_id = sessions.id AND jobs.type IN ('capture','retake'));

CREATE TABLE photos_new (
    id TEXT PRIMARY KEY,
    session_id TEXT NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
    slot INTEGER NOT NULL CHECK (slot BETWEEN 1 AND 4),
    version INTEGER NOT NULL CHECK (version >= 1),
    path TEXT NOT NULL, sha256 TEXT NOT NULL, width INTEGER NOT NULL, height INTEGER NOT NULL,
    bytes INTEGER NOT NULL, exif_orientation INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL,
    UNIQUE (session_id, slot, version)
);
INSERT INTO photos_new SELECT * FROM photos;
CREATE TABLE session_slots_new (
    session_id TEXT NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
    slot INTEGER NOT NULL CHECK (slot BETWEEN 1 AND 4),
    current_photo_id TEXT NULL REFERENCES photos_new(id),
    updated_at TEXT NOT NULL,
    PRIMARY KEY (session_id, slot)
);
INSERT INTO session_slots_new SELECT * FROM session_slots;
DROP TABLE session_slots;
DROP TABLE photos;
ALTER TABLE photos_new RENAME TO photos;
ALTER TABLE session_slots_new RENAME TO session_slots;
CREATE INDEX idx_photos_session_slot ON photos(session_id, slot, version DESC);

CREATE TABLE template_catalog (
    id TEXT NOT NULL, version TEXT NOT NULL,
    status TEXT NOT NULL CHECK (status IN ('draft','active','disabled')),
    manifest TEXT NOT NULL,
    created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
    PRIMARY KEY (id, version)
);
