<?php

declare(strict_types=1);

namespace App\Repository\User;

use App\Entity\Decision\Member;
use App\Entity\User\ExternalApp;
use App\Entity\User\ExternalAppAuthentication;
use App\Entity\User\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SortDirection;

/**
 * @extends ServiceEntityRepository<ExternalAppAuthentication>
 * @phpstan-type ExternalAppsArrayType = list<array{
 *      0: ExternalApp|null,
 *      firstAuthentication: string,
 *      lastAuthentication: string,
 *  }>
 * @phpstan-type ExternalAppsArrayType = list<array{
 *      0: ExternalApp|null,
 *      firstAuthentication: string,
 *      lastAuthentication: string,
 *  }>
 */
class ExternalAppAuthenticationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(
            $registry,
            ExternalAppAuthentication::class,
        );
    }

    /**
     * @return ExternalAppsArrayType
     */
    public function getFirstAndLastAuthenticationPerExternalApp(Member $member): array
    {
        // Correlated subqueries rather than a GROUP BY or DISTINCT: PostgreSQL has no equality operator for the
        // json claims column, so neither can compare whole rows. EXISTS keeps each app to one row, and the
        // aliases are unique to the whole query, so the subqueries do not share one.
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select([
            'e',
            '(SELECT MIN(x1.time) FROM ' . ExternalAppAuthentication::class . ' x1'
            . ' WHERE x1.externalApp = e.id AND x1.user = :user_id) AS firstAuthentication',
            '(SELECT MAX(x2.time) FROM ' . ExternalAppAuthentication::class . ' x2'
            . ' WHERE x2.externalApp = e.id AND x2.user = :user_id) AS lastAuthentication',
        ])
            ->from(
                ExternalApp::class,
                'e',
            )
            ->where(
                'EXISTS (SELECT 1 FROM ' . ExternalAppAuthentication::class . ' a3'
                . ' WHERE a3.externalApp = e.id AND a3.user = :user_id)',
            )
            ->setParameter(
                'user_id',
                $member->lidnr,
            );

        return $qb->getQuery()->getResult();
    }

    public function getLastAuthentication(
        User $user,
        ExternalApp $app,
    ): ?ExternalAppAuthentication {
        $qb = $this->createQueryBuilder('a');
        $qb->where('a.externalApp = :app_id')
            ->andWhere('a.user = :user_id')
            ->orderBy(
                'a.time',
                SortDirection::Descending,
            )
            ->setMaxResults(1)
            ->setParameter(
                'app_id',
                $app->id,
            )
            ->setParameter(
                'user_id',
                $user->lidnr,
            );

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * @return ExternalAppAuthentication[]
     */
    public function getMemberAuthenticationsPerExternalApp(Member $member): array
    {
        // The latest authentication per app, what a grouped select used to produce, as a subquery: PostgreSQL
        // refuses a `GROUP BY` over a non-aggregated entity.
        $qb = $this->createQueryBuilder('a');
        $qb->select('a')
            ->where('a.user = :user_id')
            ->andWhere(
                'a.id IN (SELECT MAX(x.id) FROM ' . ExternalAppAuthentication::class . ' x'
                . ' WHERE x.user = :user_id GROUP BY x.externalApp)',
            )
            ->orderBy(
                'a.time',
                SortDirection::Descending,
            )
            ->setParameter(
                'user_id',
                $member->lidnr,
            );

        return $qb->getQuery()->getResult();
    }
}
