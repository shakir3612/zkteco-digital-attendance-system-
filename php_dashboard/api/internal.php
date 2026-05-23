<?php
/**
 * Internal API Bridge - PHP calls to Python FastAPI server.
 * Provides helper functions for dashboard pages to interact with the Python backend.
 * 
 * The Python server runs on port 8015. This bridge makes HTTP requests to it.
 */

// Python server URL (same machine, different port)
define('PYTHON_API_URL', 'http://127.0.0.1:8015/api');

/**
 * Make a GET request to the Python API.
 */
function apiGet(string $endpoint, array $params = []): array {
    $url = PYTHON_API_URL . $endpoint;
    if ($params) {
        $url .= '?' . http_build_query($params);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['success' => false, 'error' => "Connection failed: {$error}"];
    }

    $data = json_decode($response, true);
    if ($httpCode >= 400) {
        return ['success' => false, 'error' => $data['detail'] ?? "HTTP {$httpCode}", 'code' => $httpCode];
    }

    return $data ?: ['success' => false, 'error' => 'Invalid response'];
}

/**
 * Make a POST request to the Python API.
 */
function apiPost(string $endpoint, array $body = []): array {
    $url = PYTHON_API_URL . $endpoint;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['success' => false, 'error' => "Connection failed: {$error}"];
    }

    $data = json_decode($response, true);
    if ($httpCode >= 400) {
        return ['success' => false, 'error' => $data['detail'] ?? "HTTP {$httpCode}", 'code' => $httpCode];
    }

    return $data ?: ['success' => false, 'error' => 'Invalid response'];
}

// =============================================================================
// CONVENIENCE FUNCTIONS (used by dashboard pages)
// =============================================================================

/**
 * Sync time to a device.
 */
function apiSyncTime(string $deviceSn): array {
    return apiPost('/device/sync-time', ['device_sn' => $deviceSn]);
}

/**
 * Reboot a device.
 */
function apiRebootDevice(string $deviceSn): array {
    return apiPost('/device/reboot', ['device_sn' => $deviceSn]);
}

/**
 * Full re-sync (all users + bio) to a device.
 */
function apiResyncAll(string $deviceSn): array {
    return apiPost('/device/resync-all', ['device_sn' => $deviceSn]);
}

/**
 * Cancel all pending commands for a device.
 */
function apiCancelCommands(string $deviceSn): array {
    return apiPost('/device/cancel-commands', ['device_sn' => $deviceSn]);
}

/**
 * Start an import job (pull data from device).
 */
function apiStartImport(string $deviceSn, string $jobType, string $conflictMode = 'skip', int $requestedBy = 1): array {
    return apiPost('/import/start', [
        'device_sn' => $deviceSn,
        'job_type' => $jobType,
        'conflict_mode' => $conflictMode,
        'requested_by' => $requestedBy,
    ]);
}

/**
 * Get import job status.
 */
function apiImportStatus(int $jobId): array {
    return apiGet("/import/status/{$jobId}");
}

/**
 * Get device status and command stats.
 */
function apiDeviceStatus(string $deviceSn): array {
    return apiGet("/device/status/{$deviceSn}");
}

/**
 * Trigger attendance processing for a date.
 */
function apiProcessAttendance(string $date, string $endDate = null): array {
    $body = ['date' => $date];
    if ($endDate) {
        $body['end_date'] = $endDate;
    }
    return apiPost('/attendance/process', $body);
}

/**
 * Check if the Python server is reachable.
 */
function apiHealthCheck(): array {
    $url = 'http://127.0.0.1:8015/health';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['status' => 'unreachable', 'error' => $error];
    }
    return json_decode($response, true) ?: ['status' => 'unknown'];
}
