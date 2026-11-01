<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EaConnection extends Model
{
    protected $fillable = ['name', 'base_url', 'api_token'];

    protected $hidden = ['api_token'];

    protected function casts(): array
    {
        return ['api_token' => 'encrypted'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function apiUrl(): string
    {
        return rtrim($this->base_url, '/').'/index.php/api/v1';
    }
}
