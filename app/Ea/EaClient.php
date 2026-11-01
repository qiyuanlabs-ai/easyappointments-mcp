<?php

namespace App\Ea;

use App\Models\EaConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * A small, typed client for the Easy!Appointments REST API v1.
 *
 * It returns plain arrays shaped for the MCP tools (camelCase from the API is mapped
 * to what the tools need) and turns every failure into an EaApiException.
 */
class EaClient
{
    public function __construct(
        private readonly EaConnection $connection,
        private readonly EaRequestLog $log,
    ) {}

    /** @return list<array{id: int, name: string, duration: int, price: float, currency: string, description: string|null}> */
    public function services(): array
    {
        return $this->remember('services', config('easyappointments.catalog_cache_seconds'), function () {
            return collect($this->get('/services', ['length' => 500]))
                ->map(fn (array $s) => [
                    'id' => (int) $s['id'],
                    'name' => $s['name'],
                    'duration' => (int) $s['duration'],
                    'price' => (float) $s['price'],
                    'currency' => (string) ($s['currency'] ?? ''),
                    'description' => $s['description'] ?? null,
                ])->values()->all();
        });
    }

    /** @return list<array{id: int, name: string, services: list<int>}> */
    public function providers(): array
    {
        return $this->remember('providers', config('easyappointments.catalog_cache_seconds'), function () {
            return collect($this->get('/providers', ['length' => 500, 'fields' => 'id,firstName,lastName,services']))
                ->map(fn (array $p) => [
                    'id' => (int) $p['id'],
                    'name' => trim($p['firstName'].' '.$p['lastName']),
                    'services' => array_map('intval', $p['services'] ?? []),
                ])->values()->all();
        });
    }

    /** The instance's time zone. All appointment times in Easy!Appointments are in this zone. */
    public function timezone(): string
    {
        return $this->remember('timezone', config('easyappointments.catalog_cache_seconds'), function () {
            try {
                $value = $this->get('/settings/default_timezone')['value'] ?? null;
            } catch (EaApiException) {
                $value = null;
            }

            return is_string($value) && in_array($value, timezone_identifiers_list(), true) ? $value : 'UTC';
        });
    }

    /**
     * Customer fields this instance insists on (its "require_*" settings), as new_customer keys.
     *
     * @return list<string>
     */
    public function requiredCustomerFields(): array
    {
        return $this->remember('required_customer_fields', config('easyappointments.catalog_cache_seconds'), function () {
            $map = ['require_phone_number' => 'phone', 'require_address' => 'address', 'require_city' => 'city', 'require_zip_code' => 'zip_code'];

            try {
                $settings = collect($this->get('/settings', ['length' => 500]))->pluck('value', 'name');
            } catch (EaApiException) {
                return [];
            }

            return collect($map)->filter(fn ($field, $setting) => (string) $settings->get($setting) === '1')->values()->all();
        });
    }

    public function service(int $id): ?array
    {
        return collect($this->services())->firstWhere('id', $id);
    }

    public function provider(int $id): ?array
    {
        return collect($this->providers())->firstWhere('id', $id);
    }

    /**
     * Open start times ("H:i") for one provider, one service, one day.
     *
     * @return list<string>
     */
    public function availability(int $providerId, int $serviceId, string $date): array
    {
        return $this->availabilityMany([[$providerId, $serviceId, $date]])[0];
    }

    /**
     * Open start times for several provider/service/day combinations, fetched in parallel.
     * Results are cached briefly because Easy!Appointments rate limits per IP.
     *
     * @param  list<array{0: int, 1: int, 2: string}>  $lookups
     * @return list<list<string>>
     */
    public function availabilityMany(array $lookups): array
    {
        $results = [];
        $missing = [];

        foreach ($lookups as $i => [$providerId, $serviceId, $date]) {
            $cached = Cache::get($this->availabilityKey($providerId, $serviceId, $date));
            is_array($cached) ? $results[$i] = $cached : $missing[$i] = [$providerId, $serviceId, $date];
        }

        if ($missing !== []) {
            $started = hrtime(true);

            try {
                $responses = Http::pool(fn (Pool $pool) => collect($missing)->map(
                    fn ($lookup, $i) => $this->configure($pool->as((string) $i))->get($this->connection->apiUrl().'/availabilities', [
                        'providerId' => $lookup[0], 'serviceId' => $lookup[1], 'date' => $lookup[2],
                    ])
                )->all());
            } catch (ConnectionException) {
                throw new EaApiException('Could not reach Easy!Appointments. Check that the instance is online.');
            }

            $ms = (int) ((hrtime(true) - $started) / 1e6);

            foreach ($missing as $i => [$providerId, $serviceId, $date]) {
                $response = $responses[(string) $i] ?? null;

                if (! $response instanceof Response) {
                    $this->log->add('GET', '/availabilities', null, $ms);
                    throw new EaApiException('Could not reach Easy!Appointments. Check that the instance is online.');
                }

                $this->log->add('GET', "/availabilities?providerId=$providerId&serviceId=$serviceId&date=$date", $response->status(), $ms);

                if ($response->failed()) {
                    throw EaApiException::fromStatus($response->status(), 'GET', '/availabilities');
                }

                $hours = array_values(array_filter((array) $response->json(), 'is_string'));
                Cache::put($this->availabilityKey($providerId, $serviceId, $date), $hours, config('easyappointments.availability_cache_seconds'));
                $results[$i] = $hours;
            }
        }

        ksort($results);

        return array_values($results);
    }

    /** Drop cached slots for a provider on a day after a booking changes them. */
    public function forgetAvailability(int $providerId, string $date): void
    {
        foreach ($this->services() as $service) {
            Cache::forget($this->availabilityKey($providerId, $service['id'], $date));
        }
    }

