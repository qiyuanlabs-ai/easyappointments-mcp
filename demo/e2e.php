<?php

/**
 * End-to-end check against a running server: OAuth sign-in exactly like an MCP client
 * (dynamic registration, PKCE, login, consent), then every tool, including a real booking,
 * a reschedule and a cancellation in the connected Easy!Appointments.
 *
 * Usage: php demo/e2e.php http://localhost:8000 frontdesk@demo.test demo-password-1 [read|front_desk]
 * Only point this at a demo instance: it books and cancels a real appointment.
 */
[$script, $base, $email, $password, $level] = $argv + [null, 'http://localhost:8000', '', '', 'front_desk'];
$jar = tempnam(sys_get_temp_dir(), 'e2e');
$failures = 0;

function http(string $method, string $url, array $opts = []): array
{
    global $jar;
    $ch = curl_init($url);
    $headers = $opts['headers'] ?? [];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if (isset($opts['json'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opts['json']));
        $headers[] = 'Content-Type: application/json';
    } elseif (isset($opts['form'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opts['form']));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $raw = curl_exec($ch);
    $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $head = substr($raw, 0, $size);
    preg_match('/^location:\s*(\S+)/mi', $head, $m);

    return ['status' => $status, 'body' => substr($raw, $size), 'location' => $m[1] ?? null, 'head' => $head];
}

function field(string $html, string $name): string
{
    preg_match('/name="'.preg_quote($name, '/').'" value="([^"]*)"/', $html, $m);

    return html_entity_decode($m[1] ?? '');
}

function check(bool $ok, string $what, ?array $result = null): void
{
    global $failures;
    echo ($ok ? '  ok   ' : '  FAIL ').$what."\n";
    if (! $ok && $result !== null) {
        echo '       '.substr((string) $result['text'], 0, 400)."\n";
    }
    $failures += $ok ? 0 : 1;
}

echo "OAuth\n";
$redirect = 'http://127.0.0.1:33418/callback';
$client = json_decode(http('POST', "$base/oauth/register", ['json' => ['client_name' => 'E2E test client', 'redirect_uris' => [$redirect]]])['body'], true);
check(isset($client['client_id']), 'dynamic client registration');

$verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
$challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
$authorize = "$base/oauth/authorize?".http_build_query([
    'client_id' => $client['client_id'], 'redirect_uri' => $redirect, 'response_type' => 'code',
    'scope' => 'mcp:use', 'state' => 'xyz', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
    'resource' => "$base/mcp",
]);
$r = http('GET', $authorize);
check($r['status'] === 302 && str_contains((string) $r['location'], '/login'), 'unauthenticated authorize redirects to login');

$login = http('GET', "$base/login");
$r = http('POST', "$base/login", ['form' => ['_token' => field($login['body'], '_token'), 'email' => $email, 'password' => $password]]);
check($r['status'] === 302 && str_contains((string) $r['location'], '/oauth/authorize'), 'login returns to the consent screen');

$consent = http('GET', $r['location']);
check(str_contains($consent['body'], 'What may it do?'), 'consent screen asks for an access level');
$r = http('POST', "$base/oauth/consent", ['form' => [
    '_token' => field($consent['body'], '_token'), 'auth_token' => field($consent['body'], 'auth_token'),
    'access_level' => $level,
]]);
parse_str((string) parse_url((string) $r['location'], PHP_URL_QUERY), $query);
check(isset($query['code']) && ($query['state'] ?? '') === 'xyz', 'consent redirects back with a code');

$token = json_decode(http('POST', "$base/oauth/token", ['form' => [
    'grant_type' => 'authorization_code', 'client_id' => $client['client_id'], 'redirect_uri' => $redirect,
    'code_verifier' => $verifier, 'code' => $query['code'] ?? '',
]])['body'], true);
check(isset($token['access_token'], $token['refresh_token']), 'code exchanged for access and refresh tokens');

$id = 0;
function mcp(string $method, array $params = []): array
{
    global $base, $token, $id;
    $r = http('POST', "$base/mcp", [
        'headers' => ['Authorization: Bearer '.$token['access_token'], 'Accept: application/json, text/event-stream', 'MCP-Protocol-Version: 2025-06-18'],
        'json' => ['jsonrpc' => '2.0', 'id' => ++$id, 'method' => $method, 'params' => (object) $params],
    ]);
    $body = $r['body'];
    if (str_starts_with(ltrim($body), 'event:') || str_starts_with(ltrim($body), 'data:')) {
        preg_match_all('/^data: (.*)$/m', $body, $m);
        $body = end($m[1]);
    }

    return json_decode($body, true) ?? ['raw' => $r['status'].' '.$body];
}

function tool(string $name, array $args = []): array
{
    $r = mcp('tools/call', ['name' => $name, 'arguments' => (object) $args]);
    $result = $r['result'] ?? [];

    return ['error' => (bool) ($result['isError'] ?? true), 'data' => $result['structuredContent'] ?? null, 'text' => $result['content'][0]['text'] ?? json_encode($r)];
}

echo "MCP\n";
$init = mcp('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'e2e', 'version' => '1']]);
check(($init['result']['serverInfo']['name'] ?? '') === 'Easy!Appointments', 'initialize (2025-06-18 client)');
$names = array_column(mcp('tools/list')['result']['tools'] ?? [], 'name');
check(count($names) === ($level === 'read' ? 4 : 7), 'tools/list shows '.count($names).' tools for '.$level.': '.implode(', ', $names));

$services = tool('list_services');
check(! $services['error'] && count($services['data']['services']) > 0, 'list_services: '.count($services['data']['services'] ?? []).' services, time zone '.($services['data']['timezone'] ?? '?'));
$service = collect_first($services['data']['services'], fn ($s) => count($s['providers']) > 1);

$slots = tool('find_open_slots', ['service_id' => $service['id'], 'days' => 5, 'date_from' => date('Y-m-d', strtotime('+2 days'))]);
check(! $slots['error'] && $slots['data']['total_open_times'] > 0, "find_open_slots for {$service['name']}: {$slots['data']['total_open_times']} open times");

$customers = tool('find_customer', ['query' => 'example.test']);
check(! $customers['error'] && $customers['data']['count'] > 0, "find_customer: {$customers['data']['count']} matches");

if ($level === 'read') {
    $denied = tool('book_appointment', ['service_id' => 1]);
    check($denied['error'], 'read-only connection cannot call book_appointment');
    exit($failures ? 1 : 0);
}

$slot = null;
foreach ($slots['data']['days'] as $day) {
    if ($day['slots'] !== []) {
        $slot = ['date' => $day['date']] + $day['slots'][intdiv(count($day['slots']), 2)];
        break;
    }
}
$booking = [
    'service_id' => $service['id'], 'provider_id' => $slot['provider_id'], 'start' => "{$slot['date']} {$slot['time']}",
    'new_customer' => ['first_name' => 'Taylor', 'last_name' => 'Test', 'email' => 'taylor.e2e+'.time().'@example.test'],
    'notes' => 'Booked by the end-to-end check',
];
$noPhone = tool('book_appointment', $booking);
check($noPhone['error'] && str_contains($noPhone['text'], 'new_customer.phone'), 'a new customer without the required phone number is caught at preview', $noPhone);
$booking['new_customer']['phone'] = '+1 555 0100';
$preview = tool('book_appointment', $booking);
check(! $preview['error'] && $preview['data']['status'] === 'preview', "book_appointment preview: {$booking['start']} with {$slot['provider']}");

$key = bin2hex(random_bytes(8));
$booked = tool('book_appointment', $booking + ['confirm' => true, 'idempotency_key' => $key]);
check(! $booked['error'] && $booked['data']['status'] === 'booked', 'book_appointment confirmed: appointment #'.($booked['data']['appointment']['id'] ?? '?'));
$replay = tool('book_appointment', $booking + ['confirm' => true, 'idempotency_key' => $key]);
check(! $replay['error'] && ($replay['data']['replayed'] ?? false) && $replay['data']['appointment']['id'] === $booked['data']['appointment']['id'], 'same idempotency_key replays instead of booking twice', $replay);
$again = tool('book_appointment', $booking + ['confirm' => true, 'idempotency_key' => bin2hex(random_bytes(8))]);
check($again['error'] && str_contains($again['text'], 'not open'), 'a second booking for the taken slot is refused', $again);

$appointmentId = $booked['data']['appointment']['id'];
$list = tool('list_appointments', ['customer_id' => $booked['data']['appointment']['customer_id']]);
check(! $list['error'] && in_array($appointmentId, array_column($list['data']['appointments'], 'id'), true), 'list_appointments finds the new booking', $list);

$move = tool('find_open_slots', ['service_id' => $service['id'], 'provider_id' => $slot['provider_id'], 'date_from' => date('Y-m-d', strtotime($slot['date'].' +1 day')), 'days' => 5]);
$newStart = null;
foreach ($move['data']['days'] as $day) {
    if ($day['slots'] !== []) {
        $newStart = "{$day['date']} {$day['slots'][0]['time']}";
        break;
    }
}
$reschedule = ['appointment_id' => $appointmentId, 'new_start' => $newStart];
$p = tool('reschedule_appointment', $reschedule);
check(! $p['error'] && $p['data']['status'] === 'preview', "reschedule_appointment preview to $newStart");
$r = tool('reschedule_appointment', $reschedule + ['confirm' => true, 'idempotency_key' => bin2hex(random_bytes(8))]);
check(! $r['error'] && ($r['data']['appointment']['start'] ?? '') === $newStart, 'reschedule_appointment confirmed', $r);

$p = tool('cancel_appointment', ['appointment_id' => $appointmentId, 'reason' => 'E2E clean-up']);
check(! $p['error'] && $p['data']['status'] === 'preview', 'cancel_appointment preview', $p);
$c = tool('cancel_appointment', ['appointment_id' => $appointmentId, 'reason' => 'E2E clean-up', 'confirm' => true, 'idempotency_key' => bin2hex(random_bytes(8))]);
check(! $c['error'] && ($c['data']['status'] ?? null) === 'cancelled', 'cancel_appointment confirmed', $c);
$gone = tool('list_appointments', ['customer_id' => $booked['data']['appointment']['customer_id']]);
check(! $gone['error'] && ! in_array($appointmentId, array_column($gone['data']['appointments'] ?? [], 'id'), true), 'the cancelled appointment is gone', $gone);

$missing = tool('book_appointment', ['service_id' => $service['id'], 'provider_id' => $slot['provider_id'], 'start' => '2020-01-01 09:00', 'customer_id' => 1]);
check($missing['error'] && str_contains($missing['text'], 'past'), 'a time in the past is refused with a clear message', $missing);

echo $failures ? "\n$failures check(s) failed\n" : "\nAll checks passed\n";
exit($failures ? 1 : 0);

function collect_first(array $items, callable $fn): ?array
{
    foreach ($items as $item) {
        if ($fn($item)) {
            return $item;
        }
    }

    return $items[0] ?? null;
}
