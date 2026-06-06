# Requirements Document

## Introduction

This document specifies the requirements for the Daily Attendance PDF Export feature. The feature adds PDF export functionality to the daily attendance view page, allowing users to select a date and generate a PDF containing all employees' attendance status for that day. The PDF follows the existing report styling from the employee report, including company logo, header, table format, and summary statistics.

## Glossary

- **PDF**: Portable Document Format - a file format for presenting documents
- **TCPDF**: PHP library for generating PDF documents
- **attendance_daily**: Database table containing processed daily attendance records
- **System**: The PHP dashboard application (php_dashboard)
- **Generator**: The PDF generation script (generate_daily_pdf.php)
- **Validator**: The date validation component of the Generator
- **Formatter**: The time and status formatting component of the Generator

## Requirements

### Requirement 1

**User Story:** As an administrator, I want to export daily attendance data to PDF, so that I can print and share hard copies of attendance reports.

#### Acceptance Criteria

1. WHEN a user selects a date on the daily attendance page and clicks the Export PDF button, THE Generator SHALL generate a PDF containing all employees' attendance records for that date.
2. WHEN a user submits an invalid date format, THE Validator SHALL reject the request and redirect back to the daily attendance page with an error message.
3. WHEN the database returns no records for the selected date, THE Generator SHALL generate a PDF with an empty table and display "No records found for this date" message.
4. WHEN the database connection fails during PDF generation, THE Generator SHALL display an error message "Database connection failed".
5. WHEN TCPDF library is not installed or cannot be loaded, THE Generator SHALL display an error message with installation instructions.

### Requirement 2

**User Story:** As an administrator, I want the PDF to include company branding and report metadata, so that the report looks professional and is properly identified.

#### Acceptance Criteria

1. THE Generator SHALL include the company name from system_settings in the PDF header.
2. THE Generator SHALL include the company logo in the PDF header.
3. THE Generator SHALL include the report title "DAILY ATTENDANCE REPORT" in the header.
4. THE Generator SHALL include the selected date in human-readable format (e.g., "20 May 2026") in the header.
5. THE Generator SHALL include the generation timestamp in the format "DD Mon YYYY, HH:MM AM/PM" in the header.
6. THE Generator SHALL include page numbers in the PDF footer.

### Requirement 3

**User Story:** As an administrator, I want the PDF to include summary statistics, so that I can quickly see attendance overview without reading every detail.

#### Acceptance Criteria

1. THE Generator SHALL calculate and display the count of employees with status 'present'.
2. THE Generator SHALL calculate and display the count of employees with status 'absent'.
3. THE Generator SHALL calculate and display the count of employees with status 'on_leave'.
4. THE Generator SHALL calculate and display the count of employees with status 'holiday'.
5. THE Generator SHALL calculate and display the count of employees with status 'weekend'.
6. THE Generator SHALL calculate and display the count of employees flagged as late (was_late=true).
7. THE Generator SHALL calculate and display the count of employees flagged as left early (left_early=true).

### Requirement 4

**User Story:** As an administrator, I want detailed attendance information for each employee in the PDF, so that I can see individual attendance details.

#### Acceptance Criteria

1. FOR EACH attendance record, THE PDF SHALL include the employee PIN.
2. FOR EACH attendance record, THE PDF SHALL include the employee name.
3. FOR EACH attendance record, THE PDF SHALL include the department/grade name.
4. FOR EACH attendance record, THE PDF SHALL include the shift name.
5. FOR EACH attendance record, THE PDF SHALL include the check-in time in HH:mm format.
6. FOR EACH attendance record, THE PDF SHALL include the check-out time in HH:mm format.
7. FOR EACH attendance record, THE PDF SHALL include the total hours worked.
8. FOR EACH attendance record, THE PDF SHALL include the attendance status as a human-readable label.
9. WHEN a record has was_late=true, THE PDF SHALL display a "Late" flag in that row.
10. WHEN a record has left_early=true, THE PDF SHALL display an "Early" flag in that row.
11. WHEN a record has single_punch=true, THE PDF SHALL indicate this in the row.

### Requirement 5

**User Story:** As an administrator, I want time values to be formatted consistently, so that the PDF is readable and professional.

#### Acceptance Criteria

1. WHEN a time value is NULL or empty, THE Formatter SHALL display "—" instead of the time.
2. WHEN a valid datetime is provided, THE Formatter SHALL extract and display only the time portion in HH:mm format (24-hour).
3. THE Formatter SHALL display status 'present' as "Present".
4. THE Formatter SHALL display status 'absent' as "Absent".
5. THE Formatter SHALL display status 'on_leave' as "On Leave".
6. THE Formatter SHALL display status 'holiday' as "Holiday".
7. THE Formatter SHALL display status 'weekend' as "Weekend".

### Requirement 6

**User Story:** As a system, I need to ensure PDF output data integrity, so that users can trust the accuracy of the report.

#### Acceptance Criteria

1. THE Generator SHALL include only records where the date matches the selected date.
2. THE summary present count SHALL equal the count of records with status 'present' in the detail table.
3. THE summary absent count SHALL equal the count of records with status 'absent' in the detail table.
4. THE summary late count SHALL equal the count of records where was_late=true in the detail table.
5. THE summary early_leave count SHALL equal the count of records where left_early=true in the detail table.

### Requirement 7

**User Story:** As an administrator, I want to access the PDF export via URL, so that I can integrate it with other systems or create direct links.

#### Acceptance Criteria

1. WHEN a GET request is made to generate_daily_pdf.php with a valid date parameter, THE Generator SHALL generate and stream the PDF to the browser.
2. THE date parameter SHALL be accepted in YYYY-MM-DD format.

### Requirement 8

**User Story:** As a developer, I need the PDF generation to follow security best practices, so that the system is protected against common vulnerabilities.

#### Acceptance Criteria

1. THE Generator SHALL use prepared statements for all database queries to prevent SQL injection.
2. THE Generator SHALL use htmlspecialchars() to escape all user-provided output.
3. THE Generator SHALL verify user authentication before generating any PDF.
4. THE Generator SHALL validate that the logo path does not contain directory traversal sequences.