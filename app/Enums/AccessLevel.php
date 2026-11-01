<?php

namespace App\Enums;

enum AccessLevel: string
{
    /** Look up services, open slots, customers and appointments. */
    case Read = 'read';

    /** Everything in Read, plus book, reschedule and cancel. */
    case FrontDesk = 'front_desk';

    public function allows(self $needed): bool
    {
        return $this->rank() >= $needed->rank();
    }

    /** The lower of two levels: a grant can never exceed what the person themselves may do. */
    public function cap(self $max): self
    {
        return $this->rank() <= $max->rank() ? $this : $max;
    }

    public function label(): string
    {
        return match ($this) {
            self::Read => 'Read only',
            self::FrontDesk => 'Front desk',
        };
    }

    public function summary(): string
    {
        return match ($this) {
            self::Read => 'See services, open slots, customers and appointments. Cannot change anything.',
            self::FrontDesk => 'Everything in read only, plus book, reschedule and cancel appointments. Each change is previewed first.',
        };
    }

    private function rank(): int
    {
        return match ($this) {
            self::Read => 1,
            self::FrontDesk => 2,
        };
    }
}
