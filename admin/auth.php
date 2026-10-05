<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/database.php';

function startAdminSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('muskan_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/admin',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
    if (!isset($_SESSION['created_at'])) {
        session_regenerate_id(true);
        $_SESSION['created_at'] = time();
    }
}

function adminIsAuthenticated(): bool
{
    startAdminSession();
    return isset($_SESSION['admin_id']) && (int) $_SESSION['admin_id'] > 0;
}

function requireAdmin(): void
{
    if (!adminIsAuthenticated()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['message' => 'Please sign in again.']);
        exit;
    }
}

function adminCsrfToken(): string
{
    startAdminSession();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return (string) $_SESSION['csrf'];
}

function verifyAdminCsrf(?string $token): void
{
    if (!$token || !hash_equals(adminCsrfToken(), $token)) {
        http_response_code(419);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['message' => 'Your session expired. Refresh and try again.']);
        exit;
    }
}

