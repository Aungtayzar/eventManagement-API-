<?php
declare(strict_types=1);

require_once __DIR__ . '/api_common.php';
require_once __DIR__ . '/dbconnection.php';
require_once __DIR__ . '/upload_service.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    apiError(405, 'METHOD_NOT_ALLOWED', 'Use POST to upload a complete event snapshot.');
}
$contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
if ($contentType !== 'application/json') {
    apiError(415, 'UNSUPPORTED_MEDIA_TYPE', 'Send Content-Type: application/json.');
}
$maxBytes = 10 * 1024 * 1024;
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxBytes) {
    apiError(413, 'PAYLOAD_TOO_LARGE', 'Upload must not exceed 10 MiB.');
}
$raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
if ($raw === false) {
    apiError(400, 'INVALID_BODY', 'Could not read the request body.');
}
if (strlen($raw) > $maxBytes) {
    apiError(413, 'PAYLOAD_TOO_LARGE', 'Upload must not exceed 10 MiB.');
}
try {
    $input = json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
    $upload = validateUpload($input);
} catch (JsonException $exception) {
    apiError(400, 'INVALID_JSON', 'Body must contain valid UTF-8 JSON.');
} catch (InvalidArgumentException $exception) {
    apiError(422, 'VALIDATION_ERROR', $exception->getMessage());
}

try {
    $db = databaseConnection();
    apiResponse(200, saveUpload($db, $upload));
} catch (UploadConflict $exception) {
    apiError(409, 'REVISION_CONFLICT', $exception->getMessage());
} catch (Throwable $exception) {
    // Do not expose database credentials, SQL or contact information.
    error_log('Event upload failed: ' . get_class($exception) . ' code=' . $exception->getCode());
    apiError(503, 'SERVICE_UNAVAILABLE', 'Upload failed. Check the database setup and retry the same snapshot.');
}
