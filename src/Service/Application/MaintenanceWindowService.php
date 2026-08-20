<?php

declare(strict_types=1);

namespace App\Service\Application;

use App\Entity\Application\MaintenanceWindow;
use App\Repository\Application\MaintenanceWindowRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * Putting a maintenance window up and taking it down again. The counterpart of {@see MaintenanceStatusProvider},
 * which is the read side.
 *
 * Saving the window and pushing everyone off their current page are one operation: a window that takes effect now and
 * leaves half the site sitting on a page it may no longer serve is only half applied.
 */
final readonly class MaintenanceWindowService
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private MaintenanceWindowRepository $maintenanceWindowRepository,
        private RealtimeNotifier $realtimeNotifier,
    ) {
    }

    public function schedule(MaintenanceWindow $window): void
    {
        $this->entityManager->persist($window);
        $this->entityManager->flush();

        if (!$window->isActiveAt(new DateTimeImmutable())) {
            return;
        }

        $this->broadcastReload();
    }

    /**
     * Take the window down. Reports whether there was still one to take down, which is what decides if the operator is
     * told anything happened.
     */
    public function remove(int $id): bool
    {
        $window = $this->maintenanceWindowRepository->find($id);

        if (null === $window) {
            return false;
        }

        $wasActive = $window->isActiveAt(new DateTimeImmutable());

        $this->entityManager->remove($window);
        $this->entityManager->flush();

        if ($wasActive) {
            $this->broadcastReload();
        }

        return true;
    }

    /**
     * Tells connected clients to reload. The window is already saved, so a hub outage must not fail the request: it is
     * logged and non-admins land in the right place on their next request anyway.
     */
    private function broadcastReload(): void
    {
        try {
            $this->realtimeNotifier->reloadPublic();
        } catch (Throwable $e) {
            $this->logger->warning(
                'Failed to broadcast a maintenance reload.',
                ['exception' => $e],
            );
        }
    }
}
