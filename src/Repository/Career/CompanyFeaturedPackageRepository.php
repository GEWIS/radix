<?php

declare(strict_types=1);

namespace App\Repository\Career;

use App\Entity\Career\CompanyFeaturedPackage;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

use function array_filter;
use function array_rand;
use function array_values;

/**
 * @extends ServiceEntityRepository<CompanyFeaturedPackage>
 */
class CompanyFeaturedPackageRepository extends ServiceEntityRepository
{
    public const string CACHE_KEY_PREFIX = 'layout.featured_packages.';

    /** A day, so the entry cannot outlive the day it was asked about even if nothing invalidates it. */
    private const int CACHE_LIFETIME = 86400;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(
            $registry,
            CompanyFeaturedPackage::class,
        );
    }

    /**
     * Returns a random featured package from the active featured packages,
     * and null when there is no featured package.
     */
    public function getFeaturedPackage(): ?CompanyFeaturedPackage
    {
        // The company is read wherever the pick is shown (the navigation menu names it, the career page also renders
        // the article), so both come along instead of being lazy-loaded on every page that draws the menu.
        $today = new DateTimeImmutable()->setTime(
            0,
            0,
        );

        // The candidates are cached rather than the pick, because the pick is random and rotates between page
        // loads. Applying the day's window here keeps a package starting or expiring on the date it is given.
        $featuredPackages = array_values(
            array_filter(
                $this->findCandidatesOn($today),
                static fn (CompanyFeaturedPackage $package): bool => DateTimeImmutable::createFromMutable(
                    $package->getStartingDate(),
                ) <= $today,
            ),
        );

        if ([] !== $featuredPackages) {
            return $featuredPackages[array_rand($featuredPackages)];
        }

        return null;
    }

    /**
     * Every published package that has not expired as of the given day, whether or not it has started yet.
     *
     * Cached by the day, as the layout's other queries are. The company and the article are fetch-joined because
     * both are read wherever the pick is shown.
     *
     * @return CompanyFeaturedPackage[]
     */
    public function findCandidatesOn(DateTimeImmutable $day): array
    {
        $startOfDay = $day->setTime(
            0,
            0,
        );

        return $this->createQueryBuilder('p')
            ->addSelect(
                'c',
                'article',
            )
            ->join(
                'p.company',
                'c',
            )
            ->leftJoin(
                'p.article',
                'article',
            )
            ->where('p.published = 1')
            ->andWhere('p.expires > :startOfDay')
            ->setParameter(
                'startOfDay',
                $startOfDay,
            )
            ->getQuery()
            ->enableResultCache(
                self::CACHE_LIFETIME,
                self::cacheKeyFor($startOfDay),
            )
            ->getResult();
    }

    public static function cacheKeyFor(DateTimeImmutable $startOfDay): string
    {
        return self::CACHE_KEY_PREFIX . $startOfDay->format('Y-m-d');
    }
}
