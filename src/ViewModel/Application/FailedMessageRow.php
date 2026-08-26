<?php

declare(strict_types=1);

namespace App\ViewModel\Application;

use DateTimeImmutable;

/**
 * One message on the failure transport, flattened to what is worth showing without opening it.
 *
 * Every field is optional because a failed message is exactly the case where the stamps may be incomplete: a message
 * that died before a retry carries no `RedeliveryStamp`, and one whose handler was removed since may not decode at
 * all ({@see $class} then carries what the transport could still say about it).
 */
final readonly class FailedMessageRow
{
    public function __construct(
        public ?string $id,
        public string $class,
        public ?string $originalTransport,
        public ?DateTimeImmutable $failedAt,
        public ?string $errorClass,
        public ?string $errorMessage,
        public int $retries,
    ) {
    }
}
