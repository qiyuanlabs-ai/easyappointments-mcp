<?php

namespace App\Console\Commands;

use App\Models\IdempotencyKey;
use App\Models\McpCall;
use Illuminate\Console\Command;

class PruneLogs extends Command
{
    protected $signature = 'mcp:prune';

    protected $description = 'Delete call logs and idempotency keys past their retention period';

    public function handle(): int
    {
        $calls = McpCall::where('created_at', '<', now()->subDays(config('easyappointments.log_retention_days')))->delete();
        $keys = IdempotencyKey::where('created_at', '<', now()->subHours(config('easyappointments.idempotency_hours')))->delete();
        $this->info("Deleted $calls call logs and $keys idempotency keys.");

        return self::SUCCESS;
    }
}
