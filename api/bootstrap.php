<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

$requestOrigin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
$allowedOrigins = [
    'https://ultranetcommunication-lab.github.io',
    'https://muskan-travel-tours.sutamra.chatgpt.site',
];
if (in_array($requestOrigin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $requestOrigin);
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Accept');
    header('Vary: Origin');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    if (!in_array($requestOrigin, $allowedOrigins, true)) {
        http_response_code(403);
        echo json_encode(['message' => 'Origin not allowed.']);
        exit;
    }
    http_response_code(204);
    exit;
}

function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function duffelToken(): string
{
    $token = trim((string) getenv('DUFFEL_ACCESS_TOKEN'));
    if ($token !== '') {
        return $token;
    }

    $home = rtrim((string) ($_SERVER['HOME'] ?? getenv('HOME')), '/');
    $secretFile = $home . '/.config/muskan/duffel.php';
    if (is_file($secretFile)) {
        $config = require $secretFile;
        if (is_array($config) && isset($config['access_token'])) {
            return trim((string) $config['access_token']);
        }
    }

    respond(503, [
        'code' => 'PROVIDER_NOT_CONFIGURED',
        'message' => 'Live airline access is being activated. Please contact Muskan for current fares.',
    ]);
}

function duffelRequest(string $method, string $path, ?array $body = null): array
{
    if (!function_exists('curl_init')) {
        respond(500, ['message' => 'The flight service is not available on this server.']);
    }

    $curl = curl_init('https://api.duffel.com' . $path);
    $headers = [
        'Accept: application/json',
        'Accept-Encoding: gzip',
        'Content-Type: application/json',
        'Duffel-Version: v2',
        'Authorization: Bearer ' . duffelToken(),
        'User-Agent: MuskanTravel/1.0',
    ];
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 40,
    ]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
    }

    $raw = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($raw === false || $error !== '') {
        error_log('Duffel transport error: ' . $error);
        respond(502, ['message' => 'The airline network did not respond. Please try again.']);
    }

    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        error_log('Duffel returned invalid JSON with status ' . $status);
        respond(502, ['message' => 'The airline network returned an unexpected response.']);
    }
    if ($status < 200 || $status >= 300) {
        $providerMessage = $decoded['errors'][0]['message'] ?? 'Provider request failed';
        error_log('Duffel API error ' . $status . ': ' . $providerMessage);
        $publicMessage = $status === 401 || $status === 403
            ? 'Live airline access needs attention. Please contact Muskan for current fares.'
            : 'The airlines could not complete this request. Please check the route and dates, then try again.';
        respond($status >= 500 ? 502 : 422, ['message' => $publicMessage]);
    }

    return $decoded;
}

function durationMinutes(?string $duration): int
{
    if (!$duration) {
        return 0;
    }
    try {
        $interval = new DateInterval($duration);
        return ($interval->d * 1440) + ($interval->h * 60) + $interval->i;
    } catch (Throwable) {
        return 0;
    }
}

function normalizeOffer(array $offer): array
{
    $slices = [];
    $flightNumbers = [];
    $totalDuration = 0;
    $baggage = null;

    foreach (($offer['slices'] ?? []) as $slice) {
        $segments = $slice['segments'] ?? [];
        if (!$segments) {
            continue;
        }
        $first = $segments[0];
        $last = $segments[count($segments) - 1];
        $sliceMinutes = durationMinutes($slice['duration'] ?? null);
        $totalDuration += $sliceMinutes;

        foreach ($segments as $segment) {
            $carrierCode = $segment['operating_carrier']['iata_code'] ?? $segment['marketing_carrier']['iata_code'] ?? '';
            $number = $segment['operating_carrier_flight_number'] ?? $segment['marketing_carrier_flight_number'] ?? '';
            $label = trim($carrierCode . $number);
            if ($label !== '') {
                $flightNumbers[] = $label;
            }
            if ($baggage === null) {
                foreach (($segment['passengers'][0]['baggages'] ?? []) as $bag) {
                    if (($bag['type'] ?? '') === 'checked' && (int) ($bag['quantity'] ?? 0) > 0) {
                        $quantity = (int) $bag['quantity'];
                        $baggage = $quantity . ' checked bag' . ($quantity > 1 ? 's' : '');
                        break;
                    }
                }
            }
        }

        $slices[] = [
            'origin' => $first['origin']['iata_code'] ?? '',
            'destination' => $last['destination']['iata_code'] ?? '',
            'departing_at' => $first['departing_at'] ?? '',
            'arriving_at' => $last['arriving_at'] ?? '',
            'duration_minutes' => $sliceMinutes,
            'segments' => array_map(static fn(array $segment): array => [
                'origin' => $segment['origin']['iata_code'] ?? '',
                'destination' => $segment['destination']['iata_code'] ?? '',
                'departing_at' => $segment['departing_at'] ?? '',
                'arriving_at' => $segment['arriving_at'] ?? '',
                'carrier' => $segment['operating_carrier']['name'] ?? '',
            ], $segments),
        ];
    }

    return [
        'id' => $offer['id'] ?? '',
        'live_mode' => (bool) ($offer['live_mode'] ?? false),
        'expires_at' => $offer['expires_at'] ?? null,
        'total_amount' => $offer['total_amount'] ?? '0.00',
        'total_currency' => $offer['total_currency'] ?? 'USD',
        'total_duration_minutes' => $totalDuration,
        'airline' => [
            'name' => $offer['owner']['name'] ?? 'Airline',
            'iata_code' => $offer['owner']['iata_code'] ?? '',
        ],
        'flight_numbers' => array_values(array_unique($flightNumbers)),
        'baggage' => $baggage,
        'slices' => $slices,
    ];
}

function enforceSearchRateLimit(): void
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $file = sys_get_temp_dir() . '/muskan-flight-' . hash('sha256', $ip);
    $now = time();
    $window = 60;
    $limit = 8;
    $handle = fopen($file, 'c+');
    if ($handle === false) {
        return;
    }
    flock($handle, LOCK_EX);
    $content = stream_get_contents($handle);
    $attempts = array_values(array_filter(array_map('intval', explode(',', (string) $content)), static fn(int $time): bool => $time > $now - $window));
    if (count($attempts) >= $limit) {
        flock($handle, LOCK_UN);
        fclose($handle);
        respond(429, ['message' => 'Too many searches. Please wait a minute and try again.']);
    }
    $attempts[] = $now;
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, implode(',', $attempts));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
}
