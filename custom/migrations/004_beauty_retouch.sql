-- Migration 004: Beauty Retouch settings for Photobooth sessions
ALTER TABLE sessions ADD COLUMN beauty_preset TEXT NOT NULL DEFAULT 'natural';
ALTER TABLE sessions ADD COLUMN beauty_smooth INTEGER NOT NULL DEFAULT 20;
ALTER TABLE sessions ADD COLUMN beauty_brighten INTEGER NOT NULL DEFAULT 5;
ALTER TABLE sessions ADD COLUMN beauty_tone INTEGER NOT NULL DEFAULT 10;
