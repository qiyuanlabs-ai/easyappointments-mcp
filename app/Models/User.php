<?php

namespace App\Models;

use App\Enums\AccessLevel;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'ea_connection_id', 'max_access_level'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements OAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'max_access_level' => AccessLevel::class,
        ];
    }

    public function eaConnection(): BelongsTo
    {
        return $this->belongsTo(EaConnection::class);
    }

    public function grants(): HasMany
    {
        return $this->hasMany(McpGrant::class);
    }

    /**
     * What the AI client behind the current token may do. A client that never went
     * through our consent screen (no grant row) only gets read access.
     */
    public function accessLevelForClient(?string $clientId): AccessLevel
    {
        $grant = $clientId === null ? null : $this->grants()->where('oauth_client_id', $clientId)->first();

        return ($grant?->access_level ?? AccessLevel::Read)->cap($this->max_access_level ?? AccessLevel::Read);
    }
}
