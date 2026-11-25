<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Database;

use App\Message\Database\RefundProblemEmail;
use App\MessageHandler\Database\RefundProblemEmailHandler;
use App\Service\Application\Email;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;

/**
 * The website cannot settle a refund Stripe rejected, so the mail goes to the subscriptions address for the
 * secretary to follow up, with the state Stripe reported.
 */
final class RefundProblemEmailHandlerTest extends TestCase
{
    public function testTheSecretaryIsToldWhatStripeReported(): void
    {
        $sent = [];
        $mailer = self::createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(
            static function (TemplatedEmail $email) use (&$sent): void {
                $sent[] = $email;
            },
        );

        $handler = new RefundProblemEmailHandler(
            new Email(
                $mailer,
                'noreply@example.com',
                'GEWIS',
                'secretary@example.com',
                'Secretary',
            ),
            'subscriptions@example.com',
            'Subscriptions',
        );

        $handler(new RefundProblemEmail(
            're_123',
            'failed',
        ));

        self::assertCount(
            1,
            $sent,
        );
        $email = $sent[0];
        self::assertSame(
            'subscriptions@example.com',
            $email->getTo()[0]->getAddress(),
        );
        self::assertSame(
            'Subscriptions',
            $email->getTo()[0]->getName(),
        );
        self::assertSame(
            'Problem while processing membership refund',
            $email->getSubject(),
        );
        self::assertSame(
            'database/email/refund-problem.html.twig',
            $email->getHtmlTemplate(),
        );
        self::assertSame(
            [
                'refundId' => 're_123',
                'refundStatus' => 'failed',
            ],
            [
                'refundId' => $email->getContext()['refundId'],
                'refundStatus' => $email->getContext()['refundStatus'],
            ],
        );
        self::assertSame(
            'secretary@example.com',
            $email->getReplyTo()[0]->getAddress(),
        );
        self::assertSame(
            'secretary@example.com',
            $email->getContext()['secretary_email'],
        );
    }
}
