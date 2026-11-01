<?php

/**
 * Seeds a fresh Easy!Appointments instance with a fictional physio clinic for demos.
 *
 * Usage: php demo/seed-ea.php http://localhost:8080 administrator administrator
 *    or: EA_API_TOKEN=... php demo/seed-ea.php http://localhost:8080   (uses the API token instead)
 *
 * Everything here is made up: names, emails (example.test) and phone numbers (555).
 * It deletes the sample service, provider and customer that "console install" creates.
 */
[$script, $baseUrl, $user, $pass] = $argv + [null, 'http://localhost:8080', 'administrator', 'administrator'];
$api = rtrim($baseUrl, '/').'/index.php/api/v1';
mt_srand(20261101);

function call(string $method, string $path, ?array $body = null): mixed
{
    global $api, $user, $pass;
    $ch = curl_init($api.$path);
    if (! getenv('EA_API_TOKEN')) {
        curl_setopt($ch, CURLOPT_USERPWD, "$user:$pass");
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_merge(
            ['Content-Type: application/json', 'Accept: application/json'],
            getenv('EA_API_TOKEN') ? ['Authorization: Bearer '.getenv('EA_API_TOKEN')] : [],
        ),
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($status === 429) {
        // Easy!Appointments allows 100 requests per IP every 120 seconds by default.
        fwrite(STDERR, "Rate limited by Easy!Appointments, waiting 30s...\n");
        sleep(30);

        return call($method, $path, $body);
    }
    if ($status >= 400) {
        fwrite(STDERR, "$method $path -> $status $raw\n");
        exit(1);
    }

    return $raw === '' ? null : json_decode($raw, true);
}

$settings = [
    'company_name' => 'Maple Street Physio (demo)',
    'company_email' => 'frontdesk@maple-street-physio.example.test',
    'company_link' => 'https://example.test',
    'default_timezone' => 'America/New_York',
    'book_advance_timeout' => '60',
    'future_booking_limit' => '60',
];
foreach ($settings as $name => $value) {
    call('PUT', "/settings/$name", ['name' => $name, 'value' => $value]);
}

foreach (call('GET', '/appointments?length=500') as $a) {
    call('DELETE', "/appointments/{$a['id']}");
}
foreach (['/customers', '/providers', '/services'] as $path) {
    foreach (call('GET', "$path?length=500") as $row) {
        call('DELETE', "$path/{$row['id']}");
    }
}

$services = [];
foreach ([
    ['Initial assessment', 60, 95, 'First visit: history, movement tests and a treatment plan.'],
    ['Follow-up treatment', 45, 75, 'Hands-on treatment and exercise review.'],
    ['Sports massage', 30, 55, 'Deep tissue massage for training recovery.'],
    ['Video consultation', 30, 45, 'Remote check-in and exercise coaching by video.'],
] as [$name, $minutes, $price, $description]) {
    $services[$name] = call('POST', '/services', [
        'name' => $name, 'duration' => $minutes, 'price' => $price, 'currency' => 'USD',
        'description' => $description, 'slotInterval' => 15, 'attendantsNumber' => 1,
    ])['id'];
}

$day = fn (?string $start, ?string $end) => $start === null ? null : [
    'start' => $start, 'end' => $end, 'breaks' => [['start' => '12:30', 'end' => '13:15']],
];
$weekdays = fn (array $hours, array $off = []) => array_combine(
    ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
    array_map(fn ($d) => in_array($d, $off, true) ? null : $day(...$hours),
        ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']),
);

$providers = [];
foreach ([
    ['Anna', 'Kim', ['08:00', '16:00'], ['saturday', 'sunday'], ['Initial assessment', 'Follow-up treatment', 'Video consultation']],
    ['Marcus', 'Reed', ['10:00', '18:30'], ['saturday', 'sunday'], ['Initial assessment', 'Follow-up treatment', 'Sports massage']],
    ['Priya', 'Shah', ['09:00', '17:00'], ['sunday', 'monday'], ['Sports massage', 'Follow-up treatment', 'Video consultation']],
] as $i => [$first, $last, $hours, $off, $serviceNames]) {
    $providers[] = call('POST', '/providers', [
        'firstName' => $first, 'lastName' => $last,
        'email' => strtolower($first).'@maple-street-physio.example.test',
        'phone' => '+1 555 0100 '.($i + 1), 'timezone' => 'America/New_York',
        'services' => array_map(fn ($n) => $services[$n], $serviceNames),
        'settings' => [
            'username' => strtolower($first), 'password' => bin2hex(random_bytes(8)),
            'notifications' => false, 'calendarView' => 'default',
            'workingPlan' => $weekdays($hours, $off), 'workingPlanExceptions' => new stdClass,
        ],
    ]);
}

$customers = [];
foreach ([
    ['Olivia', 'Bennett'], ['Liam', 'Carter'], ['Emma', 'Diaz'], ['Noah', 'Evans'],
    ['Ava', 'Foster'], ['Ethan', 'Garcia'], ['Mia', 'Hughes'], ['Lucas', 'Iverson'],
    ['Sofia', 'Jensen'], ['Mason', 'Klein'], ['Chloe', 'Lopez'], ['James', 'Morgan'],
] as $i => [$first, $last]) {
    $customers[] = call('POST', '/customers', [
        'firstName' => $first, 'lastName' => $last,
        'email' => strtolower("$first.$last").'@example.test',
        'phone' => sprintf('+1 555 01%02d', 10 + $i), 'timezone' => 'America/New_York',
    ]);
}

// Fill roughly a third of the open slots over the next 14 days so the calendar looks real.
$created = 0;
$tz = new DateTimeZone('America/New_York');
for ($d = 1; $d <= 14; $d++) {
    $date = (new DateTimeImmutable("+$d days", $tz))->format('Y-m-d');
    foreach ($providers as $provider) {
        $serviceId = $provider['services'][array_rand($provider['services'])];
        $hours = call('GET', "/availabilities?providerId={$provider['id']}&serviceId=$serviceId&date=$date");
        $picked = 0;
        foreach ($hours as $hour) {
            if ($picked >= 3 || mt_rand(1, 4) !== 1) {
                continue;
            }
            $customer = $customers[array_rand($customers)];
            call('POST', '/appointments', [
                'start' => "$date $hour:00", 'customerId' => $customer['id'],
                'providerId' => $provider['id'], 'serviceId' => $serviceId,
                'notes' => 'Seeded demo appointment',
            ]);
            $picked++;
            $created++;
        }
    }
}

echo "Seeded 4 services, 3 providers, 12 customers, $created appointments.\n";
