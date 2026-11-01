<?php

namespace App\Ea;

/**
 * Collects the Easy!Appointments requests made while one MCP tool call runs,
 * so the call log can show exactly what the AI's request turned into.
 */
class EaRequestLog
{
    /** @var list<array{method: string, path: string, status: int|null, ms: int}> */
    private array $entries = [];

    public function add(string $method, string $path, ?int $status, int $ms): void
    {
        $this->entries[] = compact('method', 'path', 'status', 'ms');
    }

    /** @return list<array{method: string, path: string, status: int|null, ms: int}> */
    public function entries(): array
    {
        return $this->entries;
    }

    public function reset(): void
    {
        $this->entries = [];
    }
}
