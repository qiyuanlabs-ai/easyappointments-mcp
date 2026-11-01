<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A key is now claimed before the change is made (result still null) and completed after,
    // so two calls with the same key can never both make the change.
    public function up(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table) {
            $table->json('result')->nullable()->change();
            $table->timestamp('completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table) {
            $table->dropColumn('completed_at');
        });
    }
};
