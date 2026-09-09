<?php

declare(strict_types=1);

namespace App\EventListener\Application;

use App\Entity\Application\MaintenanceWindow;
use App\Repository\Application\MaintenanceWindowRepository;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\ObjectManager;

/**
 * Drops the cached answers the layout asks for on every page as soon as what they are about is written.
 *
 * The layout's queries are cached by the day rather than by the instant (see {@see MaintenanceWindowRepository}), so
 * that asking the same question on every page is one query a day rather than one per request. That trade only holds
 * while a write is reflected immediately: a maintenance window scheduled by the board has to reach the banner on the
 * next page load, not on the next rollover. Time passing is not a write and needs no invalidation, because the cached
 * set is a superset of what is in force at any instant within the day and the caller narrows it down itself.
 *
 * The pool is taken from the manager that raised the event rather than injected, so it is by construction the one
 * Doctrine wrote the entry with.
 */
#[AsDoctrineListener(
    event: Events::postPersist,
    connection: 'web',
)]
#[AsDoctrineListener(
    event: Events::postUpdate,
    connection: 'web',
)]
#[AsDoctrineListener(
    event: Events::postRemove,
    connection: 'web',
)]
final readonly class LayoutCacheInvalidationListener
{
    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->invalidateFor(
            $args->getObject(),
            $args->getObjectManager(),
        );
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->invalidateFor(
            $args->getObject(),
            $args->getObjectManager(),
        );
    }

    public function postRemove(PostRemoveEventArgs $args): void
    {
        $this->invalidateFor(
            $args->getObject(),
            $args->getObjectManager(),
        );
    }

    private function invalidateFor(
        object $entity,
        ObjectManager $manager,
    ): void {
        if (
            !$entity instanceof MaintenanceWindow
            || !$manager instanceof EntityManagerInterface
        ) {
            return;
        }

        $resultCache = $manager->getConfiguration()->getResultCache();
        if (null === $resultCache) {
            return;
        }

        // Only today's entry: a later day has not been asked about yet and so has nothing cached.
        $resultCache->deleteItem(
            MaintenanceWindowRepository::cacheKeyFor(new DateTimeImmutable()->setTime(0, 0)),
        );
    }
}
