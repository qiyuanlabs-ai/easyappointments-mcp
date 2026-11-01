<?php

namespace Tests\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * An in-memory stand-in for the Easy!Appointments REST API v1, shaped like the real
 * responses the MCP server reads. Tests use it through Http::fake().
 */
class FakeEasyAppointments
{
    public const BASE = 'http://ea.test/index.php/api/v1';

    public array $settings = [
        'default_timezone' => 'America/New_York',
        'require_phone_number' => '0',
        'require_address' => '0',
        'require_city' => '0',
        'require_zip_code' => '0',
    ];

    public array $services = [
        ['id' => 1, 'name' => 'Initial assessment', 'duration' => 60, 'price' => '95.00', 'currency' => 'USD', 'description' => null],
        ['id' => 2, 'name' => 'Sports massage', 'duration' => 30, 'price' => '55.00', 'currency' => 'USD', 'description' => null],
    ];

    public array $providers = [
        ['id' => 10, 'firstName' => 'Anna', 'lastName' => 'Kim', 'services' => [1, 2], 'timezone' => 'America/New_York'],
        ['id' => 11, 'firstName' => 'Marcus', 'lastName' => 'Reed', 'services' => [1], 'timezone' => 'America/New_York'],
    ];

    public array $customers = [
        1 => ['id' => 1, 'firstName' => 'Olivia', 'lastName' => 'Bennett', 'email' => 'olivia.bennett@example.test', 'phone' => '+1 555 0101'],
        2 => ['id' => 2, 'firstName' => 'Liam', 'lastName' => 'Carter', 'email' => 'liam.carter@example.test', 'phone' => '+1 555 0102'],
    ];

    public array $appointments = [];

    private int $nextId = 100;

    /** Open start times per "providerId|date". Days not listed get the default hours. */
    public array $open = [];

    /** When true, creating an appointment saves it but the answer never arrives (a timeout). */
    public bool $loseWriteAnswers = false;

    /** When set, every request fails with this HTTP status. */
    public ?int $failWith = null;

    public array $defaultHours = ['09:00', '10:00', '11:00', '13:00', '14:00', '15:00'];

    /** @var list<string> "METHOD /path" for every request received */
    public array $requests = [];

    public static function install(): self
    {
        $fake = new self;
        Http::fake(fn (Request $request) => $fake->handle($request));

        return $fake;
    }

    public function writes(): array
    {
        return array_values(array_filter($this->requests, fn ($r) => ! str_starts_with($r, 'GET ')));
    }

    public function handle(Request $request)
    {
        $url = parse_url($request->url());
        $path = substr($url['path'], strlen('/index.php/api/v1'));
        parse_str($url['query'] ?? '', $query);
        $method = $request->method();
        $this->requests[] = "$method $path";

        if ($this->failWith !== null) {
            return Http::response(['message' => 'Failure'], $this->failWith);
        }

        if (($request->header('Authorization')[0] ?? '') !== 'Bearer test-token') {
            return Http::response(['message' => 'Unauthorized'], 401);
        }

        return match (true) {
            $path === '/services' => Http::response($this->services),
            $path === '/providers' => Http::response($this->providers),
            $path === '/settings' => Http::response(collect($this->settings)->map(fn ($v, $k) => ['name' => $k, 'value' => $v])->values()->all()),
            str_starts_with($path, '/settings/') => Http::response(['name' => substr($path, 10), 'value' => $this->settings[substr($path, 10)] ?? null]),
            $path === '/availabilities' => Http::response($this->openTimes((int) $query['providerId'], (string) $query['date'])),
            $path === '/customers' && $method === 'GET' => Http::response($this->searchCustomers((string) ($query['q'] ?? ''))),
            $path === '/customers' && $method === 'POST' => $this->createCustomer($request->data()),
            (bool) preg_match('#^/customers/(\d+)$#', $path, $m) => isset($this->customers[(int) $m[1]])
                ? Http::response($this->customers[(int) $m[1]]) : Http::response(['message' => 'Not found'], 404),
            $path === '/appointments' && $method === 'GET' => Http::response($this->listAppointments($query)),
            $path === '/appointments' && $method === 'POST' => $this->createAppointment($request->data()),
            (bool) preg_match('#^/appointments/(\d+)$#', $path, $m) => $this->appointmentRoute($method, (int) $m[1], $request->data()),
            default => Http::response(['message' => 'Not found'], 404),
        };
    }

