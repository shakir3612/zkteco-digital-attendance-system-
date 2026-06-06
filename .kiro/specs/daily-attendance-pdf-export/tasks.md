# Implementation Plan: Daily Attendance PDF Export

## Overview

This feature adds PDF export functionality to the daily attendance view page. Users can select a date and generate a PDF containing all employees' attendance status for that day, following the existing report styling conventions.

## Tasks

- [ ] 1. Install TCPDF library via Composer
  - Navigate to php_dashboard directory
  - Run `composer require tecnickcom/tcpdf`
  - Verify vendor/autoload.php is created
  - _Requirements: 1.5, 8.1_

- [ ] 2. Create PDF generation script
  - [ ] 2.1 Create generate_daily_pdf.php file
    - Create new file at pages/attendance/generate_daily_pdf.php
    - Add require_once for composer autoload
    - Add database connection include
    - Add auth check
    - _Requirements: 1.1, 1.4, 8.3_

  - [ ] 2.2 Implement date validation
    - Get date from POST or GET parameter
    - Validate YYYY-MM-DD format
    - Redirect with error if invalid
    - _Requirements: 1.2_

  - [ ] 2.3 Implement fetchAttendanceDataForDate() function
    - Query attendance_daily table for selected date
    - Join with employees, grades, shifts tables
    - Return array of records ordered by employee name
    - Use prepared statements
    - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5, 4.6, 4.7, 4.8, 6.1, 8.1_

  - [ ] 2.4 Implement calculateSummaryStats() function
    - Count present, absent, on_leave, holiday, weekend
    - Count late (was_late=true) and early leave (left_early=true)
    - Return summary array
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5, 3.6, 3.7, 6.2, 6.3, 6.4, 6.5_

  - [ ] 2.5 Implement formatTimeForPDF() function
    - Handle null/empty values → return '—'
    - Convert datetime to HH:mm format
    - Handle '0000-00-00 00:00:00' as null
    - _Requirements: 5.1, 5.2, 7.1_

  - [ ] 2.6 Implement getStatusLabel() function
    - Map status codes to human-readable labels
    - present→'Present', absent→'Absent', on_leave→'On Leave', holiday→'Holiday', weekend→'Weekend'
    - _Requirements: 5.3, 5.4, 5.5, 5.6, 5.7_

  - [ ] 2.7 Get company settings for PDF header
    - Fetch company_name from system_settings
    - Fetch company_logo_path from system_settings
    - Apply defaults if not set
    - _Requirements: 2.1, 2.2_

  - [ ] 2.8 Generate PDF header section
    - Add company logo (validate path, prevent traversal)
    - Add company name
    - Add report title "DAILY ATTENDANCE REPORT"
    - Add selected date in "DD Mon YYYY" format
    - Add generation timestamp in "DD Mon YYYY, HH:MM AM/PM" format
    - _Requirements: 2.3, 2.4, 2.5, 8.4_

  - [ ] 2.9 Generate PDF summary section
    - Display present, absent, on_leave, holiday, weekend counts
    - Display late and early_leave counts
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5, 3.6, 3.7_

  - [ ] 2.10 Generate PDF detail table
    - Table columns: PIN, Name, Dept, Shift, In, Out, Total Hours, Status, Flags
    - Loop through records and render each row
    - Show "Late" flag when was_late=true
    - Show "Early" flag when left_early=true
    - Indicate single_punch when applicable
    - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5, 4.6, 4.7, 4.8, 4.9, 4.10, 4.11_

  - [ ] 2.11 Handle empty records case
    - If no records for date, generate PDF with empty table
    - Add "No records found for this date" message
    - _Requirements: 1.3_

  - [ ] 2.12 Add page numbers to PDF footer
    - Enable page numbers in TCPDF
    - Format: "Page X of Y"
    - _Requirements: 2.6_

  - [ ] 2.13 Output PDF to browser
    - Set Content-Type: application/pdf
    - Use 'I' mode to stream inline
    - Set filename: Daily_Attendance_YYYY-MM-DD.pdf
    - _Requirements: 1.1_

- [ ] 3. Add PDF export button to daily.php
  - [ ] 3.1 Add Export PDF button to filter bar
    - Add form with POST method to generate_daily_pdf.php
    - Add hidden input for date value
    - Add target="_blank" for new tab
    - Add btn-success class and PDF icon
    - _Requirements: 1.1, 7.1_

- [ ] 4. Error handling and security
  - [ ] 4.1 Handle database connection failure
    - Catch PDOException
    - Display "Database connection failed" message
    - _Requirements: 1.4_

  - [ ] 4.2 Handle TCPDF not installed
    - Check if TCPDF class exists
    - Display error with installation instructions if missing
    - _Requirements: 1.5_

  - [ ] 4.3 Add authentication check
    - Verify user is logged in before generating PDF
    - _Requirements: 8.3_

- [ ] 5. Checkpoint - Ensure all requirements are met
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Implementation uses PHP with TCPDF library
- All database queries use prepared statements for SQL injection prevention
- All user output is escaped with htmlspecialchars()
- Logo path is validated to prevent directory traversal
- Task 1 (TCPDF installation) must be completed before other tasks
- PDF follows existing employee report styling conventions
- The feature supports both POST (form submission) and GET (direct URL) access

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1"] },
    { "id": 1, "tasks": ["2.1", "2.2", "3.1", "4.1", "4.2", "4.3"] },
    { "id": 2, "tasks": ["2.3"] },
    { "id": 3, "tasks": ["2.4", "2.5", "2.6", "2.7"] },
    { "id": 4, "tasks": ["2.8", "2.9", "2.10", "2.11", "2.12", "2.13"] },
    { "id": 5, "tasks": ["5"] }
  ]
}
```