<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../upload_service.php';

function sampleUpload(): stdClass
{
    return json_decode(file_get_contents(__DIR__ . '/../example-upload.json'), false, 64, JSON_THROW_ON_ERROR);
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function rejects(callable $change): void
{
    $input = sampleUpload();
    $change($input);
    try {
        validateUpload($input);
    } catch (InvalidArgumentException $exception) {
        return;
    }
    throw new RuntimeException('Expected invalid input to be rejected.');
}

$valid = validateUpload(sampleUpload());
check(count($valid['events']) === 1, 'Accept a full event and nested session.');
check($valid['events'][0]['contactEmail'] === 'alex@example.com', 'Preserve optional details.');
rejects(fn($v) => $v->events[0]->date = '31/02/2026');
rejects(fn($v) => $v->events[0]->date = "20/10/\0");
rejects(fn($v) => $v->events[0]->amount = -1);
rejects(fn($v) => $v->events[0]->id = '1');
rejects(fn($v) => $v->events[0]->name = ' ');
rejects(fn($v) => $v->events[0]->contactEmail = 'not an email');
rejects(fn($v) => $v->events[0]->sessions[0]->time = '24:00');
rejects(fn($v) => $v->events[0]->sessions[0]->eventId = 2);
rejects(fn($v) => $v->events[] = clone $v->events[0]);
rejects(fn($v) => $v->events[0]->sessions[] = clone $v->events[0]->sessions[0]);
rejects(fn($v) => $v->deviceId = '../device');
rejects(fn($v) => $v->revision = 0);
rejects(fn($v) => $v->events = new stdClass());
rejects(function ($v) { unset($v->events[0]->sessions); });
rejects(fn($v) => $v->events[0]->unexpected = true);
$empty = sampleUpload();
$empty->events = [];
check(validateUpload($empty)['events'] === [], 'Accept an explicitly empty snapshot.');
echo "Validation tests passed (18 cases).\n";
