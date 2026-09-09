<?php

declare(strict_types=1);

namespace App\Repository\Application;

use App\Entity\Application\Announcement;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

use function array_filter;
use function array_values;

/**
 * @template-extends ServiceEntityRepository<Announcement>
 */
class AnnouncementRepository extends ServiceEntityRepository
{
    public const string CACHE_KEY_PREFIX = 'layout.announcements.';

    /** A day, so the entry cannot outlive the day it was asked about even if nothing invalidates it. */
    private const int CACHE_LIFETIME = 86400;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(
            $registry,
            Announcement::class,
        );
    }

    /**
     * @return Announcement[]
     */
    public function findAllNewestFirst(): array
    {
        return $this->findBy(
            [],
            ['createdAt' => 'DESC'],
        );
    }

    /**
     * Every announcement that could still be running at some point today, oldest first.
     *
     * Cached by the day, as {@see MaintenanceWindowRepository::findRelevantOn()} is and for the same reason. The
     * result is a superset of what is running at any instant within the day, which {@see findActive} narrows down.
     *
     * @return Announcement[]
     */
    public function findRelevantOn(DateTimeImmutable $day): array
    {
        $startOfDay = $day->setTime(
            0,
            0,
        );

        return $this->createQueryBuilder('a')
            ->where('a.endsAt > :startOfDay')
            ->setParameter(
                'startOfDay',
                $startOfDay,
                Types::DATETIME_IMMUTABLE,
            )
            ->orderBy(
                'a.createdAt',
                'ASC',
            )
            ->getQuery()
            ->enableResultCache(
                self::CACHE_LIFETIME,
                self::cacheKeyFor($startOfDay),
            )
            ->getResult();
    }

    /**
     * The announcements running at the given instant, taken from the day's cached candidates.
     *
     * @return Announcement[]
     */
    public function findActive(DateTimeImmutable $now): array
    {
        return array_values(
            array_filter(
                $this->findRelevantOn($now),
                static fn (Announcement $announcement): bool => $announcement->getEndsAt() > $now,
            ),
        );
    }

    public static function cacheKeyFor(DateTimeImmutable $startOfDay): string
    {
        return self::CACHE_KEY_PREFIX . $startOfDay->format('Y-m-d');
    }
}
