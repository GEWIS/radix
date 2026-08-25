<?php

declare(strict_types=1);

namespace App\Message\Database;

/**
 * Asynchronously tell a (prospective) member, and the secretary, what happened to their registration.
 *
 * Carries the membership number rather than the record, so the mail is rendered from what the register holds at the
 * time it is sent. Every dispatch site flushes first, and a record that has since been removed is not mailed about.
 *
 * Async because these are raised from the Stripe webhook and from the secretary's approval request, where a blocking
 * SMTP round-trip buys nothing and costs a webhook retry or a slow page.
 */
class RegistrationUpdateEmail
{
    public function __construct(
        private readonly RegistrationUpdate $type,
        private readonly int $lidnr,
    ) {
    }

    public function getType(): RegistrationUpdate
    {
        return $this->type;
    }

    public function getLidnr(): int
    {
        return $this->lidnr;
    }
}
