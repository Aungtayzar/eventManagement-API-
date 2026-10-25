<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../dbconnection.php';

// Calls getevents.php over HTTP (Apache must be running) and returns [status, json].
function getEvents(array $query = [], string $method = 'GET'): array
{
    $base = getenv('API_TEST_URL') ?: 'http://localhost/eventmanagementapi';
    $curl = curl_init(rtrim($base, '/') . '/getevents.php?' . http_build_query($query));
    curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $response = curl_exec($curl);
    if ($response === false) {
        throw new RuntimeException('HTTP request failed: ' . curl_error($curl));
    }
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    return [$status, json_decode($response, true, 64, JSON_THROW_ON_ERROR)];
}

function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// Returns only the test device's events, so real events in the database don't affect results.
function testEvents(array $response, string $device): array
{
    verify($response[0] === 200 && $response[1]['success'] === true, 'Expected HTTP 200: ' . json_encode($response));
    $events = array_values(array_filter($response[1]['events'], fn($e) => str_starts_with($e['id'], $device . '-')));
    return array_map(fn($e) => $e['name'], $events);
}

// Errors
$wrongMethod = getEvents([], 'POST');
verify($wrongMethod[0] === 405 && $wrongMethod[1]['success'] === false, 'POST must be rejected with 405.');
foreach (['99/99/2026', '2026-10-22', 'hello', '1/2/2026'] as $badDate) {
    $response = getEvents(['date' => $badDate]);
    verify($response[0] === 422 && $response[1]['error']['code'] === 'VALIDATION_ERROR', "Bad date '{$badDate}' must return 422.");
}

// Insert test events straight into the database.
$db = databaseConnection();
$device = 'getevents-test-' . bin2hex(random_bytes(8));
$db->prepare('INSERT INTO cloud_devices (device_id, revision) VALUES (?, 1)')->execute([$device]);
$insert = $db->prepare("INSERT INTO cloud_events (device_id, local_id, name, date, location, organiser, type, status,
    amount, description, contact_email, contact_phone) VALUES (?, ?, ?, ?, 'Yangon', 'Tester', 'Workshop', 'Upcoming', ?, '', 'a@b.com', '0912345')");
try {
    $insert->execute([$device, 1, 'Music Festival', '2026-12-25', 50.5]);
    $insert->execute([$device, 2, 'Tech Conference', '2026-12-25', 0]);
    $insert->execute([$device, 3, 'Jazz Music Night', '2027-01-10', 10]);

    // All events, sorted by date then name
    verify(testEvents(getEvents(), $device) === ['Music Festival', 'Tech Conference', 'Jazz Music Night'], 'List all events in date order.');

    // Response format the app relies on
    $first = array_values(array_filter(getEvents()[1]['events'], fn($e) => $e['id'] === $device . '-1'))[0];
    verify($first['date'] === '25/12/2026', 'Date is returned as DD/MM/YYYY.');
    verify($first['amount'] === 50.5, 'Amount is returned as a number.');
    verify($first['contactEmail'] === 'a@b.com' && $first['contactPhone'] === '0912345', 'Contact fields use camelCase names.');

    // Search by name (part of the name, any letter case)
    verify(testEvents(getEvents(['name' => 'music']), $device) === ['Music Festival', 'Jazz Music Night'], 'Search by name.');
    verify(testEvents(getEvents(['name' => '  TECH  ']), $device) === ['Tech Conference'], 'Name search trims spaces and ignores case.');
    verify(testEvents(getEvents(['name' => 'no such event xyz']), $device) === [], 'Unknown name returns no events.');

    // Search by date
    verify(testEvents(getEvents(['date' => '25/12/2026']), $device) === ['Music Festival', 'Tech Conference'], 'Search by date.');
    verify(testEvents(getEvents(['date' => '01/01/2030']), $device) === [], 'Date with no events returns none.');

    // Search by name and date together
    verify(testEvents(getEvents(['name' => 'music', 'date' => '10/01/2027']), $device) === ['Jazz Music Night'], 'Search by name and date.');

    // "%" and "_" are treated as normal letters, not wildcards
    $insert->execute([$device, 4, '50% Off_Sale', '2027-02-01', 0]);
    verify(testEvents(getEvents(['name' => '%']), $device) === ['50% Off_Sale'], 'A % in the name is not a wildcard.');
    verify(testEvents(getEvents(['name' => 'f_s']), $device) === ['50% Off_Sale'], 'An _ in the name is not a wildcard.');
    verify(testEvents(getEvents(['name' => 'Music_Festival']), $device) === [], 'An _ does not match a space.');

    echo "getevents tests passed: method/date errors, list, response format, name search, date search, combined search.\n";
} finally {
    // Deleting the device also deletes its events (ON DELETE CASCADE).
    $db->prepare('DELETE FROM cloud_devices WHERE device_id = ?')->execute([$device]);
}
