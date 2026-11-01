<?php

namespace Tests\Feature;

use App\Enums\AccessLevel;
use App\Models\EaConnection;
use App\Models\McpCall;
use App\Models\McpGrant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Tests\TestCase;

class LazyAuthAndRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function rpc(string $method, array $params = [], array $headers = [])
    {
        return $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], $headers + ['Accept' => 'application/json, text/event-stream']);
    }

    public function test_a_client_can_connect_and_list_every_tool_without_signing_in(): void
    {
        $this->rpc('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'test', 'version' => '1']])
            ->assertOk()->assertJsonPath('result.serverInfo.name', 'Easy!Appointments');

        $tools = $this->rpc('tools/list')->assertOk()->json('result.tools.*.name');
        sort($tools);
        $this->assertSame(['book_appointment', 'cancel_appointment', 'find_customer', 'find_open_slots', 'list_appointments', 'list_services', 'reschedule_appointment'], $tools);
    }

    public function test_every_tool_call_without_a_token_gets_a_401_that_starts_sign_in(): void
    {
        $response = $this->rpc('tools/call', ['name' => 'list_services', 'arguments' => (object) []])->assertUnauthorized();
        $this->assertStringContainsString('resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp').'"', $response->headers->get('WWW-Authenticate'));

        // A batch that sneaks a call in with a listing is refused as a whole.
        $this->postJson('/mcp', [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'list_services']],
        ])->assertUnauthorized()->assertHeader('WWW-Authenticate');

        $this->rpc('resources/list')->assertUnauthorized();
        $this->assertSame(0, McpCall::count());
    }

    public function test_a_token_that_is_sent_is_always_checked(): void
    {
        // An expired or bad token must get a 401 (so the client refreshes), even for a listing.
        $this->rpc('tools/list', [], ['Authorization' => 'Bearer not-a-real-token'])->assertUnauthorized()->assertHeader('WWW-Authenticate');
    }

    public function test_registration_accepts_https_and_loopback_redirects_only(): void
    {
        $register = fn (string $uri) => $this->postJson('/oauth/register', ['client_name' => 'Test', 'redirect_uris' => [$uri]]);

        $register('https://claude.ai/api/mcp/auth_callback')->assertCreated();
        $register('http://127.0.0.1:33418/callback')->assertCreated();
        $register('http://localhost:6274/oauth/callback')->assertCreated();

        foreach (['http://evil.example/callback', 'https://claude.ai/cb#frag', 'cursor://anysphere.cursor-retrieval/oauth', 'https://user:pw@claude.ai/cb', 'javascript:alert(1)'] as $bad) {
            $register($bad)->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
        }
    }

    public function test_registration_is_rate_limited_per_address(): void
    {
        config(['easyappointments.registrations_per_hour' => 3]);

        foreach (range(1, 3) as $i) {
            $this->postJson('/oauth/register', ['client_name' => "Client $i", 'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback']])->assertCreated();
        }

        $this->postJson('/oauth/register', ['client_name' => 'One too many', 'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback']])
            ->assertStatus(429)->assertJsonPath('error', 'too_many_requests');
    }

    public function test_consent_says_the_name_is_unverified_and_warns_about_an_unknown_destination(): void
    {
        if (! file_exists(storage_path('oauth-private.key'))) {
            Artisan::call('passport:keys');
        }
        $connection = EaConnection::create(['name' => 'Test clinic', 'base_url' => 'http://ea.test', 'api_token' => 'test-token']);
        $user = User::factory()->create(['ea_connection_id' => $connection->id, 'max_access_level' => AccessLevel::FrontDesk]);
        $clients = app(ClientRepository::class);
        $page = fn ($client, $redirect) => $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client->getKey(), 'redirect_uri' => $redirect, 'response_type' => 'code', 'scope' => 'mcp:use', 'state' => 'abc',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', Str::random(64), true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256',
        ]))->assertOk();

        $claude = $clients->createAuthorizationCodeGrantClient('Claude', ['https://claude.ai/api/mcp/auth_callback'], false);
        $page($claude, 'https://claude.ai/api/mcp/auth_callback')
            ->assertSee('name not verified')->assertSee('you will be sent to', false)->assertSee('claude.ai')
            ->assertDontSee('not a known AI assistant address');

        $fake = $clients->createAuthorizationCodeGrantClient('Claude', ['https://claude-login.example/cb'], false);
        $page($fake, 'https://claude-login.example/cb')
            ->assertSee('claude-login.example')->assertSee('This is not a known AI assistant address.');
    }

    public function test_unused_clients_are_pruned_and_used_ones_kept(): void
    {
        $clients = app(ClientRepository::class);
        $user = User::factory()->create();
        $make = function (string $name, int $daysOld) use ($clients) {
            $client = $clients->createAuthorizationCodeGrantClient($name, ['https://claude.ai/api/mcp/auth_callback'], false);
            $client->forceFill(['created_at' => now()->subDays($daysOld)])->save();

            return (string) $client->getKey();
        };
        $token = function (string $clientId, int $daysAgo, bool $validRefresh) use ($user) {
            $id = Str::random(40);
            DB::table('oauth_access_tokens')->insert(['id' => $id, 'user_id' => $user->id, 'client_id' => $clientId, 'scopes' => '[]', 'revoked' => false, 'created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo), 'expires_at' => now()->subDays($daysAgo)->addHour()]);
            DB::table('oauth_refresh_tokens')->insert(['id' => Str::random(40), 'access_token_id' => $id, 'revoked' => false, 'expires_at' => $validRefresh ? now()->addDays(5) : now()->subDays($daysAgo - 30)]);
            McpGrant::create(['user_id' => $user->id, 'oauth_client_id' => $clientId, 'client_name' => 'x', 'access_level' => AccessLevel::Read]);
        };

        $fresh = $make('Registered just now', 0);
        $neverUsed = $make('Registered, never signed in', 2);
        $active = $make('Used recently', 200);
        $token($active, 25, validRefresh: true);
        $abandoned = $make('Not used for months', 200);
        $token($abandoned, 120, validRefresh: false);

        $this->artisan('mcp:prune')->assertSuccessful();

        $left = Passport::client()->newQuery()->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->assertEqualsCanonicalizing([$fresh, $active], $left);
        $this->assertSame(0, McpGrant::where('oauth_client_id', $abandoned)->count());
        $this->assertSame(0, DB::table('oauth_access_tokens')->where('client_id', $abandoned)->count());
    }
}
