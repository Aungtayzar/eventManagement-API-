<?php
declare(strict_types=1);

require_once __DIR__ . '/api_common.php';
require_once __DIR__ . '/dbconnection.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    apiError(405, 'METHOD_NOT_ALLOWED', 'Use GET to check the service.');
}
try {
    $db = databaseConnection();
    // Check that all required tables are installed as well as DB connectivity.
    foreach (['cloud_devices', 'cloud_events', 'cloud_sessions'] as $table) {
        $db->query("SELECT 1 FROM {$table} LIMIT 0");
    }
    apiResponse(200, ['success' => true, 'status' => 'ready']);
} catch (Throwable $exception) {
    error_log('Event health check failed: ' . get_class($exception) . ' code=' . $exception->getCode());
    apiError(503, 'SERVICE_UNAVAILABLE', 'Database unavailable or schema not installed.');
}
