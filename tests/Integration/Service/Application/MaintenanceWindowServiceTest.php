<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Application;

use App\Entity\Application\Enums\MaintenanceStatus;
use App\Entity\Application\MaintenanceWindow;
use App\Repository\Application\MaintenanceWindowRepository;
use App\Service\Application\MaintenanceWindowService;
use App\Service\Application\RealtimeNotifier;
use App\Service\Application\RealtimeTopics;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * A window whose time has already come also reloads every open page: a window that takes effect now must not leave
 * clients on a page it may no longer serve. A hub outage may not fail the request, because the window is already
 * saved.
 */
final class MaintenanceWindowServiceTest extends DatabaseTestCase
{
    public function testAWindowThatIsAlreadyActivePushesAClientReload(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())
            ->method('publish')
            ->with(self::callback(
                static fn (Update $update): bool => [RealtimeTopics::PUBLIC] === $update->getTopics(),
            ));

        $window = $this->window();
        $window->startsAt = new DateTimeImmutable('-1 hour');

        $this->service($hub)->schedule($window);

        self::assertNotNull($window->id);
    }

    public function testAWindowInTheFutureIsOnlyScheduled(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::never())->method('publish');

        $window = $this->window();
        $window->startsAt = new DateTimeImmutable('+1 hour');

        $this->service($hub)->schedule($window);

        self::assertNotNull($window->id);
    }

    public function testTakingDownAnActiveWindowPushesAClientReload(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())->method('publish');

        $window = $this->storedWindow(active: true);
        $id = (int) $window->id;

        self::assertTrue($this->service($hub)->remove($id));
        self::assertNull($this->entityManager->getRepository(MaintenanceWindow::class)->find($id));
    }

    /**
     * A window that has not started yet was never pushed, so taking it down has nothing to reload.
     */
    public function testTakingDownAFutureWindowChangesNothingForConnectedClients(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::never())->method('publish');

        $window = $this->storedWindow(active: false);

        self::assertTrue($this->service($hub)->remove((int) $window->id));
    }

    public function testTakingDownAWindowThatIsNotThereReportsNothingHappened(): void
    {
        self::assertFalse($this->service($this->hub())->remove(999999));
    }

    public function testAFailingHubDoesNotFailTheRequest(): void
    {
        $hub = self::createStub(HubInterface::class);
        $hub->method('publish')->willThrowException(new RuntimeException('hub is down'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $window = $this->window();
        $window->startsAt = new DateTimeImmutable('-1 hour');

        $this->service($hub, $logger)->schedule($window);

        self::assertNotNull($window->id);
    }

    private function service(
        HubInterface $hub,
        ?LoggerInterface $logger = null,
    ): MaintenanceWindowService {
        return new MaintenanceWindowService(
            $this->entityManager,
            $logger ?? self::createStub(LoggerInterface::class),
            self::getContainer()->get(MaintenanceWindowRepository::class),
            new RealtimeNotifier(
                $hub,
                new RealtimeTopics('secret'),
            ),
        );
    }

    private function hub(): HubInterface
    {
        $hub = self::createStub(HubInterface::class);
        $hub->method('publish')->willReturn('update-id');

        return $hub;
    }

    private function window(): MaintenanceWindow
    {
        $window = new MaintenanceWindow();
        $window->status = MaintenanceStatus::ReadOnly;

        return $window;
    }

    private function storedWindow(bool $active): MaintenanceWindow
    {
        $window = $this->window();
        $window->startsAt = $active
            ? new DateTimeImmutable('-1 hour')
            : new DateTimeImmutable('+1 hour');
        $this->entityManager->persist($window);
        $this->entityManager->flush();

        return $window;
    }
}
