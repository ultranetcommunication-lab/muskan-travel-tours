<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    respond(405, ['message' => 'Use GET to recheck a flight offer.']);
}

$id = trim((string) ($_GET['id'] ?? ''));
if (!preg_match('/^off_[A-Za-z0-9]+$/', $id)) {
    respond(422, ['message' => 'Invalid flight reference.']);
}

$response = duffelRequest('GET', '/air/offers/' . rawurlencode($id) . '?return_available_services=true');
respond(200, ['offer' => normalizeOffer($response['data'] ?? [])]);

