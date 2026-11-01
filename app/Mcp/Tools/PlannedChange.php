<?php

namespace App\Mcp\Tools;

use Closure;

/** A change worked out and checked, but not yet made. */
final class PlannedChange
{
    /**
     * @param  array  $preview  What will happen, shown to the person before they confirm.
     * @param  Closure(): array  $execute  Makes the change and returns the result.
     * @param  string|null  $lock  Name of the slot this change books (provider and day). Changes to the
     *                             same slot run one at a time, so two of them can never book it twice.
     * @param  Closure(): void|null  $recheck  Runs inside that lock, right before execute, to check the
     *                                         slot is still open with fresh data from Easy!Appointments.
     */
    public function __construct(
        public readonly array $preview,
        public readonly Closure $execute,
        public readonly ?int $appointmentId = null,
        public readonly ?string $lock = null,
        public readonly ?Closure $recheck = null,
    ) {}
}
