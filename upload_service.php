<?php
declare(strict_types=1);

final class UploadConflict extends RuntimeException {}

function uploadObject(mixed $value, array $allowed, string $path): array
{
    if (!$value instanceof stdClass) {
        throw new InvalidArgumentException("{$path} must be a JSON object.");
    }
    $fields = get_object_vars($value);
    if (array_diff(array_keys($fields), $allowed)) {
        throw new InvalidArgumentException("{$path} contains unsupported fields.");
    }
    return $fields;
}

function uploadText(array $fields, string $key, int $max, bool $required = true): string
{
    $value = $fields[$key] ?? ($required ? null : '');
    if (!is_string($value) || ($required && trim($value) === '') || strlen($value) > $max) {
        throw new InvalidArgumentException("{$key} must be text of at most {$max} UTF-8 bytes" . ($required ? ' and must not be blank.' : '.'));
    }
    return $value;
}

function uploadId(mixed $value, string $key): int
{
    if (!is_int($value) || $value < 1) {
        throw new InvalidArgumentException("{$key} must be a positive integer.");
    }
    return $value;
}

function uploadList(mixed $value, string $key, int $max): array
{
    if (!is_array($value) || !array_is_list($value) || count($value) > $max) {
        throw new InvalidArgumentException("{$key} must be an array with at most {$max} items.");
    }
    return $value;
}

function uploadChoice(array $fields, string $key, array $choices): string
{
    $value = uploadText($fields, $key, 32);
    if (!in_array($value, $choices, true)) {
        throw new InvalidArgumentException("{$key} has an unsupported value.");
    }
    return $value;
}

function validateUpload(mixed $input): array
{
    $body = uploadObject($input, ['deviceId', 'revision', 'events'], 'body');
    $deviceId = uploadText($body, 'deviceId', 64);
    if (!preg_match('/\A[A-Za-z0-9_-]{16,64}\z/', $deviceId)) {
        throw new InvalidArgumentException('deviceId must contain 16-64 letters, digits, underscores or hyphens.');
    }
    $revision = uploadId($body['revision'] ?? null, 'revision');
    $events = [];
    $eventIds = [];
    $sessionIds = [];
    foreach (uploadList($body['events'] ?? null, 'events', 5000) as $index => $inputEvent) {
        $event = uploadObject($inputEvent, [
            'id', 'name', 'date', 'location', 'organiser', 'type', 'status', 'amount',
            'description', 'contactEmail', 'contactPhone', 'sessions',
        ], "events[{$index}]");
        $id = uploadId($event['id'] ?? null, 'event.id');
        if (isset($eventIds[$id])) {
            throw new InvalidArgumentException('Event IDs must be unique within a device.');
        }
        $eventIds[$id] = true;
        $date = uploadText($event, 'date', 10);
        $parsedDate = preg_match('/\A[0-9]{2}\/[0-9]{2}\/[0-9]{4}\z/', $date)
            ? DateTimeImmutable::createFromFormat('!d/m/Y', $date) : false;
        if (!$parsedDate || $parsedDate->format('d/m/Y') !== $date || (int)$parsedDate->format('Y') < 1000) {
            throw new InvalidArgumentException('date must be a valid DD/MM/YYYY date with year 1000-9999.');
        }
        $amount = $event['amount'] ?? null;
        if ((!is_int($amount) && !is_float($amount)) || !is_finite((float)$amount) || $amount < 0 || $amount > 9999999999999.99) {
            throw new InvalidArgumentException('amount must be a non-negative number up to 9999999999999.99.');
        }
        $email = uploadText($event, 'contactEmail', 254, false);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('contactEmail must be a valid email address or empty.');
        }
        $phone = uploadText($event, 'contactPhone', 64, false);
        $digits = preg_replace('/\D/', '', $phone);
        if ($phone !== '' && (!preg_match('/\A\+?[0-9][0-9 ()-]*\z/', $phone) || strlen($digits) < 7 || strlen($digits) > 15)) {
            throw new InvalidArgumentException('contactPhone must contain 7-15 digits with optional +, spaces, hyphens or parentheses.');
        }
        $sessions = [];
        foreach (uploadList($event['sessions'] ?? null, 'sessions', 5000) as $sessionIndex => $inputSession) {
            $session = uploadObject($inputSession, ['id', 'eventId', 'name', 'time', 'type', 'speaker', 'room', 'description'], "sessions[{$sessionIndex}]");
            $sessionId = uploadId($session['id'] ?? null, 'session.id');
            if (isset($sessionIds[$sessionId])) {
                throw new InvalidArgumentException('Session IDs must be unique within a device.');
            }
            $sessionIds[$sessionId] = true;
            if (uploadId($session['eventId'] ?? null, 'session.eventId') !== $id) {
                throw new InvalidArgumentException('session.eventId must match its parent event ID.');
            }
            $time = uploadText($session, 'time', 5);
            if (!preg_match('/\A([01][0-9]|2[0-3]):[0-5][0-9]\z/', $time)) {
                throw new InvalidArgumentException('Session time must use HH:mm (00:00-23:59).');
            }
            $sessions[] = [
                'id' => $sessionId, 'eventId' => $id,
                'name' => uploadText($session, 'name', 255), 'time' => $time,
                'type' => uploadChoice($session, 'type', ['Workshop', 'Presentation', 'Panel Discussion', 'Networking', 'Keynote', 'Others']),
                'speaker' => uploadText($session, 'speaker', 255, false),
                'room' => uploadText($session, 'room', 255, false),
                'description' => uploadText($session, 'description', 60000, false),
            ];
        }
        usort($sessions, fn($a, $b) => $a['id'] <=> $b['id']);
        $events[] = [
            'id' => $id, 'name' => uploadText($event, 'name', 255), 'date' => $parsedDate->format('Y-m-d'),
            'location' => uploadText($event, 'location', 255), 'organiser' => uploadText($event, 'organiser', 255),
            'type' => uploadChoice($event, 'type', ['Conference', 'Workshop', 'Seminar', 'Social', 'Other']),
            'status' => uploadChoice($event, 'status', ['Upcoming', 'Completed', 'Cancelled']),
            'amount' => number_format((float)$amount, 2, '.', ''),
            'description' => uploadText($event, 'description', 60000, false),
            'contactEmail' => $email, 'contactPhone' => $phone, 'sessions' => $sessions,
        ];
    }
    usort($events, fn($a, $b) => $a['id'] <=> $b['id']);
    return ['deviceId' => $deviceId, 'revision' => $revision, 'events' => $events];
}

