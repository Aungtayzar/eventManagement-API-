<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../dbconnection.php';

function request(string $endpoint, string $method, ?string $body = null, string $contentType = 'application/json'): array
{
    $base = getenv('API_TEST_URL') ?: 'http://localhost/eventmanagementapi';
    $curl = curl_init(rtrim($base, '/') . '/' . $endpoint);
    curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => ['Content-Type: ' . $contentType]]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }
    $response = curl_exec($curl);
    if ($response === false) {
        throw new RuntimeException('HTTP request failed: ' . curl_error($curl));
    }
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    return [$status, json_decode($response, true, 64, JSON_THROW_ON_ERROR)];
}

function expectStatus(array $response, int $status): void
{
    if ($response[0] !== $status || $response[1]['success'] !== ($status === 200)) {
        throw new RuntimeException('Unexpected HTTP response: ' . json_encode($response));
    }
}

expectStatus(request('health.php', 'GET'), 200);
expectStatus(request('health.php', 'POST'), 405);
expectStatus(request('uploadevent.php', 'GET'), 405);
expectStatus(request('uploadevent.php', 'POST', '{}', 'text/plain'), 415);
expectStatus(request('uploadevent.php', 'POST', '{broken'), 400);
expectStatus(request('uploadevent.php', 'POST', '{}'), 422);
expectStatus(request('uploadevent.php', 'POST', str_repeat(' ', 10 * 1024 * 1024 + 1)), 413);

$db = databaseConnection();
$device = 'http-test-' . bin2hex(random_bytes(16));
$input = json_decode(file_get_contents(__DIR__ . '/../example-upload.json'), false, 64, JSON_THROW_ON_ERROR);
$input->deviceId = $device;
$second = clone $input->events[0];
$second->id = 2;
$second->name = 'Second event';
$second->sessions = [];
$input->events[] = $second;
try {
    $json = json_encode($input, JSON_THROW_ON_ERROR);
    $result = request('uploadevent.php', 'POST', $json);
    expectStatus($result, 200);
    if ($result[1]['uploadedEvents'] !== 2 || $result[1]['uploadedSessions'] !== 1) {
        throw new RuntimeException('Bulk upload did not store both events and their session.');
    }
    $retry = request('uploadevent.php', 'POST', $json);
    expectStatus($retry, 200);
    if (!$retry[1]['replayed']) {
        throw new RuntimeException('Retry must be recognized.');
    }
    $input->events[0]->name = 'Changed event';
    expectStatus(request('uploadevent.php', 'POST', json_encode($input, JSON_THROW_ON_ERROR)), 409);
    $input->revision = 2;
    expectStatus(request('uploadevent.php', 'POST', json_encode($input, JSON_THROW_ON_ERROR)), 200);
    echo "HTTP tests passed: health, method/content/body errors, size limit, bulk upload, retry, conflict and update.\n";
} finally {
    $cleanup = $db->prepare('DELETE FROM cloud_devices WHERE device_id = ?');
    $cleanup->execute([$device]);
}