    /** @return list<array> */
    public function searchCustomers(string $query, int $limit = 10): array
    {
        return array_map(
            fn (array $c) => $this->mapCustomer($c),
            $this->get('/customers', ['q' => $query, 'length' => $limit, 'sort' => '+lastName']),
        );
    }

    public function customer(int $id): array
    {
        return $this->mapCustomer($this->get("/customers/$id"));
    }

    public function createCustomer(array $customer): array
    {
        return $this->mapCustomer($this->send('POST', '/customers', [
            'firstName' => $customer['first_name'],
            'lastName' => $customer['last_name'],
            'email' => $customer['email'],
            'phone' => $customer['phone'] ?? null,
            'address' => $customer['address'] ?? null,
            'city' => $customer['city'] ?? null,
            'zip' => $customer['zip_code'] ?? null,
            'timezone' => $this->timezone(),
        ]));
    }

    /**
     * @param  array{customerId?: int, providerId?: int, serviceId?: int, from?: string, till?: string}  $filters
     * @return list<array>
     */
    public function appointments(array $filters, int $limit = 50): array
    {
        $query = array_filter($filters, fn ($v) => $v !== null) + ['length' => $limit, 'sort' => '+start', 'with' => 'customer'];

        return array_map(fn (array $a) => $this->mapAppointment($a), $this->get('/appointments', $query));
    }

    public function appointment(int $id): array
    {
        return $this->mapAppointment($this->get("/appointments/$id"));
    }

    public function createAppointment(array $data): array
    {
        return $this->mapAppointment($this->send('POST', '/appointments', $data));
    }

    public function updateAppointment(int $id, array $data): array
    {
        return $this->mapAppointment($this->send('PUT', "/appointments/$id", $data));
    }

    public function deleteAppointment(int $id): void
    {
        $this->send('DELETE', "/appointments/$id");
    }

    private function mapCustomer(array $c): array
    {
        return [
            'id' => (int) $c['id'],
            'name' => trim(($c['firstName'] ?? '').' '.($c['lastName'] ?? '')),
            'email' => $c['email'] ?? null,
            'phone' => $c['phone'] ?? ($c['mobile'] ?? null),
        ];
    }

    private function mapAppointment(array $a): array
    {
        $service = $this->service((int) $a['serviceId']);
        $provider = $this->provider((int) $a['providerId']);

        return [
            'id' => (int) $a['id'],
            'start' => substr((string) $a['start'], 0, 16),
            'end' => substr((string) $a['end'], 0, 16),
            'service_id' => (int) $a['serviceId'],
            'service' => $service['name'] ?? null,
            'provider_id' => (int) $a['providerId'],
            'provider' => $provider['name'] ?? null,
            'customer_id' => $a['customerId'] !== null ? (int) $a['customerId'] : null,
            'customer' => isset($a['customer']['first_name']) ? trim($a['customer']['first_name'].' '.$a['customer']['last_name']) : null,
            'notes' => $a['notes'] ?? null,
            'status' => ($a['status'] ?? '') !== '' ? $a['status'] : null,
        ];
    }

    private function get(string $path, array $query = []): array
    {
        $result = $this->send('GET', $path, $query);

        return is_array($result) ? $result : [];
    }

    private function send(string $method, string $path, array $payload = []): mixed
    {
        $started = hrtime(true);
        $request = $this->configure(Http::baseUrl($this->connection->apiUrl()));

        try {
            $response = match ($method) {
                'GET' => $request->get($path, $payload),
                'POST' => $request->post($path, $payload),
                'PUT' => $request->put($path, $payload),
                'DELETE' => $request->delete($path),
            };
        } catch (ConnectionException) {
            $this->log->add($method, $path, null, (int) ((hrtime(true) - $started) / 1e6));
            throw new EaApiException('Could not reach Easy!Appointments. Check that the instance is online.');
        }

        $this->log->add($method, $path, $response->status(), (int) ((hrtime(true) - $started) / 1e6));

        if ($response->failed()) {
            $message = $response->json('message');

            if ($response->status() === 400) {
                throw new EaApiException('Easy!Appointments did not accept the request: '.self::cleanMessage($message).'.', $response->status());
            }

            throw EaApiException::fromStatus($response->status(), $method, $path);
        }

        return $response->status() === 204 ? null : $response->json();
    }

    /**
     * Easy!Appointments sometimes appends a print_r() dump of the submitted record to its
     * validation messages. That dump holds customer details, so it never reaches the model
     * or the call log; only the names of the empty fields are kept.
     */
    public static function cleanMessage(mixed $message): string
    {
        if (! is_string($message) || trim($message) === '') {
            return 'invalid data';
        }

        $position = strpos($message, 'Array');

        if ($position === false) {
            return rtrim(trim(mb_substr($message, 0, 300)), '.');
        }

        preg_match_all('/\[(\w+)\] => *$/m', substr($message, $position), $empty);
        $head = rtrim(trim(substr($message, 0, $position)), ':. ');

        return $empty[1] === [] ? $head : $head.' (missing: '.implode(', ', $empty[1]).')';
    }

    private function configure(PendingRequest $request): PendingRequest
    {
        return $request
            ->withToken($this->connection->api_token)
            ->acceptJson()
            ->asJson()
            ->timeout(config('easyappointments.timeout'))
            ->withUserAgent('ea-mcp-server/1.0');
    }

    private function remember(string $key, int $seconds, callable $callback): mixed
    {
        return Cache::remember("ea:{$this->connection->id}:$key", $seconds, $callback);
    }

    private function availabilityKey(int $providerId, int $serviceId, string $date): string
    {
        return "ea:{$this->connection->id}:slots:$providerId:$serviceId:$date";
    }
}
