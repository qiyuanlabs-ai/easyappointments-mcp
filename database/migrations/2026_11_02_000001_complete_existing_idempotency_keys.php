<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Keys saved before completed_at existed only ever held finished results, so mark them
    // completed and they keep replaying their result instead of looking unfinished.
    public function up(): void
    {
        DB::table('idempotency_keys')
            ->whereNotNull('result')
            ->whereNull('completed_at')
            ->update(['completed_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        // Nothing to undo: the previous migration drops the column.
    }
};
