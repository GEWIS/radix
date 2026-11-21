<?php

declare(strict_types=1);

namespace App\Tests\Service\Application;

use App\Entity\Application\Enums\AlertTypes;
use App\Entity\Application\Enums\NotificationType;
use App\Entity\Application\Notification;
use App\Entity\User\CompanyUser;
use App\Entity\User\User;
use App\Service\Application\NotificationChannelInterface;
use App\Service\Application\NotificationPublisher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The persisted row is the source of truth, so it is written before any channel runs, and one failing channel may not
 * stop the others or turn a delivered notice into an error.
 */
final class NotificationPublisherTest extends TestCase
{
    public function testTheNotificationIsPersistedAndStampedBeforeItIsPushed(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::isInstanceOf(Notification::class));
        $entityManager->expects(self::once())->method('flush');

        $delivered = [];
        $channel = $this->channel(static function (Notification $notification) use (&$delivered): void {
            $delivered[] = $notification;
        });

        $notification = new Notification();
        $notification->type = NotificationType::ActivityPublished;

        $this->publisher($entityManager, null, $channel)->publish($notification);

        self::assertSame(
            [$notification],
            $delivered,
        );
    }

    public function testAFailingChannelDoesNotStopTheNextOne(): void
    {
        $delivered = [];
        $failing = $this->channel(static function (): void {
            throw new RuntimeException('hub is down');
        });
        $collecting = $this->channel(static function (Notification $notification) use (&$delivered): void {
            $delivered[] = $notification;
        });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $this->publisher($this->entityManager(), $logger, $failing, $collecting)->publish($this->notification());

        self::assertCount(
            1,
            $delivered,
        );
    }

    public function testANoticeIsAddressedToTheUserItIsAbout(): void
    {
        $notification = null;
        $channel = $this->channel(static function (Notification $delivered) use (&$notification): void {
            $notification = $delivered;
        });

        $user = self::createStub(User::class);
        $this->publisher(
            $this->entityManager(),
            null,
            $channel,
        )->publishFor(
            $user,
            NotificationType::PasswordChanged,
            ['key' => 'value'],
            AlertTypes::Warning,
        );

        self::assertInstanceOf(
            Notification::class,
            $notification,
        );
        self::assertSame(
            NotificationType::PasswordChanged,
            $notification->type,
        );
        self::assertSame(
            AlertTypes::Warning,
            $notification->level,
        );
        self::assertSame(
            ['key' => 'value'],
            $notification->context,
        );
        self::assertSame(
            $user,
            $notification->recipientUser,
        );
        self::assertNull($notification->recipientCompanyUser);
    }

    /**
     * The same notice, addressed to a company representative, must not land in any member's notification centre.
     */
    public function testANoticeForACompanyUserIsAddressedToThem(): void
    {
        $notification = null;
        $channel = $this->channel(static function (Notification $delivered) use (&$notification): void {
            $notification = $delivered;
        });

        $companyUser = self::createStub(CompanyUser::class);
        $this->publisher(
            $this->entityManager(),
            null,
            $channel,
        )->publishFor(
            $companyUser,
            NotificationType::CompanyRevisionAwaitingReview,
        );

        self::assertInstanceOf(
            Notification::class,
            $notification,
        );
        self::assertSame(
            $companyUser,
            $notification->recipientCompanyUser,
        );
        self::assertNull($notification->recipientUser);
        self::assertSame(
            AlertTypes::Info,
            $notification->level,
        );
        self::assertSame(
            [],
            $notification->context,
        );
    }

    private function publisher(
        EntityManagerInterface $entityManager,
        ?LoggerInterface $logger = null,
        NotificationChannelInterface ...$channels,
    ): NotificationPublisher {
        return new NotificationPublisher(
            $entityManager,
            $logger ?? self::createStub(LoggerInterface::class),
            $channels,
        );
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::createStub(EntityManagerInterface::class);
    }

    /**
     * @param callable(Notification): void $deliver
     */
    private function channel(callable $deliver): NotificationChannelInterface
    {
        $channel = self::createStub(NotificationChannelInterface::class);
        $channel->method('deliver')->willReturnCallback($deliver);

        return $channel;
    }

    private function notification(): Notification
    {
        $notification = new Notification();
        $notification->type = NotificationType::ActivityPublished;
        $notification->setRecipient(
            self::createStub(User::class),
            null,
        );

        return $notification;
    }
}
