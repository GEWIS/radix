<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Application;

use App\Entity\Application\Enums\NotificationType;
use App\Message\Application\SendNotificationDigestMessage;
use App\MessageHandler\Application\SendNotificationDigestHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function array_column;

/**
 * One digest per member rather than one mail per notification. A single notification becomes the subject; several
 * are summarised by count.
 */
final class SendNotificationDigestHandlerTest extends TestCase
{
    public function testAnEmptyDigestSendsNothing(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::never())->method('generate');

        $handler = new SendNotificationDigestHandler(
            $mailer,
            $urlGenerator,
            self::createStub(LoggerInterface::class),
        );

        $handler($this->message([]));
    }

    public function testASingleNotificationIsItsOwnSubject(): void
    {
        $sent = [];
        $this->handler($sent)->__invoke($this->message([
            $this->entry('An album was published'),
        ]));

        self::assertCount(
            1,
            $sent,
        );
        $email = $sent[0];
        self::assertSame(
            'ada@example.com',
            $email->getTo()[0]->getAddress(),
        );
        self::assertSame(
            'An album was published',
            $email->getSubject(),
        );
        self::assertSame(
            'emails/notification/digest.html.twig',
            $email->getHtmlTemplate(),
        );
        self::assertSame(
            'Ada Lovelace',
            $email->getContext()['fullName'],
        );
        self::assertSame(
            'https://gewis.nl/user_settings_notifications',
            $email->getContext()['settingsUrl'],
        );
        self::assertSame(
            [
                [
                    'text' => 'An album was published',
                    'linkLabel' => 'View photo',
                    'type' => NotificationType::AlbumPublished,
                    'subjectId' => 12,
                ],
            ],
            $email->getContext()['entries'],
        );
    }

    public function testSeveralNotificationsShareAGenericSubjectAndKeepTheirOrder(): void
    {
        $sent = [];
        $this->handler($sent)->__invoke($this->message([
            $this->entry('First'),
            $this->entry('Second'),
        ]));

        self::assertCount(
            1,
            $sent,
        );
        self::assertSame(
            'You have new notifications from GEWIS',
            $sent[0]->getSubject(),
        );
        self::assertSame(
            [
                'First',
                'Second',
            ],
            array_column(
                $sent[0]->getContext()['entries'],
                'text',
            ),
        );
    }

    public function testATransportFailureDoesNotThrowOutOfTheWorker(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())
            ->method('send')
            ->willThrowException(new TransportException('mail server is down'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                'Failed to send a notification digest email.',
                self::arrayHasKey('exception'),
            );

        $handler = new SendNotificationDigestHandler(
            $mailer,
            $this->urlGenerator(),
            $logger,
        );

        $handler($this->message([$this->entry('Anything')]));
    }

    /**
     * @param list<TemplatedEmail> $sent
     */
    private function handler(array &$sent): SendNotificationDigestHandler
    {
        $mailer = self::createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(
            static function (TemplatedEmail $email) use (&$sent): void {
                $sent[] = $email;
            },
        );

        return new SendNotificationDigestHandler(
            $mailer,
            $this->urlGenerator(),
            self::createStub(LoggerInterface::class),
        );
    }

    private function urlGenerator(): UrlGeneratorInterface
    {
        $urlGenerator = self::createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static fn (string $route): string => 'https://gewis.nl/' . $route,
        );

        return $urlGenerator;
    }

    /**
     * @param list<array{text: string, linkLabel: string, type: NotificationType, subjectId: int}> $entries
     */
    private function message(array $entries): SendNotificationDigestMessage
    {
        return new SendNotificationDigestMessage(
            'ada@example.com',
            'Ada Lovelace',
            $entries,
        );
    }

    /**
     * @return array{text: string, linkLabel: string, type: NotificationType, subjectId: int}
     */
    private function entry(string $text): array
    {
        return [
            'text' => $text,
            'linkLabel' => 'View photo',
            'type' => NotificationType::AlbumPublished,
            'subjectId' => 12,
        ];
    }
}
