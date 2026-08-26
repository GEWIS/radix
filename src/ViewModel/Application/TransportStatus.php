<?php

declare(strict_types=1);

namespace App\ViewModel\Application;

/**
 * One messenger transport as the queue page shows it.
 *
 * `$waiting` is null rather than zero when the transport cannot be counted: a transport only reports a depth if it
 * implements `MessageCountAwareInterface`, and "unknown" and "empty" are not the same answer to give an
 * administrator looking at a backlog.
 */
final readonly class TransportStatus
{
    public function __construct(
        public string $name,
        public ?int $waiting,
        public bool $isFailureTransport,
    ) {
    }
}
