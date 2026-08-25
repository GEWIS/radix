<?php

declare(strict_types=1);

namespace App\Message\Database;

/**
 * Asynchronously tell the secretary that a membership fee refund did not go through.
 *
 * Carries what Stripe said rather than a record of our own: the refund is the association's side of a payment that
 * only Stripe knows the state of, and the secretary takes it from here by hand.
 */
class RefundProblemEmail
{
    public function __construct(
        private readonly string $refundId,
        private readonly string $refundStatus,
    ) {
    }

    public function getRefundId(): string
    {
        return $this->refundId;
    }

    public function getRefundStatus(): string
    {
        return $this->refundStatus;
    }
}
