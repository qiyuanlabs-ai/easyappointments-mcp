<?php

namespace Tests\Support;

use App\Enums\AccessLevel;
use App\Models\EaConnection;
use App\Models\McpGrant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Mcp\Server\Testing\TestResponse;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

/** Signs a person in to the MCP server through a fake OAuth client and a fake Easy!Appointments. */
trait ConnectsMcp
{
    protected FakeEasyAppointments $ea;

    protected User $user;

    protected function setUpMcp(AccessLevel $granted = AccessLevel::FrontDesk, AccessLevel $max = AccessLevel::FrontDesk): void
    {
        // Monday 2 November 2026, 8:00 in the demo business's time zone.
        $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00', 'America/New_York'));

        $this->ea = FakeEasyAppointments::install();

        $connection = EaConnection::create(['name' => 'Test clinic', 'base_url' => 'http://ea.test', 'api_token' => 'test-token']);
        $this->user = User::factory()->create(['ea_connection_id' => $connection->id, 'max_access_level' => $max]);

        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Claude', ['https://claude.ai/api/mcp/auth_callback'], false);
        McpGrant::create(['user_id' => $this->user->id, 'oauth_client_id' => $client->getKey(), 'client_name' => 'Claude', 'access_level' => $granted]);

        Passport::actingAs($this->user, ['mcp:use'], 'api', $client);
    }

    /** The structured result of a successful tool call, as an array. */
    protected function structured(TestResponse $response): array
    {
        $data = null;
        $response->assertHasNoErrors()->assertStructuredContent(function (AssertableJson $json) use (&$data) {
            $data = $json->toArray();
            $json->etc();
        });

        return $data;
    }
}
