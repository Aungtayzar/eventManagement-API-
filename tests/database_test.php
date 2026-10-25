<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../dbconnection.php';
require_once __DIR__ . '/../upload_service.php';

function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$db = databaseConnection();
$device = 'test-' . bin2hex(random_bytes(16));
$otherDevice = 'test-' . bin2hex(random_bytes(16));
$input = json_decode(file_get_contents(__DIR__ . '/../example-upload.json'), false, 64, JSON_THROW_ON_ERROR);
$input->deviceId = $device;
$select = $db->prepare('SELECT * FROM cloud_events WHERE device_id = ?');
$countSessions = $db->prepare('SELECT COUNT(*) FROM cloud_sessions WHERE device_id = ?');
$selectRevision = $db->prepare('SELECT revision FROM cloud_devices WHERE device_id = ?');
try {
    $upload = validateUpload($input);
    $result = saveUpload($db, $upload);
    verify($result['uploadedEvents'] === 1 && $result['uploadedSessions'] === 1, 'Upload counts match.');
    $select->execute([$device]);
    $stored = $select->fetchAll();
    verify(count($stored) === 1 && $stored[0]['contact_email'] === 'alex@example.com', 'Full event persisted.');
    verify(saveUpload($db, $upload)['replayed'] === true, 'An identical retry is accepted.');
    $select->execute([$device]);
    verify(count($select->fetchAll()) === 1, 'A retry does not duplicate events.');

    $other = $upload;
    $other['deviceId'] = $otherDevice;
    saveUpload($db, $other);

    $input->revision = 2;
    $input->events[0]->description = "Updated from mobile ' OR 1=1 -- မြန်မာ";
    $input->events[0]->sessions = [];
    $updated = validateUpload($input);
    saveUpload($db, $updated);
    $select->execute([$device]);
    verify($select->fetch()['description'] === $input->events[0]->description, 'Edits, Unicode and SQL-like text are stored safely.');
    $countSessions->execute([$device]);
    verify((int)$countSessions->fetchColumn() === 0, 'Deleted sessions are removed.');

    foreach ([$upload, array_replace($upload, ['revision' => 2])] as $conflict) {
        try {
            saveUpload($db, $conflict);
            throw new RuntimeException('Expected a stale or reused revision conflict.');
        } catch (UploadConflict $expected) {
            // Neither an older revision nor a reused revision can replace data.
        }
    }

    // Force a DB constraint failure after the old snapshot has been deleted.
    // The whole transaction must roll back, including its revision.
    $broken = $updated;
    $broken['revision'] = 3;
    $broken['events'][] = $broken['events'][0];
    try {
        saveUpload($db, $broken);
        throw new RuntimeException('Expected duplicate key failure.');
    } catch (PDOException $expected) {
        $select->execute([$device]);
        $rows = $select->fetchAll();
        verify(count($rows) === 1 && $rows[0]['description'] === $input->events[0]->description, 'Failed batch restores the old snapshot.');
        $selectRevision->execute([$device]);
        verify((int)$selectRevision->fetchColumn() === 2, 'Failed batch does not advance revision.');
    }

    $input->revision = 3;
    $input->events = [];
    saveUpload($db, validateUpload($input));
    $select->execute([$device]);
    verify($select->fetchAll() === [], 'An empty snapshot synchronizes deletion of all events.');
    $select->execute([$otherDevice]);
    verify(count($select->fetchAll()) === 1, 'Other devices are unaffected.');
    echo "Database tests passed: upload, retry, edit, session deletion, revision conflicts, rollback, empty snapshot and device isolation.\n";
} finally {
    $cleanup = $db->prepare('DELETE FROM cloud_devices WHERE device_id IN (?, ?)');
    $cleanup->execute([$device, $otherDevice]);
}
