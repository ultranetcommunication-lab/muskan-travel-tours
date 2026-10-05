<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/database.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, ['message' => 'Use POST to submit a booking request.']);
}

enforceSearchRateLimit();
$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) {
    respond(400, ['message' => 'Invalid booking request.']);
}
if (trim((string) ($input['company'] ?? '')) !== '') {
    respond(200, ['reference' => 'received']);
}

$name = trim((string) ($input['name'] ?? ''));
$email = strtolower(trim((string) ($input['email'] ?? '')));
$phone = preg_replace('/[^0-9+\-() ]/', '', trim((string) ($input['phone'] ?? '')));
$message = trim((string) ($input['message'] ?? ''));
$offerId = trim((string) ($input['offer_id'] ?? ''));
$search = is_array($input['search'] ?? null) ? $input['search'] : [];

if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
    respond(422, ['message' => 'Please enter your full name.']);
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(422, ['message' => 'Please enter a valid email address.']);
}
if (mb_strlen($phone) < 7 || mb_strlen($phone) > 24) {
    respond(422, ['message' => 'Please enter a valid phone or WhatsApp number.']);
}
if (mb_strlen($message) > 1000 || !preg_match('/^off_[A-Za-z0-9]+$/', $offerId)) {
    respond(422, ['message' => 'This flight selection is not valid. Please search again.']);
}

// Re-fetch the selected offer so the dashboard never relies on browser-supplied pricing.
$providerResponse = duffelRequest('GET', '/air/offers/' . rawurlencode($offerId) . '?return_available_services=true');
$offer = normalizeOffer($providerResponse['data'] ?? []);
if (($offer['id'] ?? '') === '' || empty($offer['slices'])) {
    respond(409, ['message' => 'This fare is no longer available. Please search again.']);
}

$firstSlice = $offer['slices'][0];
$lastSlice = $offer['slices'][count($offer['slices']) - 1];
$origin = strtoupper((string) ($firstSlice['origin'] ?? $search['origin'] ?? ''));
$destination = strtoupper((string) ($firstSlice['destination'] ?? $search['destination'] ?? ''));
$departureDate = substr((string) ($firstSlice['departing_at'] ?? $search['departure_date'] ?? ''), 0, 10);
$returnDate = count($offer['slices']) > 1 ? substr((string) ($lastSlice['departing_at'] ?? ''), 0, 10) : null;
$adults = max(1, min(9, (int) ($search['adults'] ?? 1)));
$cabin = in_array(($search['cabin_class'] ?? ''), ['economy', 'premium_economy', 'business', 'first'], true)
    ? (string) $search['cabin_class'] : 'economy';
$now = muskanNow();
$reference = muskanReference();

try {
    $database = muskanDatabase();
    $database->beginTransaction();
    $statement = $database->prepare(<<<'SQL'
        INSERT INTO booking_requests (
            public_id, status, customer_name, email, phone, adults, origin, destination,
            departure_date, return_date, cabin_class, airline, flight_numbers, fare_amount,
            fare_currency, baggage, offer_id, offer_expires_at, itinerary_json,
            customer_message, source, provider_live, created_at, updated_at
        ) VALUES (
            :public_id, 'new', :customer_name, :email, :phone, :adults, :origin, :destination,
            :departure_date, :return_date, :cabin_class, :airline, :flight_numbers, :fare_amount,
            :fare_currency, :baggage, :offer_id, :offer_expires_at, :itinerary_json,
            :customer_message, 'website', :provider_live, :created_at, :updated_at
        )
    SQL);
    $statement->execute([
        ':public_id' => $reference,
        ':customer_name' => $name,
        ':email' => $email !== '' ? $email : null,
        ':phone' => $phone,
        ':adults' => $adults,
        ':origin' => $origin,
        ':destination' => $destination,
        ':departure_date' => $departureDate,
        ':return_date' => $returnDate,
        ':cabin_class' => $cabin,
        ':airline' => $offer['airline']['name'] ?? null,
        ':flight_numbers' => implode(', ', $offer['flight_numbers'] ?? []),
        ':fare_amount' => $offer['total_amount'] ?? null,
        ':fare_currency' => $offer['total_currency'] ?? null,
        ':baggage' => $offer['baggage'] ?? null,
        ':offer_id' => $offerId,
        ':offer_expires_at' => $offer['expires_at'] ?? null,
        ':itinerary_json' => json_encode($offer['slices'], JSON_UNESCAPED_SLASHES),
        ':customer_message' => $message !== '' ? $message : null,
        ':provider_live' => !empty($offer['live_mode']) ? 1 : 0,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
    $bookingId = (int) $database->lastInsertId();
    $event = $database->prepare('INSERT INTO booking_events (booking_id, event_type, event_data, created_at) VALUES (?, ?, ?, ?)');
    $event->execute([$bookingId, 'created', json_encode(['source' => 'website']), $now]);
    $database->commit();
} catch (Throwable $error) {
    if (isset($database) && $database->inTransaction()) {
        $database->rollBack();
    }
    error_log('Booking request storage error: ' . $error->getMessage());
    respond(500, ['message' => 'We could not save your request. Please contact Muskan directly.']);
}

respond(201, [
    'reference' => $reference,
    'message' => 'Your flight request has been sent to Muskan Travels.',
]);

