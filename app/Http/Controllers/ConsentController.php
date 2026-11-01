<?php

namespace App\Http\Controllers;

use App\Enums\AccessLevel;
use App\Models\McpGrant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Laravel\Passport\Client;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;

/**
 * The person approves an AI client on our consent screen and picks how much it may do.
 * We store that choice per client, then hand over to Passport to finish the OAuth flow.
 */
class ConsentController extends Controller
{
    public function approve(Request $request): mixed
    {
        $validated = $request->validate([
            'auth_token' => ['required', 'string'],
            'access_level' => ['required', Rule::enum(AccessLevel::class)],
        ]);

        // Only a client that is actually being authorized in this session can be granted anything.
        // The client comes from Passport's own record of the pending request, never from the form.
        abort_unless($request->session()->get('authToken') === $validated['auth_token'], 403);
        $authRequest = unserialize((string) $request->session()->get('authRequest'), ['allowed_classes' => true]);
        abort_unless($authRequest instanceof AuthorizationRequest, 403);
        $client = Client::findOrFail($authRequest->getClient()->getIdentifier());

        $user = $request->user();
        $level = AccessLevel::from($validated['access_level'])->cap($user->max_access_level);

        McpGrant::updateOrCreate(
            ['user_id' => $user->id, 'oauth_client_id' => (string) $client->getKey()],
            ['client_name' => $client->name, 'access_level' => $level],
        );

        return app()->call([app(ApproveAuthorizationController::class), 'approve']);
    }
}
