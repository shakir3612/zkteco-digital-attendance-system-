-- =============================================================================
-- ZKTeco Attendance Management System - Database Schema
-- Database: attendance_system
-- =============================================================================

CREATE DATABASE IF NOT EXISTS attendance_system
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE attendance_system;

-- =============================================================================
-- SYSTEM SETTINGS
-- =============================================================================
CREATE TABLE system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    description VARCHAR(255),
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- USERS (Admin accounts)
-- =============================================================================
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('super_admin', 'admin') NOT NULL,
    name VARCHAR(100),
    email VARCHAR(100),
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_login DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- DEVICES
-- =============================================================================
CREATE TABLE devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    serial_number VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(100),
    location VARCHAR(200),
    ip_address VARCHAR(45),
    firmware_ver VARCHAR(50),
    push_ver VARCHAR(20),
    model VARCHAR(50),
    last_seen DATETIME,
    status ENUM('pending_approval','approved','rejected','suspended','inactive') DEFAULT 'pending_approval',
    approved_by INT,
    approved_at DATETIME,
    notes TEXT,
    registered_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_serial (serial_number),
    INDEX idx_last_seen (last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- DEVICE CONNECTION LOG
-- =============================================================================
CREATE TABLE device_connection_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_sn VARCHAR(50) NOT NULL,
    ip_address VARCHAR(45),
    action VARCHAR(50),
    was_allowed BOOLEAN,
    details TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_device_time (device_sn, created_at),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- GRADES (Employee grades/levels)
-- =============================================================================
CREATE TABLE grades (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    parent_id INT,
    status ENUM('active', 'inactive') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- EMPLOYEES
-- =============================================================================
CREATE TABLE employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pin VARCHAR(20) UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    grade_id INT,
    designation VARCHAR(100),
    card_number VARCHAR(50),
    privilege INT DEFAULT 0,
    phone VARCHAR(20),
    email VARCHAR(100),
    join_date DATE,
    status ENUM('active', 'inactive', 'terminated') DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_grade (grade_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- BIOMETRIC TEMPLATES (master copy)
-- =============================================================================
CREATE TABLE biometric_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pin VARCHAR(20) NOT NULL,
    bio_type INT NOT NULL,
    bio_no INT DEFAULT 0,
    bio_index INT DEFAULT 0,
    valid INT DEFAULT 1,
    duress INT DEFAULT 0,
    major_ver INT,
    minor_ver INT,
    format INT DEFAULT 0,
    template LONGTEXT NOT NULL,
    source_device_sn VARCHAR(50),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_bio (pin, bio_type, bio_no, bio_index),
    INDEX idx_pin (pin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- DEVICE-EMPLOYEE SYNC STATUS
-- =============================================================================
CREATE TABLE device_employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_sn VARCHAR(50) NOT NULL,
    pin VARCHAR(20) NOT NULL,
    user_synced BOOLEAN DEFAULT FALSE,
    bio_synced BOOLEAN DEFAULT FALSE,
    synced_at DATETIME,
    UNIQUE KEY unique_device_emp (device_sn, pin),
    INDEX idx_device (device_sn),
    INDEX idx_pin (pin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- DEVICE COMMANDS QUEUE
-- =============================================================================
CREATE TABLE device_commands (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_sn VARCHAR(50) NOT NULL,
    command_type VARCHAR(50) NOT NULL,
    command_content LONGTEXT NOT NULL,
    priority INT DEFAULT 5,
    status ENUM('pending','delivered','acknowledged','failed','cancelled') DEFAULT 'pending',
    attempts INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    delivered_at DATETIME,
    acknowledged_at DATETIME,
    result_code INT,
    INDEX idx_device_status (device_sn, status),
    INDEX idx_priority (priority, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- RAW ATTENDANCE LOGS
-- =============================================================================
CREATE TABLE attendance_raw (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_sn VARCHAR(50) NOT NULL,
    pin VARCHAR(20) NOT NULL,
    punch_time DATETIME NOT NULL,
    status INT DEFAULT 0,
    verify_type INT DEFAULT 0,
    work_code VARCHAR(20),
    reserved1 VARCHAR(20),
    reserved2 VARCHAR(20),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_punch (pin, punch_time, device_sn),
    INDEX idx_pin_time (pin, punch_time),
    INDEX idx_device_time (device_sn, punch_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- PROCESSED ATTENDANCE (daily summary)
-- =============================================================================
CREATE TABLE attendance_daily (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    pin VARCHAR(20) NOT NULL,
    date DATE NOT NULL,
    first_in DATETIME,
    last_out DATETIME,
    total_hours DECIMAL(5,2),
    status ENUM('present','absent','on_leave','holiday','weekend') DEFAULT 'absent',
    was_late BOOLEAN DEFAULT FALSE,
    late_minutes INT DEFAULT 0,
    left_early BOOLEAN DEFAULT FALSE,
    early_minutes INT DEFAULT 0,
    single_punch BOOLEAN DEFAULT FALSE,
    shift_id INT,
    remarks VARCHAR(255),
    processed_at DATETIME,
    UNIQUE KEY unique_daily (pin, date),
    INDEX idx_date (date),
    INDEX idx_employee (employee_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- SHIFTS
-- =============================================================================
CREATE TABLE shifts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    grace_minutes_late INT DEFAULT 30,
    grace_minutes_early INT DEFAULT 30,
    full_day_hours DECIMAL(4,2) DEFAULT 8.00,
    is_night_shift BOOLEAN DEFAULT FALSE,
    status ENUM('active', 'inactive') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- EMPLOYEE-SHIFT ASSIGNMENT
-- =============================================================================
CREATE TABLE employee_shifts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    shift_id INT NOT NULL,
    effective_from DATE NOT NULL,
    effective_to DATE,
    UNIQUE KEY unique_assignment (employee_id, effective_from),
    INDEX idx_employee (employee_id),
    INDEX idx_shift (shift_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- LEAVES
-- =============================================================================
CREATE TABLE leaves (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    leave_type ENUM('casual','sick','annual','unpaid','maternity','other') NOT NULL,
    from_date DATE NOT NULL,
    to_date DATE NOT NULL,
    days DECIMAL(4,1) NOT NULL,
    reason VARCHAR(500),
    status ENUM('pending','approved','rejected','cancelled') DEFAULT 'pending',
    approved_by INT,
    applied_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    actioned_at DATETIME,
    INDEX idx_employee (employee_id),
    INDEX idx_dates (from_date, to_date),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- HOLIDAYS
-- =============================================================================
CREATE TABLE holidays (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    date DATE NOT NULL UNIQUE,
    type ENUM('public','optional','restricted') DEFAULT 'public'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- DEVICE STAMPS (track sync position per device per table)
-- =============================================================================
CREATE TABLE device_stamps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_sn VARCHAR(50) NOT NULL,
    table_name VARCHAR(50) NOT NULL,
    stamp VARCHAR(50) DEFAULT '0',
    UNIQUE KEY unique_stamp (device_sn, table_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- IMPORT JOBS (track data imports from devices)
-- =============================================================================
CREATE TABLE import_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_sn VARCHAR(50) NOT NULL,
    job_type ENUM('users','biometrics','attendance') NOT NULL,
    conflict_mode ENUM('skip','update','update_blank') DEFAULT 'skip',
    status ENUM('queued','running','completed','failed','partial') DEFAULT 'queued',
    requested_by INT NOT NULL,
    records_received INT DEFAULT 0,
    records_inserted INT DEFAULT 0,
    records_updated INT DEFAULT 0,
    records_skipped INT DEFAULT 0,
    records_failed INT DEFAULT 0,
    started_at DATETIME,
    completed_at DATETIME,
    error_message TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_device (device_sn, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- REPORT TEMPLATES
-- =============================================================================
CREATE TABLE report_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    created_by INT NOT NULL,
    columns_json JSON,
    filters_json JSON,
    grouping VARCHAR(20) DEFAULT 'summary',
    is_shared BOOLEAN DEFAULT FALSE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- AUDIT LOG
-- =============================================================================
CREATE TABLE audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(100) NOT NULL,
    target_type VARCHAR(50),
    target_id INT,
    details TEXT,
    ip_address VARCHAR(45),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_action (action, created_at),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- NOTIFICATIONS
-- =============================================================================
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('new_device','device_offline','device_online','sync_failed','system') NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT,
    target_role ENUM('super_admin','admin','all') DEFAULT 'all',
    is_read BOOLEAN DEFAULT FALSE,
    read_by INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_unread (is_read, created_at),
    INDEX idx_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- VIEW: Device today stats
-- =============================================================================
CREATE OR REPLACE VIEW v_device_today_stats AS
SELECT
    device_sn,
    COUNT(*) AS punches_today,
    MAX(punch_time) AS last_punch_at
FROM attendance_raw
WHERE DATE(punch_time) = CURDATE()
GROUP BY device_sn;
