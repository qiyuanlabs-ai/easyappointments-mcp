<?php

namespace App\Console\Commands;

use App\Enums\AccessLevel;
use App\Models\EaConnection;
use App\Models\User;
use Illuminate\Console\Command;

class CreateUser extends Command
{
    protected $signature = 'ea:user {email} {--name=} {--password= : Asked for if left out} {--connection= : Connection id (defaults to the only one)} {--level=read : read or front_desk, the most this person may grant an AI client}';

    protected $description = 'Create or update a person who can sign in and connect AI clients';

    public function handle(): int
    {
        $level = AccessLevel::tryFrom($this->option('level'));
        if ($level === null) {
            $this->error('--level must be read or front_desk.');

            return self::FAILURE;
        }

        $connection = $this->option('connection')
            ? EaConnection::find($this->option('connection'))
            : (EaConnection::count() === 1 ? EaConnection::first() : null);

        if ($connection === null) {
            $this->error('Pick a connection with --connection=<id>. Add one first with ea:connect.');

            return self::FAILURE;
        }

        $password = $this->option('password') ?: $this->secret('Password (at least 10 characters)');
        if (strlen((string) $password) < 10) {
            $this->error('Use at least 10 characters.');

            return self::FAILURE;
        }

        $user = User::updateOrCreate(['email' => $this->argument('email')], [
            'name' => $this->option('name') ?: $this->argument('email'),
            'password' => $password,
            'ea_connection_id' => $connection->id,
            'max_access_level' => $level,
        ]);

        $this->info("{$user->email} can sign in and grant up to {$level->label()} access to \"{$connection->name}\".");

        return self::SUCCESS;
    }
}
