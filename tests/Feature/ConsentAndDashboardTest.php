<?php

namespace Tests\Feature;

use App\Enums\AccessLevel;
use App\Mcp\Servers\EasyAppointmentsServer;
use App\Mcp\Tools\BookAppointment;
use App\Mcp\Tools\CancelAppointment;
use App\Mcp\Tools\FindOpenSlots;
use App\Mcp\Tools\ListServices;
use App\Models\EaConnection;
use App\Models\McpCall;
use App\Models\McpGrant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Token;
use Tests\Support\ConnectsMcp;
use Tests\TestCase;

class ConsentAndDashboardTest extends TestCase
{
    use ConnectsMcp, RefreshDatabase;

    public function test_a_read_only_connection_sees_and_runs_only_read_tools(): void
    {
        $this->setUpMcp(granted: AccessLevel::Read);

        EasyAppointmentsServer::tools()
            ->assertRegistered([ListServices::class, FindOpenSlots::class])
            ->assertNotRegistered([BookAppointment::class, CancelAppointment::class]);

        EasyAppointmentsServer::tool(BookAppointment::class, ['service_id' => 1])->assertHasErrors();
        $this->assertSame([], $this->ea->writes());
    }

    public function test_the_account_limit_caps_what_a_client_was_granted(): void
    {
        $this->setUpMcp(granted: AccessLevel::FrontDesk, max: AccessLevel::Read);

        EasyAppointmentsServer::tools()->assertNotRegistered([BookAppointment::class]);
    }

    public function test_an_account_without_a_linked_instance_gets_a_clear_message(): void
    {
        $this->setUpMcp();
        $this->user->update(['ea_connection_id' => null]);

        EasyAppointmentsServer::tool(ListServices::class)->assertHasErrors(['not linked to an Easy!Appointments instance']);
    }

    public function test_consent_stores_the_chosen_level_capped_by_the_account(): void
    {
        $this->ensurePassportKeys();
        $connection = EaConnection::create(['name' => 'Test clinic', 'base_url' => 'http://ea.test', 'api_token' => 'test-token']);
        $user = User::factory()->create(['ea_connection_id' => $connection->id, 'max_access_level' => AccessLevel::Read]);
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Claude', ['https://claude.ai/api/mcp/auth_callback'], false);

        $page = $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
            'response_type' => 'code',
            'scope' => 'mcp:use',
            'state' => 'abc',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', Str::random(64), true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]));
        $page->assertOk()->assertSee('Connect Claude?')->assertSee('claude.ai')->assertSee('Your account is not allowed this level.');

        $response = $this->post('/oauth/consent', [
            'auth_token' => session('authToken'),
            'access_level' => AccessLevel::FrontDesk->value,
        ]);

        $response->assertRedirectContains('https://claude.ai/api/mcp/auth_callback?code=');
        $this->assertSame(AccessLevel::Read, McpGrant::sole()->access_level);
    }

    public function test_consent_without_the_pending_request_token_is_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/oauth/consent', ['auth_token' => 'guess', 'access_level' => 'read'])->assertForbidden();
        $this->assertSame(0, McpGrant::count());
    }

    public function test_dashboard_lists_calls_and_revoking_a_client_kills_its_tokens(): void
    {
        $this->setUpMcp();
        EasyAppointmentsServer::tool(ListServices::class);
        $grant = McpGrant::sole();
        $token = Token::forceCreate(['id' => Str::random(40), 'user_id' => $this->user->id, 'client_id' => $grant->oauth_client_id, 'scopes' => ['mcp:use'], 'revoked' => false, 'expires_at' => now()->addHour()]);

        $this->actingAs($this->user, 'web')->get('/dashboard')->assertOk()->assertSee('list_services')->assertSee('Claude');

        $this->delete("/grants/{$grant->id}")->assertRedirect();
        $this->assertTrue($token->fresh()->revoked);
        $this->assertSame(0, McpGrant::count());
    }

    public function test_people_cannot_change_each_others_grants(): void
    {
        $this->setUpMcp();
        $grant = McpGrant::sole();

        $this->actingAs(User::factory()->create(), 'web')->delete("/grants/{$grant->id}")->assertNotFound();
        $this->assertSame(1, McpGrant::count());
    }

    public function test_csv_export_neutralises_spreadsheet_formulas(): void
    {
        $this->setUpMcp();
        McpCall::create(['user_id' => $this->user->id, 'client_name' => '=HYPERLINK("http://evil.test")', 'tool' => 'list_services', 'arguments' => [], 'outcome' => 'ok', 'ea_requests' => [], 'duration_ms' => 1, 'created_at' => now()]);

        $csv = $this->actingAs($this->user, 'web')->get('/dashboard/calls.csv')->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    private function ensurePassportKeys(): void
    {
        if (! file_exists(storage_path('oauth-private.key'))) {
            Artisan::call('passport:keys');
        }
    }

    public function test_unauthenticated_mcp_requests_get_a_401_pointing_at_the_oauth_metadata(): void
    {
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'])
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate');

        // Even a client that sends no Accept header must not be redirected to the login page.
        $this->call('POST', '/mcp', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertUnauthorized();
    }
}
