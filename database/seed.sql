-- =============================================================================
-- ZKTeco Attendance Management System - Seed Data
-- Run after schema.sql
-- =============================================================================

USE attendance_system;

-- =============================================================================
-- DEFAULT SUPER ADMIN
-- The admin password is set automatically on first login page load.
-- Default credentials: admin / admin123
-- =============================================================================
INSERT INTO users (username, password_hash, role, name, status)
VALUES ('admin', '__PENDING__', 'super_admin', 'System Administrator', 'active');

-- =============================================================================
-- DEFAULT SHIFT (Regular 9-5)
-- =============================================================================
INSERT INTO shifts (name, start_time, end_time, grace_minutes_late, grace_minutes_early, full_day_hours, is_night_shift, status)
VALUES ('Regular 9-5', '09:00:00', '17:00:00', 30, 30, 8.00, FALSE, 'active');

-- =============================================================================
-- SYSTEM SETTINGS
-- =============================================================================
INSERT INTO system_settings (setting_key, setting_value, description) VALUES
('weekly_off_days', 'fri,sat', 'Days of week that are off (comma-separated: mon,tue,wed,thu,fri,sat,sun)'),
('offline_threshold_minutes', '10', 'Minutes after which device is considered offline'),
('idle_threshold_minutes', '2', 'Minutes after which device is considered idle'),
('company_name', 'My Company', 'Company name shown in reports and dashboard'),
('company_logo_path', 'assets/img/logo.png', 'Path to company logo for PDF reports'),
('timezone', 'Asia/Dhaka', 'Server timezone'),
('device_approval_by_admin', '0', '1=admins can approve devices, 0=only super_admin'),
('time_sync_hour', '3', 'Hour (0-23) for daily device time sync cron'),
('auto_refresh_seconds', '30', 'Dashboard auto-refresh interval in seconds');

-- =============================================================================
-- DEFAULT GRADE
-- =============================================================================
INSERT INTO grades (name, status) VALUES ('Default Grade', 'active');
