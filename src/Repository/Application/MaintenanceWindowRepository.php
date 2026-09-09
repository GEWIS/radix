<?php

declare(strict_types=1);

namespace App\Repository\Application;

use App\Entity\Application\MaintenanceWindow;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

use function array_filter;
use function array_values;

/**
 * @template-extends ServiceEntityRepository<MaintenanceWindow>
 */
class MaintenanceWindowRepository extends ServiceEntityRepository
{
    public const string CACHE_KEY_PREFIX = 'layout.maintenance_windows.';

    /** A day, so the entry cannot outlive the day it was asked about even if nothing invalidates it. */
    private const int CACHE_LIFETIME = 86400;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(
            $registry,
            MaintenanceWindow::class,
        );
    }

    /**
     * @return MaintenanceWindow[]
     */
    public function findAllOrdered(): array
    {
        return $this->findBy(
            [],
            ['startsAt' => 'ASC'],
        );
    }

    /**
     * Every window that could still be in force at some point today, ordered as the active one is picked.
     *
     * Cached by the day rather than by the instant, because a query parameterised on `now` is a different question
     * every second and so can never be answered from cache. The result is a superset of what is in force at any
     * instant within the day, which is what lets {@see findActiveAt} narrow it down without a query.
     *
     * @return MaintenanceWindow[]
     */
    public function findRelevantOn(DateTimeImmutable $day): array
    {
        $startOfDay = $day->setTime(
            0,
            0,
        );

        return $this->createQueryBuilder('w')
            ->where('(w.endsAt IS NULL OR w.endsAt > :startOfDay)')
            ->setParameter(
                'startOfDay',
                $startOfDay,
                Types::DATETIME_IMMUTABLE,
            )
            ->orderBy(
                'w.startsAt',
                'ASC',
            )
            ->getQuery()
            ->enableResultCache(
                self::CACHE_LIFETIME,
                self::cacheKeyFor($startOfDay),
            )
            ->getResult();
    }

    /** The window in force at the given instant, taken from the day's cached candidates. */
    public function findActiveAt(DateTimeImmutable $now): ?MaintenanceWindow
    {
        foreach ($this->findRelevantOn($now) as $window) {
            $startsAt = $window->getStartsAt();
            $endsAt = $window->getEndsAt();

            if (
                (
                    null !== $startsAt
                    && $startsAt > $now
                )
                || (
                    null !== $endsAt
                    && $endsAt <= $now
                )
            ) {
                continue;
            }

            return $window;
        }

        return null;
    }

    public static function cacheKeyFor(DateTimeImmutable $startOfDay): string
    {
        return self::CACHE_KEY_PREFIX . $startOfDay->format('Y-m-d');
    }

    /**
     * Every other window whose interval clashes with the given one, so the form can refuse an overlapping schedule.
     *
     * @return MaintenanceWindow[]
     */
    public function findOverlapping(MaintenanceWindow $window): array
    {
        $id = $window->getId();
        if (null === $id) {
            $others = $this->findAll();
        } else {
            $others = $this->createQueryBuilder('w')
                ->where('w.id != :id')
                ->setParameter(
                    'id',
                    $id,
                )
                ->getQuery()
                ->getResult();
        }

        return array_values(array_filter(
            $others,
            static fn (MaintenanceWindow $other): bool => $window->overlaps($other),
        ));
    }
}
