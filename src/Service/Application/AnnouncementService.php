<?php

declare(strict_types=1);

namespace App\Service\Application;

use App\Entity\Application\Announcement;
use App\Repository\Application\AnnouncementRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Sending an announcement out to everyone connected, and taking one back down again.
 *
 * Broadcasting and storing are one operation rather than two: an announcement that is not sticky is a one-off that
 * nothing keeps, so whether a row is written at all is part of sending rather than a decision the caller makes
 * afterwards.
 */
final readonly class AnnouncementService
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private EntityManagerInterface $entityManager,
        private AnnouncementRepository $announcementRepository,
        private RealtimeNotifier $realtimeNotifier,
    ) {
    }

    /**
     * Push the announcement to every connected client, and keep it only if it was asked to stay up until `$endsAt`.
     */
    public function send(
        Announcement $announcement,
        bool $sticky,
        ?DateTimeImmutable $endsAt,
    ): void {
        $this->realtimeNotifier->toPublic(new RealtimePayload(
            $announcement->getLevel(),
            $announcement->getBody()->toArray(),
            title: $announcement->getTitle()->toArray(),
        ));

        if (
            !$sticky
            || null === $endsAt
        ) {
            return;
        }

        $announcement->setEndsAt($endsAt);
        $announcement->setCreatedAt(new DateTimeImmutable());

        $this->entityManager->persist($announcement);
        $this->entityManager->flush();
    }

    /**
     * Take the announcement down. Reports whether there was still one to take down, which is what decides if the
     * operator is told anything happened.
     */
    public function remove(int $id): bool
    {
        $announcement = $this->announcementRepository->find($id);

        if (null === $announcement) {
            return false;
        }

        $this->entityManager->remove($announcement);
        $this->entityManager->flush();

        return true;
    }
}
