<?php

declare(strict_types=1);

namespace App\EventListener\Application;

use App\Entity\Application\Announcement;
use App\Entity\Application\MaintenanceWindow;
use App\Entity\Career\Company;
use App\Entity\Career\CompanyFeaturedPackage;
use App\Entity\Career\CompanyPackage;
use App\Entity\Career\Vacancy;
use App\Entity\Career\VacancyRevision;
use App\Entity\Database\CheckoutSession;
use App\Entity\Database\MemberUpdate;
use App\Entity\Database\ProspectiveMember;
use App\Entity\Database\SubDecision\Other;
use App\Repository\Application\AnnouncementRepository;
use App\Repository\Application\MaintenanceWindowRepository;
use App\Repository\Career\CompanyFeaturedPackageRepository;
use App\Twig\Extensions\ApplicationExtension;
use App\Twig\Extensions\CareerExtension;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\ObjectManager;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Drops the cached answers the layout renders on every page when what they are about is written.
 *
 * Both ways those answers are cached account for time passing on their own: the queries returning rows are cached by
 * the day and narrowed to the instant by their caller, and the career badges expire when their count changes. Neither
 * accounts for a write, which is what this listener is for.
 *
 * The result cache is taken from the manager that raised the event rather than injected, so it is the one Doctrine
 * wrote the entry with.
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
#[AsDoctrineListener(
    event: Events::postPersist,
    connection: 'default',
)]
#[AsDoctrineListener(
    event: Events::postUpdate,
    connection: 'default',
)]
#[AsDoctrineListener(
    event: Events::postRemove,
    connection: 'default',
)]
final readonly class LayoutCacheInvalidationListener
{
    public function __construct(
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $applicationCache,
    ) {
    }

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
        // A package is both the featured pick and part of what decides the counts.
        if (
            $entity instanceof CompanyPackage
            || $entity instanceof Company
            || $entity instanceof Vacancy
            || $entity instanceof VacancyRevision
        ) {
            $this->applicationCache->deleteItem(CareerExtension::MENU_COUNTS_CACHE_KEY);
        }

        // A checkout session counts because whether an applicant has paid is read off their latest one.
        $badgeKey = match (true) {
            $entity instanceof ProspectiveMember,
            $entity instanceof CheckoutSession => ApplicationExtension::PROSPECTIVES_CACHE_KEY,
            $entity instanceof MemberUpdate => ApplicationExtension::MEMBER_UPDATES_CACHE_KEY,
            $entity instanceof Other => ApplicationExtension::UNTRANSLATED_CACHE_KEY,
            default => null,
        };

        if (null !== $badgeKey) {
            $this->applicationCache->deleteItem($badgeKey);
        }

        $today = new DateTimeImmutable()->setTime(
            0,
            0,
        );
        $key = match (true) {
            $entity instanceof MaintenanceWindow => MaintenanceWindowRepository::cacheKeyFor($today),
            $entity instanceof Announcement => AnnouncementRepository::cacheKeyFor($today),
            $entity instanceof CompanyFeaturedPackage => CompanyFeaturedPackageRepository::cacheKeyFor($today),
            default => null,
        };

        if (
            null === $key
            || !$manager instanceof EntityManagerInterface
        ) {
            return;
        }

        $resultCache = $manager->getConfiguration()->getResultCache();
        if (null === $resultCache) {
            return;
        }

        // Only today's entry: a later day has not been asked about yet and so has nothing cached.
        $resultCache->deleteItem($key);
    }
}
