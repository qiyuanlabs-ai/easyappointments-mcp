<?php

namespace App\Ea;

use RuntimeException;

/**
 * A failure talking to Easy!Appointments, with a message written for the AI client:
 * it reads this text and decides what to tell the person, so it must say what to do next.
 */
class EaApiException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }

    public static function fromStatus(int $status, string $method, string $path): self
    {
        return new self(match (true) {
            $status === 401, $status === 403 => 'Easy!Appointments rejected the API token. An administrator needs to check the connection settings on the MCP server.',
            $status === 404 => 'Easy!Appointments has no record at '.$path.'. It may have been deleted; look it up again.',
            $status === 429 => 'Easy!Appointments is rate limiting requests (it allows 100 every 2 minutes per server). Wait a minute and try again, or ask for fewer days at once.',
            $status >= 500 => 'Easy!Appointments had an internal error ('.$status.') on '.$method.' '.$path.'. Try again shortly.',
            default => 'Easy!Appointments refused '.$method.' '.$path.' with status '.$status.'.',
        }, $status);
    }
}
