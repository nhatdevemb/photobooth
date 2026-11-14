CREATE TABLE lut_catalog (
    id TEXT NOT NULL,
    version TEXT NOT NULL,
    name TEXT NOT NULL,
    status TEXT NOT NULL CHECK (status IN ('draft','active','disabled')),
    sha256 TEXT NOT NULL,
    intensity INTEGER NOT NULL CHECK (intensity BETWEEN 0 AND 100),
    file_path TEXT NOT NULL,
    preview_path TEXT NOT NULL,
    preview_sha256 TEXT NOT NULL,
    revision INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (id, version)
);
CREATE UNIQUE INDEX idx_lut_one_active ON lut_catalog(id) WHERE status = 'active';
CREATE INDEX idx_lut_sha ON lut_catalog(sha256);
ALTER TABLE sessions ADD COLUMN lut_version TEXT NOT NULL DEFAULT '1.0.0';
ALTER TABLE sessions ADD COLUMN lut_intensity INTEGER NOT NULL DEFAULT 100 CHECK (lut_intensity BETWEEN 0 AND 100);
ALTER TABLE sessions ADD COLUMN lut_sha256 TEXT;
UPDATE sessions SET lut_sha256 = CASE selected_lut
    WHEN 'original' THEN 'identity-original'
    WHEN 'korean-soft' THEN 'c146e8b1f3dace37129a136e93ca1198e1cc77342eaf701a7ad67ebd632836fe'
    WHEN 'film-400' THEN '57a67308c10321ba201215a50c2bb4eeb6da8cf667ec44030c4fc019b6be10a5'
    WHEN 'vintage-fade' THEN '167f31585dab2ecb651e4ddc447e4488cc69e92ba016a8b6d23a74981b707b65'
END;
