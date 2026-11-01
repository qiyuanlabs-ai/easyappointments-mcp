<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One Easy!Appointments instance. The API token is encrypted at rest and never leaves the server.
        Schema::create('ea_connections', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('base_url');
            $table->text('api_token');
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('ea_connection_id')->nullable()->constrained()->nullOnDelete();
            // The most this person may ever grant an AI client. A connection can only get this or less.
            $table->string('max_access_level')->default('read');
        });

        // What one person allowed one OAuth client (Claude, ChatGPT...) to do.
        Schema::create('mcp_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('oauth_client_id');
            $table->string('client_name')->nullable();
            $table->string('access_level');
            $table->timestamps();
            $table->unique(['user_id', 'oauth_client_id']);
        });

        Schema::create('mcp_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('oauth_client_id')->nullable();
            $table->string('client_name')->nullable();
            $table->string('tool');
            $table->json('arguments')->nullable();
            $table->string('outcome'); // ok, preview, replayed, denied, error
            $table->text('error')->nullable();
            $table->json('ea_requests')->nullable();
            $table->unsignedInteger('duration_ms');
            $table->unsignedBigInteger('appointment_id')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->timestamp('created_at')->index();
            $table->index(['tool', 'outcome']);
        });

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('tool');
            $table->string('arguments_hash', 64);
            $table->json('result');
            $table->timestamp('created_at')->index();
            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('mcp_calls');
        Schema::dropIfExists('mcp_grants');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ea_connection_id');
            $table->dropColumn('max_access_level');
        });
        Schema::dropIfExists('ea_connections');
    }
};
