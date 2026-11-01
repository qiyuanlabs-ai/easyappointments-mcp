<?php

namespace App\Console\Commands;

use App\Models\IdempotencyKey;
use App\Models\McpCall;
use App\Models\McpGrant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

class PruneLogs extends Command
{
    protected $signature = 'mcp:prune';

    protected $description = 'Delete old call logs and idempotency keys, and OAuth clients that registered but are not used';

    public function handle(): int
    {
        $calls = McpCall::where('created_at', '<', now()->subDays(config('easyappointments.log_retention_days')))->delete();
        $keys = IdempotencyKey::where('created_at', '<', now()->subHours(config('easyappointments.idempotency_hours')))->delete();
        [$neverUsed, $unused] = $this->pruneClients();

        $this->info("Deleted $calls call logs, $keys idempotency keys, $neverUsed never-used and $unused unused OAuth clients.");

        return self::SUCCESS;
    }

    /**
     * Anyone can register a client, so clients that never got a token are removed after a day,
     * and clients with no valid token and no activity for unused_client_days are removed with
     * their grants. A removed client simply registers again next time it connects.
     *
     * @return array{int, int}
     */
    private function pruneClients(): array
    {
        $neverUsedBefore = now()->subDays(config('easyappointments.never_used_client_days'));
        $unusedBefore = now()->subDays(config('easyappointments.unused_client_days'));
        $neverUsed = $unused = 0;

        Passport::client()->newQuery()->whereNull('owner_id')->where('created_at', '<', $neverUsedBefore)->lazyById()
            ->each(function ($client) use ($unusedBefore, &$neverUsed, &$unused) {
                $id = (string) $client->getKey();
                $tokens = DB::table('oauth_access_tokens')->where('client_id', $id);
                $hasGrant = McpGrant::where('oauth_client_id', $id)->exists();

                if (! $hasGrant && ! (clone $tokens)->exists() && ! DB::table('oauth_auth_codes')->where('client_id', $id)->where('expires_at', '>', now())->exists()) {
                    $this->deleteClient($client);
                    $neverUsed++;

                    return;
                }

                $validToken = (clone $tokens)->where('revoked', false)->where('expires_at', '>', now())->exists()
                    || DB::table('oauth_refresh_tokens')
                        ->whereIn('access_token_id', (clone $tokens)->select('id'))
                        ->where('revoked', false)->where('expires_at', '>', now())->exists();

                $lastActive = collect([
                    $client->created_at,
                    (clone $tokens)->max('created_at'),
                    McpCall::where('oauth_client_id', $id)->max('created_at'),
                ])->filter()->map(fn ($t) => Carbon::parse($t))->max();

                if (! $validToken && $lastActive->lt($unusedBefore)) {
                    $this->deleteClient($client);
                    $unused++;
                }
            });

        return [$neverUsed, $unused];
    }

    private function deleteClient($client): void
    {
        $id = (string) $client->getKey();

        DB::transaction(function () use ($client, $id) {
            $tokenIds = DB::table('oauth_access_tokens')->where('client_id', $id)->pluck('id');
            DB::table('oauth_refresh_tokens')->whereIn('access_token_id', $tokenIds)->delete();
            DB::table('oauth_access_tokens')->where('client_id', $id)->delete();
            DB::table('oauth_auth_codes')->where('client_id', $id)->delete();
            McpGrant::where('oauth_client_id', $id)->delete();
            $client->delete();
        });
    }
}
