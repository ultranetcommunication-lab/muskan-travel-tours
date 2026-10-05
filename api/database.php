<?php
declare(strict_types=1);

function muskanPrivateDirectory(): string
{
    $home = trim((string) ($_SERVER['HOME'] ?? getenv('HOME')));
    if ($home === '') {
        $documentRoot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
        $home = $documentRoot !== '' ? dirname($documentRoot) : sys_get_temp_dir();
    }
    $directory = rtrim($home, '/') . '/.config/muskan';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to initialise private storage.');
    }
    @chmod($directory, 0700);
    return $directory;
}

function muskanDatabase(): PDO
{
    static $database = null;
    if ($database instanceof PDO) {
        return $database;
    }

    $path = muskanPrivateDirectory() . '/bookings.sqlite';
    $database = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $database->exec('PRAGMA journal_mode = WAL');
    $database->exec('PRAGMA busy_timeout = 5000');
    $database->exec('PRAGMA foreign_keys = ON');
    $database->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS admins (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            last_login_at TEXT
        );
        CREATE TABLE IF NOT EXISTS booking_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            public_id TEXT NOT NULL UNIQUE,
            status TEXT NOT NULL DEFAULT 'new',
            customer_name TEXT NOT NULL,
            email TEXT,
            phone TEXT NOT NULL,
            adults INTEGER NOT NULL DEFAULT 1,
            origin TEXT NOT NULL,
            destination TEXT NOT NULL,
            departure_date TEXT NOT NULL,
            return_date TEXT,
            cabin_class TEXT NOT NULL,
            airline TEXT,
            flight_numbers TEXT,
            fare_amount TEXT,
            fare_currency TEXT,
            baggage TEXT,
            offer_id TEXT,
            offer_expires_at TEXT,
            itinerary_json TEXT,
            customer_message TEXT,
            admin_notes TEXT,
            source TEXT NOT NULL DEFAULT 'website',
            provider_live INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_booking_status ON booking_requests(status, created_at DESC);
        CREATE INDEX IF NOT EXISTS idx_booking_customer ON booking_requests(phone, email);
        CREATE TABLE IF NOT EXISTS booking_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            booking_id INTEGER NOT NULL,
            event_type TEXT NOT NULL,
            event_data TEXT,
            created_at TEXT NOT NULL,
            FOREIGN KEY (booking_id) REFERENCES booking_requests(id) ON DELETE CASCADE
        );
    SQL);
    @chmod($path, 0600);
    return $database;
}

function muskanNow(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Kathmandu')))->format(DateTimeInterface::ATOM);
}

function muskanReference(): string
{
    return 'MUS-' . (new DateTimeImmutable('now', new DateTimeZone('Asia/Kathmandu')))->format('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

