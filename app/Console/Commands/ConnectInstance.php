<?php

namespace App\Console\Commands;

use App\Models\EaConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ConnectInstance extends Command
{
    protected $signature = 'ea:connect {name : A label, e.g. the business name} {url : Base URL of Easy!Appointments, e.g. https://booking.example.com} {--token= : The API token from Settings > Integrations > API (asked for if left out)}';

    protected $description = 'Add an Easy!Appointments instance (or update the one with this URL) and check the API token works';

    public function handle(): int
    {
        $url = rtrim($this->argument('url'), '/');
        $token = $this->option('token') ?: $this->secret('API token');

        $response = Http::withToken($token)->acceptJson()->timeout(10)
            ->get("$url/index.php/api/v1/services", ['length' => 1]);

        if ($response->failed()) {
            $this->error("The API answered {$response->status()}. Check the URL, that the API token is set in Easy!Appointments, and that your web server passes the Authorization header to PHP.");

            return self::FAILURE;
        }

        $connection = EaConnection::updateOrCreate(['base_url' => $url], ['name' => $this->argument('name'), 'api_token' => $token]);
        $this->info("Connected \"{$connection->name}\" (id {$connection->id}).");

        return self::SUCCESS;
    }
}
