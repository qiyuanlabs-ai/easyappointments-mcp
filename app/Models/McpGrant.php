<?php

namespace App\Models;

use App\Enums\AccessLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class McpGrant extends Model
{
    protected $fillable = ['user_id', 'oauth_client_id', 'client_name', 'access_level'];

    protected function casts(): array
    {
        return ['access_level' => AccessLevel::class];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
