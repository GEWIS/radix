<?php

declare(strict_types=1);

namespace App\Repository\Application;

use App\Entity\Application\AbstractRevision;
use App\Entity\Application\Enums\RevisionStatus;
use DateTime;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The one query the stale-revision cleanup runs, asked of whichever revision entity it is pointed at. Every revisable
 * domain maps its chain the same way — a status, a stamp of when it was last written to — so the query is written
 * once here rather than five times in five repositories.
 *
 * Whether a row is still the working head of its aggregate is deliberately not asked of the database: the association
 * back to the aggregate is named after the domain (`activity`, `poll`, ...), and once the row is loaded the question
 * is one comparison. A superseded revision therefore comes back and is dropped by the caller, which is correct either
 * way: history behind a live version is not abandoned work.
 */
final readonly class StaleRevisionRepository
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Every revision of the given class that was never approved and has not been written to since the cutoff, oldest
     * first. An approved revision is never returned: the live version of something is not abandoned, it is finished.
     *
     * @param class-string<AbstractRevision> $revisionClass
     *
     * @return list<AbstractRevision>
     */
    public function findUntouchedSince(
        string $revisionClass,
        DateTime $cutoff,
    ): array {
        /** @var list<AbstractRevision> $revisions */
        $revisions = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(
                $revisionClass,
                'r',
            )
            ->where('r.status <> :approved')
            ->andWhere('r.updatedAt <= :cutoff')
            ->setParameter(
                'approved',
                RevisionStatus::Approved->value,
            )
            ->setParameter(
                'cutoff',
                $cutoff,
                Types::DATETIME_MUTABLE,
            )
            ->orderBy(
                'r.updatedAt',
                'ASC',
            )
            ->getQuery()
            ->getResult();

        return $revisions;
    }
}
