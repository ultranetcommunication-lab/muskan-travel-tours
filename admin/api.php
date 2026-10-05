<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
requireAdmin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$database = muskanDatabase();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$validStatuses = ['new', 'contacted', 'quoted', 'confirmed', 'closed'];

if ($method === 'GET') {
    $status = trim((string) ($_GET['status'] ?? 'all'));
    $query = trim((string) ($_GET['q'] ?? ''));
    $conditions = [];
    $parameters = [];
    if ($status !== 'all' && in_array($status, $validStatuses, true)) {
        $conditions[] = 'status = ?';
        $parameters[] = $status;
    }
    if ($query !== '') {
        $conditions[] = '(public_id LIKE ? OR customer_name LIKE ? OR phone LIKE ? OR email LIKE ? OR origin LIKE ? OR destination LIKE ?)';
        $needle = '%' . $query . '%';
        array_push($parameters, $needle, $needle, $needle, $needle, $needle, $needle);
    }
    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
    $statement = $database->prepare("SELECT * FROM booking_requests $where ORDER BY CASE status WHEN 'new' THEN 0 WHEN 'contacted' THEN 1 WHEN 'quoted' THEN 2 WHEN 'confirmed' THEN 3 ELSE 4 END, created_at DESC LIMIT 250");
    $statement->execute($parameters);
    $requests = $statement->fetchAll();
    foreach ($requests as &$request) {
        $request['itinerary'] = json_decode((string) ($request['itinerary_json'] ?? '[]'), true) ?: [];
        unset($request['itinerary_json'], $request['id']);
    }
    unset($request);
    $stats = [];
    foreach ($database->query('SELECT status, COUNT(*) AS total FROM booking_requests GROUP BY status')->fetchAll() as $row) {
        $stats[$row['status']] = (int) $row['total'];
    }
    echo json_encode(['requests' => $requests, 'stats' => $stats, 'csrf' => adminCsrfToken()], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'POST') {
    $input = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($input)) {
        http_response_code(400); echo json_encode(['message' => 'Invalid request.']); exit;
    }
    verifyAdminCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    $reference = trim((string) ($input['reference'] ?? ''));
    $action = (string) ($input['action'] ?? '');
    $statement = $database->prepare('SELECT id, status FROM booking_requests WHERE public_id = ?');
    $statement->execute([$reference]);
    $booking = $statement->fetch();
    if (!$booking) {
        http_response_code(404); echo json_encode(['message' => 'Booking request not found.']); exit;
    }
    $now = muskanNow();
    if ($action === 'status') {
        $newStatus = (string) ($input['status'] ?? '');
        if (!in_array($newStatus, $validStatuses, true)) {
            http_response_code(422); echo json_encode(['message' => 'Invalid status.']); exit;
        }
        $database->prepare('UPDATE booking_requests SET status = ?, updated_at = ? WHERE id = ?')->execute([$newStatus, $now, $booking['id']]);
        $eventData = json_encode(['from' => $booking['status'], 'to' => $newStatus]);
        $database->prepare('INSERT INTO booking_events (booking_id, event_type, event_data, created_at) VALUES (?, ?, ?, ?)')->execute([$booking['id'], 'status_changed', $eventData, $now]);
    } elseif ($action === 'notes') {
        $notes = trim((string) ($input['notes'] ?? ''));
        if (mb_strlen($notes) > 5000) {
            http_response_code(422); echo json_encode(['message' => 'Notes are too long.']); exit;
        }
        $database->prepare('UPDATE booking_requests SET admin_notes = ?, updated_at = ? WHERE id = ?')->execute([$notes, $now, $booking['id']]);
        $database->prepare('INSERT INTO booking_events (booking_id, event_type, event_data, created_at) VALUES (?, ?, ?, ?)')->execute([$booking['id'], 'notes_updated', null, $now]);
    } else {
        http_response_code(422); echo json_encode(['message' => 'Unsupported action.']); exit;
    }
    echo json_encode(['ok' => true]);
    exit;
}

header('Allow: GET, POST');
http_response_code(405);
echo json_encode(['message' => 'Method not allowed.']);

