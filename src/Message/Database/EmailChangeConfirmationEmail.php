<?php

declare(strict_types=1);

namespace App\Message\Database;

class EmailChangeConfirmationEmail
{
    public function __construct(
        private readonly int $lidnr,
        private readonly string $newEmail,
        private readonly string $token,
    ) {
    }

    public function getLidnr(): int
    {
        return $this->lidnr;
    }

    public function getNewEmail(): string
    {
        return $this->newEmail;
    }

    public function getToken(): string
    {
        return $this->token;
    }
}
