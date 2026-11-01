<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['result' => 'array', 'created_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }
}
