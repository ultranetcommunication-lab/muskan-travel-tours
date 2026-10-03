<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, ['message' => 'Use POST to search for flights.']);
}

enforceSearchRateLimit();
$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) {
    respond(400, ['message' => 'Invalid search request.']);
}

$origin = strtoupper(trim((string) ($input['origin'] ?? '')));
$destination = strtoupper(trim((string) ($input['destination'] ?? '')));
$departureDate = trim((string) ($input['departure_date'] ?? ''));
$returnDate = isset($input['return_date']) ? trim((string) $input['return_date']) : '';
$adults = (int) ($input['adults'] ?? 1);
$cabin = (string) ($input['cabin_class'] ?? 'economy');
$allowedCabins = ['economy', 'premium_economy', 'business', 'first'];

if (!preg_match('/^[A-Z]{3}$/', $origin) || !preg_match('/^[A-Z]{3}$/', $destination) || $origin === $destination) {
    respond(422, ['message' => 'Please enter two different valid three-letter airport codes.']);
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $departureDate) || $departureDate < gmdate('Y-m-d')) {
    respond(422, ['message' => 'Please choose a valid future departure date.']);
}
if ($returnDate !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $returnDate) || $returnDate < $departureDate)) {
    respond(422, ['message' => 'The return date must be on or after departure.']);
}
if ($adults < 1 || $adults > 9 || !in_array($cabin, $allowedCabins, true)) {
    respond(422, ['message' => 'Please check the traveller and cabin selections.']);
}

$slices = [[
    'origin' => $origin,
    'destination' => $destination,
    'departure_date' => $departureDate,
]];
if ($returnDate !== '') {
    $slices[] = [
        'origin' => $destination,
        'destination' => $origin,
        'departure_date' => $returnDate,
    ];
}

$passengers = array_fill(0, $adults, ['type' => 'adult']);
$response = duffelRequest('POST', '/air/offer_requests?return_offers=true&supplier_timeout=15000', [
    'data' => [
        'slices' => $slices,
        'passengers' => $passengers,
        'cabin_class' => $cabin,
        'max_connections' => 2,
    ],
]);

$offers = array_map('normalizeOffer', $response['data']['offers'] ?? []);
$offers = array_values(array_filter($offers, static fn(array $offer): bool => count($offer['slices']) > 0));
usort($offers, static fn(array $a, array $b): int => (float) $a['total_amount'] <=> (float) $b['total_amount']);
$offers = array_slice($offers, 0, 30);

respond(200, [
    'live_mode' => (bool) ($response['data']['live_mode'] ?? ($offers[0]['live_mode'] ?? false)),
    'offer_request_id' => $response['data']['id'] ?? null,
    'offers' => $offers,
]);