// One transaction stores a complete device snapshot, including local deletions.
function saveUpload(PDO $db, array $upload): array
{
    $device = $upload['deviceId'];
    $revision = $upload['revision'];
    $hash = hash('sha256', json_encode($upload['events'], JSON_THROW_ON_ERROR));
    $eventCount = count($upload['events']);
    $sessionCount = array_sum(array_map(fn($event) => count($event['sessions']), $upload['events']));
    $db->beginTransaction();
    try {
        // This atomic insert also locks an existing device until commit.
        $db->prepare('INSERT INTO cloud_devices (device_id) VALUES (?) ON DUPLICATE KEY UPDATE device_id = device_id')->execute([$device]);
        $query = $db->prepare('SELECT revision, payload_hash FROM cloud_devices WHERE device_id = ? FOR UPDATE');
        $query->execute([$device]);
        $current = $query->fetch();
        if ($revision < (int)$current['revision']) {
            throw new UploadConflict('A newer revision is already stored. Upload your latest local snapshot.');
        }
        $replayed = $revision === (int)$current['revision'];
        if ($replayed && !hash_equals($current['payload_hash'], $hash)) {
            throw new UploadConflict('This revision was already used for different data. Increment revision after a local change.');
        }
        if (!$replayed) {
            $db->prepare('DELETE FROM cloud_events WHERE device_id = ?')->execute([$device]);
            $insertEvent = $db->prepare('INSERT INTO cloud_events (device_id, local_id, name, date, location, organiser, type, status, amount, description, contact_email, contact_phone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $insertSession = $db->prepare('INSERT INTO cloud_sessions (device_id, local_id, event_local_id, name, time, type, speaker, room, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($upload['events'] as $event) {
                $insertEvent->execute([$device, $event['id'], $event['name'], $event['date'], $event['location'], $event['organiser'], $event['type'], $event['status'], $event['amount'], $event['description'], $event['contactEmail'], $event['contactPhone']]);
                foreach ($event['sessions'] as $session) {
                    $insertSession->execute([$device, $session['id'], $event['id'], $session['name'], $session['time'], $session['type'], $session['speaker'], $session['room'], $session['description']]);
                }
            }
            $db->prepare('UPDATE cloud_devices SET revision = ?, payload_hash = ?, synced_at = CURRENT_TIMESTAMP WHERE device_id = ?')->execute([$revision, $hash, $device]);
        }
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
    return ['success' => true, 'deviceId' => $device, 'revision' => $revision,
        'uploadedEvents' => $eventCount, 'uploadedSessions' => $sessionCount, 'replayed' => $replayed];
}
