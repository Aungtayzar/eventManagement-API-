# Event upload API

PHP 8.1+ with PDO MySQL and MySQL 8.0+. No Composer packages required.
The API stores all the fields used by the Android app, including event sessions.

## Local setup

1. Start Apache and MySQL in Laragon.
2. Import `schema.sql` with phpMyAdmin, or run from this directory:

   ```powershell
   mysql -u root -e "source C:/laragon/www/eventmanagementapi/schema.sql"
   ```

   This creates the `eventmanagement` database if needed and adds `cloud_devices`,
   `cloud_events` and `cloud_sessions`. Existing tables are not modified.
3. `dbconnection.php` defaults to `127.0.0.1:3306`, database `eventmanagement`,
   user `root`, empty password (local Laragon development only). Override with
   `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` in the server environment.
   Do not put hosting passwords in source control. Restart Apache after configuring it.

## Check the service

`GET http://localhost/eventmanagementapi/health.php`

HTTP 200 means PHP can connect to MySQL and the required tables exist:

```json
{"success":true,"status":"ready"}
```

HTTP 503 means the service/database/schema is unavailable. The mobile app should
check network connectivity, then call this endpoint with a timeout before uploading.
A successful check does not guarantee that the subsequent upload will succeed;
keep local data and handle errors on both requests.

## Upload all events

`POST http://localhost/eventmanagementapi/uploadevent.php`

Header: `Content-Type: application/json`

The complete example request is in `example-upload.json`. To try it:

```powershell
curl.exe -i -H "Content-Type: application/json" --data-binary "@example-upload.json" http://localhost/eventmanagementapi/uploadevent.php
```

Top-level contract:

| Field | Type | Meaning |
| --- | --- | --- |
| `deviceId` | string | Stable, randomly generated ID saved on the device, 16-64 ASCII letters/digits/underscores/hyphens; a UUID works |
| `revision` | positive JSON integer | Persistent local snapshot revision; increase whenever any event or session changes |
| `events` | array | **All** local events, each with its complete `sessions` array |

Each event requires `id` (positive local integer), `name`, `date` (`DD/MM/YYYY`,
year 1000-9999), `location`, `organiser`, `type`, `status`, `amount` (non-negative
JSON number, stored to two decimal places), and `sessions` (array, empty if none).
Optional strings `description`, `contactEmail`, `contactPhone` default to empty.
Event types: `Conference`, `Workshop`, `Seminar`, `Social`, `Other`.
Statuses: `Upcoming`, `Completed`, `Cancelled`.

Each session requires `id` (positive local integer), `eventId` (parent event ID),
`name`, `time` (`HH:mm`, 24-hour), `type`. Optional strings `speaker`, `room`,
`description` default to empty. Session types: `Workshop`, `Presentation`,
`Panel Discussion`, `Networking`, `Keynote`, `Others`.

Text limits in UTF-8 bytes: name/location/organiser/speaker/room 255, description
60,000, email 254, phone 64. Email and phone are validated when non-empty. Unknown
fields and duplicate IDs are rejected. IDs for sessions are unique across a device,
matching SQLite's session primary key. Derived Android `sessionCount` is not uploaded.
Limit: 10 MiB per request, 5,000 events, 5,000 sessions per event; server/proxy body
limits must also allow the request. There is no silent truncation or partial success.

Successful response (HTTP 200):

```json
{"success":true,"deviceId":"7ef6df40-5b17-4df8-8c5c-a78a25cfe3c3","revision":1,"uploadedEvents":1,"uploadedSessions":1,"replayed":false}
```

## Synchronization rules

- The phone is the source of truth for its own records. Every upload is a complete
  snapshot. Never send a filtered search result or a single page as the snapshot.
- A newer revision atomically replaces the previous snapshot for that device.
  Updates and deleted events/sessions are therefore reflected in MySQL.
- An explicit `events: []` removes that device's cloud events and sessions.
- Repeating the same revision with identical normalized data returns 200 with
  `replayed: true`. This is safe after a timeout or a lost response.
- An older revision, or the same revision with different data, returns HTTP 409.
- A transaction and per-device lock prevent partial writes and concurrent
  requests from overwriting newer revisions. Other devices are unaffected.
- On the mobile side, read the revision, events and sessions together in a SQLite
  transaction. Persist revisions alongside local changes. Retry an unchanged
  snapshot with its original revision. If data changes during upload, send another
  snapshot with a higher revision after the current request completes.
- Do not reset the revision while reusing a device ID. A fresh installation should
  generate a new device ID. This API does not download or merge cloud-side edits.

Error response format:

```json
{"success":false,"error":{"code":"VALIDATION_ERROR","message":"date must be a valid DD/MM/YYYY date with year 1000-9999."}}
```

| HTTP status | Meaning |
| --- | --- |
| 400 | Invalid JSON/body |
| 405 | Wrong HTTP method; see `Allow` header |
| 409 | Revision conflict; upload the latest local revision |
| 413 | Request exceeds 10 MiB |
| 415 | Content-Type must be application/json |
| 422 | Invalid event/session data; nothing written |
| 503 | Database/service error; retry the same snapshot later |

## Android and hosting

The Android emulator reaches the PC at `http://10.0.2.2/eventmanagementapi/`.
A physical device uses the PC's LAN IP on the same network. `localhost` on a phone
refers to the phone itself. Local Laragon is a development server, not cloud hosting.

For a cloud service, deploy the PHP files and schema to a PHP/MySQL host, configure
database credentials there, and use its HTTPS URL. This local prototype has no
authentication: `deviceId` separates records but is **not** an authorization credential.
Before public deployment, add authentication and bind each device to an authorized
user, since event data contains contact information. Do not expose an unauthenticated
deployment to the internet. No CORS configuration is needed for a native Android client.

## Tests

From this directory:

```powershell
php tests/validation_test.php
php tests/database_test.php
php tests/http_test.php
```

The database test uses two random test device IDs and deletes only those records
in `finally`. Run it against a development database with `schema.sql` installed.
The HTTP test requires Apache to be running, uploads two events using a random test
device ID and cleans up afterward. Set `API_TEST_URL` to override the local API URL.

Implementation references: [PDO prepared statements](https://www.php.net/manual/en/pdo.prepare.php)
and [MySQL locking reads](https://dev.mysql.com/doc/refman/8.0/en/innodb-locking-reads.html).
