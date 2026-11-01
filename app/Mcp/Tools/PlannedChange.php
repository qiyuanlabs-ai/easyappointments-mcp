<?php

namespace App\Mcp\Tools;

use Closure;

/** A change worked out and checked, but not yet made. */
final class PlannedChange
{
    /**
     * @param  array  $preview  What will happen, shown to the person before they confirm.
     * @param  Closure(): array  $execute  Makes the change and returns the result.
     */
    public function __construct(
        public readonly array $preview,
        public readonly Closure $execute,
        public readonly ?int $appointmentId = null,
    ) {}
}
