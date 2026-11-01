<?php

namespace App\Mcp\Tools;

/** What a tool produced, plus what the call log should remember about it. */
final class ToolResult
{
    public function __construct(
        public readonly array $data,
        public readonly string $outcome = 'ok',
        public readonly ?int $appointmentId = null,
    ) {}

    public static function preview(array $data, ?int $appointmentId = null): self
    {
        return new self(['status' => 'preview', 'confirmed' => false] + $data, 'preview', $appointmentId);
    }
}
