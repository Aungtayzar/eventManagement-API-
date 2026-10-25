-- Import once in phpMyAdmin or MySQL before using the API.
-- Dedicated cloud_* tables leave any existing application tables untouched.
CREATE DATABASE IF NOT EXISTS eventmanagement CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE eventmanagement;

CREATE TABLE IF NOT EXISTS cloud_devices (
    device_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    revision BIGINT NOT NULL DEFAULT 0,
    payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
    synced_at TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cloud_events (
    device_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    local_id BIGINT NOT NULL,
    name VARCHAR(255) NOT NULL,
    date DATE NOT NULL,
    location VARCHAR(255) NOT NULL,
    organiser VARCHAR(255) NOT NULL,
    type VARCHAR(32) NOT NULL,
    status VARCHAR(32) NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    description TEXT NOT NULL,
    contact_email VARCHAR(254) NOT NULL,
    contact_phone VARCHAR(64) NOT NULL,
    PRIMARY KEY (device_id, local_id),
    CONSTRAINT cloud_events_device_fk FOREIGN KEY (device_id)
        REFERENCES cloud_devices(device_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cloud_sessions (
    device_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    local_id BIGINT NOT NULL,
    event_local_id BIGINT NOT NULL,
    name VARCHAR(255) NOT NULL,
    time CHAR(5) NOT NULL,
    type VARCHAR(32) NOT NULL,
    speaker VARCHAR(255) NOT NULL,
    room VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    PRIMARY KEY (device_id, local_id),
    CONSTRAINT cloud_sessions_event_fk FOREIGN KEY (device_id, event_local_id)
        REFERENCES cloud_events(device_id, local_id) ON DELETE CASCADE
) ENGINE=InnoDB;
