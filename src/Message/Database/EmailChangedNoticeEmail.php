<?php

declare(strict_types=1);

namespace App\Message\Database;

/**
 * Carries the address being replaced, because by the time this is handled the member answers with the new one.
 */
class EmailChangedNoticeEmail
{
    public function __construct(
        private readonly int $lidnr,
        private readonly string $previousEmail,
        private readonly string $newEmail,
    ) {
    }

    public function getLidnr(): int
    {
        return $this->lidnr;
    }

    public function getPreviousEmail(): string
    {
        return $this->previousEmail;
    }

    public function getNewEmail(): string
    {
        return $this->newEmail;
    }
}
