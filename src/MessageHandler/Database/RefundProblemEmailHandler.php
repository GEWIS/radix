<?php

declare(strict_types=1);

namespace App\MessageHandler\Database;

use App\Message\Database\RefundProblemEmail;
use App\Service\Application\Email as EmailService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;

/**
 * Tells the secretary that a refund needs looking at. They ask the ApplicatieBeheerCommissie and/or the treasurer to
 * work out what happened and what has to be done to settle it.
 */
#[AsMessageHandler]
class RefundProblemEmailHandler
{
    public function __construct(
        private readonly EmailService $emailService,
        private readonly string $mailToSubscriptionAddress,
        private readonly string $mailToSubscriptionName,
    ) {
    }

    public function __invoke(RefundProblemEmail $message): void
    {
        $this->emailService->send(
            new Address(
                $this->mailToSubscriptionAddress,
                $this->mailToSubscriptionName,
            ),
            'Problem while processing membership refund',
            'database/email/refund-problem.html.twig',
            [
                'refundId' => $message->getRefundId(),
                'refundStatus' => $message->getRefundStatus(),
            ],
            $this->emailService->secretary(),
        );
    }
}
