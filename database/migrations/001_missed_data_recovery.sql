-- =============================================================================
-- Migration 001 - Missed / Unprocessed Data Recovery
-- =============================================================================
-- Adds:
--   * 'pending' attendance status (working day with no confirmed data yet)
--   * day_type / worked_on_off_day / is_pending / locked columns on attendance_daily
--   * attendance_reprocess_queue (dirty-date queue for self-healing backfill)
--
-- Safe to run on an existing database. Idempotency notes are inline.
-- Run:  mysql -u root attendance_system < database/migrations/001_missed_data_recovery.sql
-- =============================================================================

USE attendance_system;

-- 1) Extend the status enum with 'pending' (existing rows are unaffected).
ALTER TABLE attendance_daily
    MODIFY COLUMN status ENUM('present','absent','on_leave','holiday','weekend','pending') DEFAULT 'absent';

-- 2) New columns. (If re-running, drop them first or ignore "Duplicate column" errors.)
ALTER TABLE attendance_daily
    ADD COLUMN day_type ENUM('working','weekend','holiday') NOT NULL DEFAULT 'working' AFTER status,
    ADD COLUMN worked_on_off_day BOOLEAN NOT NULL DEFAULT FALSE AFTER day_type,
    ADD COLUMN is_pending BOOLEAN NOT NULL DEFAULT FALSE AFTER worked_on_off_day,
    ADD COLUMN locked BOOLEAN NOT NULL DEFAULT FALSE AFTER is_pending;

-- 3) Helpful index for status filtering / pending lookups.
--    (Ignore "Duplicate key name" if re-running.)
ALTER TABLE attendance_daily
    ADD INDEX idx_status (status);

-- 4) Dirty-date reprocess queue.
CREATE TABLE IF NOT EXISTS attendance_reprocess_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    target_date DATE NOT NULL,
    reason VARCHAR(100),
    enqueued_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_date (target_date),
    INDEX idx_enqueued (enqueued_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5) Backfill day_type for already-processed rows so historical reports read correctly.
UPDATE attendance_daily SET day_type = 'holiday' WHERE status = 'holiday';
UPDATE attendance_daily SET day_type = 'weekend' WHERE status = 'weekend';
