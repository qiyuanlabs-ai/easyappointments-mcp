<?php

namespace App\Mcp\Tools;

use App\Ea\EaClient;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_services')]
#[Title('List services and providers')]
#[Description('Lists the services this business offers (length, price) and which providers offer each one. Call this first to get the service_id and provider_id that the other tools need.')]
#[IsReadOnly]
class ListServices extends EaTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Optional words to filter services by name, for example "massage".'),
        ];
    }

    protected function perform(Request $request, EaClient $ea, User $user): ToolResult
    {
        $request->validate(['query' => ['nullable', 'string', 'max:100']]);
        $query = mb_strtolower(trim((string) $request->get('query', '')));
        $providers = $ea->providers();

        $services = collect($ea->services())
            ->when($query !== '', fn ($c) => $c->filter(fn ($s) => str_contains(mb_strtolower($s['name']), $query)))
            ->map(fn (array $s) => [
                'id' => $s['id'],
                'name' => $s['name'],
                'duration_minutes' => $s['duration'],
                'price' => $s['price'],
                'currency' => $s['currency'],
                'description' => $s['description'],
                'providers' => collect($providers)
                    ->filter(fn ($p) => in_array($s['id'], $p['services'], true))
                    ->map(fn ($p) => ['id' => $p['id'], 'name' => $p['name'], 'timezone' => $p['timezone']])->values()->all(),
            ])->values()->all();

        return new ToolResult([
            'timezone' => $ea->timezone(),
            'services' => $services,
        ]);
    }
}