    public function addAppointment(array $attributes): array
    {
        $id = $this->nextId++;
        $service = collect($this->services)->firstWhere('id', $attributes['serviceId']);
        $start = new \DateTimeImmutable($attributes['start']);

        return $this->appointments[$id] = [
            'id' => $id,
            'start' => $start->format('Y-m-d H:i:s'),
            'end' => $attributes['end'] ?? $start->modify("+{$service['duration']} minutes")->format('Y-m-d H:i:s'),
            'serviceId' => $attributes['serviceId'],
            'providerId' => $attributes['providerId'],
            'customerId' => $attributes['customerId'],
            'notes' => $attributes['notes'] ?? null,
            'status' => 'Booked',
        ];
    }

    private function openTimes(int $providerId, string $date): array
    {
        $times = $this->open["$providerId|$date"] ?? $this->defaultHours;
        $taken = collect($this->appointments)
            ->filter(fn ($a) => $a['providerId'] === $providerId && str_starts_with($a['start'], $date))
            ->map(fn ($a) => substr($a['start'], 11, 5));

        return array_values(array_diff($times, $taken->all()));
    }

    private function searchCustomers(string $q): array
    {
        $q = mb_strtolower($q);

        return array_values(array_filter($this->customers, fn ($c) => str_contains(mb_strtolower($c['firstName'].' '.$c['lastName'].' '.$c['email'].' '.$c['phone']), $q)));
    }

    private function createCustomer(array $data)
    {
        $required = ['firstName', 'lastName', 'email'];

        if ($this->settings['require_phone_number'] === '1') {
            $required[] = 'phone';
        }

        foreach ($required as $field) {
            if (empty($data[$field])) {
                return Http::response(['message' => "Not all required fields are provided: Array\n(\n    [first_name] => {$data['firstName']}\n    [email] => {$data['email']}\n    [phone_number] => \n)\n"], 400);
            }
        }

        $id = max(array_keys($this->customers)) + 1;

        return Http::response($this->customers[$id] = ['id' => $id] + array_intersect_key($data, array_flip(['firstName', 'lastName', 'email', 'phone'])), 201);
    }

    private function listAppointments(array $query): array
    {
        return collect($this->appointments)
            ->filter(fn ($a) => ! isset($query['customerId']) || (int) $query['customerId'] === $a['customerId'])
            ->filter(fn ($a) => ! isset($query['providerId']) || (int) $query['providerId'] === $a['providerId'])
            ->filter(fn ($a) => ! isset($query['from']) || substr($a['start'], 0, 10) >= $query['from'])
            ->filter(fn ($a) => ! isset($query['till']) || substr($a['end'], 0, 10) <= $query['till'])
            ->sortBy('start')
            ->map(fn ($a) => $a + ['customer' => ['first_name' => $this->customers[$a['customerId']]['firstName'], 'last_name' => $this->customers[$a['customerId']]['lastName']]])
            ->values()->all();
    }

    /**
     * Like the real API (Appointments_api_v1::store in 1.6.1), this saves whatever it is given:
     * it never checks the slot is free, so double bookings are the MCP server's job to prevent.
     */
    private function createAppointment(array $data)
    {
        $appointment = $this->addAppointment($data);

        if ($this->loseWriteAnswers) {
            throw new ConnectionException('Operation timed out');
        }

        return Http::response($appointment, 201);
    }

    private function appointmentRoute(string $method, int $id, array $data)
    {
        if (! isset($this->appointments[$id])) {
            return Http::response(['message' => 'Not found'], 404);
        }

        if ($method === 'DELETE') {
            unset($this->appointments[$id]);

            return Http::response(null, 204);
        }

        if ($method === 'PUT') {
            $this->appointments[$id] = array_merge($this->appointments[$id], $data);
        }

        return Http::response($this->appointments[$id]);
    }
}
