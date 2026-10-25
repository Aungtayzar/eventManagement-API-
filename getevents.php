<?php
declare(strict_types=1);

// Read-only list of events for the user (hybrid) app.
// GET getevents.php                    -> all events
// GET getevents.php?name=music         -> events whose name contains "music"
// GET getevents.php?date=25/12/2026    -> events on that date (DD/MM/YYYY)
// Both filters can be used together.

require_once __DIR__ . '/api_common.php';
require_once __DIR__ . '/dbconnection.php';

// Allows the app to call this file when it runs in a web browser (Expo web).
header('Access-Control-Allow-Origin: *');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    apiError(405, 'METHOD_NOT_ALLOWED', 'Use GET to list events.');
}

$name = trim((string)($_GET['name'] ?? ''));
$date = trim((string)($_GET['date'] ?? ''));

$sql = "SELECT device_id, local_id, name, DATE_FORMAT(date, '%d/%m/%Y') AS date,
               location, organiser, type, status, amount, description,
               contact_email, contact_phone
        FROM cloud_events
        WHERE 1 = 1";
$params = [];

if ($name !== '') {
    // Escape % and _ so they are searched as normal characters, not LIKE wildcards.
    $sql .= " AND name LIKE ? ESCAPE '!'";
    $params[] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $name) . '%';
}
if ($date !== '') {
    $parsed = DateTime::createFromFormat('!d/m/Y', $date);
    if ($parsed === false || $parsed->format('d/m/Y') !== $date) {
        apiError(422, 'VALIDATION_ERROR', 'date must be in DD/MM/YYYY format.');
    }
    $sql .= ' AND date = ?';
    $params[] = $parsed->format('Y-m-d');
}
$sql .= ' ORDER BY cloud_events.date, name';

try {
    $db = databaseConnection();
    $statement = $db->prepare($sql);
    $statement->execute($params);

    $events = [];
    foreach ($statement->fetchAll() as $row) {
        $events[] = [
            // Different admin devices can reuse the same local id, so combine both.
            'id' => $row['device_id'] . '-' . $row['local_id'],
            'name' => $row['name'],
            'date' => $row['date'],
            'location' => $row['location'],
            'organiser' => $row['organiser'],
            'type' => $row['type'],
            'status' => $row['status'],
            'amount' => (float)$row['amount'],
            'description' => $row['description'],
            'contactEmail' => $row['contact_email'],
            'contactPhone' => $row['contact_phone'],
        ];
    }
    apiResponse(200, ['success' => true, 'events' => $events]);
} catch (Throwable $exception) {
    error_log('Event list failed: ' . get_class($exception) . ' code=' . $exception->getCode());
    apiError(503, 'SERVICE_UNAVAILABLE', 'Could not load events. Check the database setup.');
}
