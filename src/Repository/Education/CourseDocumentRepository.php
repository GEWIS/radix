<?php

declare(strict_types=1);

namespace App\Repository\Education;

use App\Entity\Education\Course;
use App\Entity\Education\CourseDocument;
use App\Entity\Education\Enums\DocumentFlattenStatus;
use App\Entity\Education\Exam;
use App\Entity\Education\Summary;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CourseDocument>
 */
class CourseDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(
            $registry,
            CourseDocument::class,
        );
    }

    /**
     * @phpstan-param class-string<Exam>|class-string<Summary> $type
     *
     * @return CourseDocument[]
     */
    public function findDocumentsByCourse(
        Course $course,
        string $type,
    ): array {
        $qb = $this->createQueryBuilder('d');
        $qb->where('d.course = :course')
            ->andWhere('d INSTANCE OF :type')
            ->setParameter(
                'course',
                $course,
                Course::class,
            )
            ->setParameter(
                'type',
                $this->getEntityManager()->getClassMetadata($type),
            );

        return $qb->getQuery()->getResult();
    }

    /**
     * Ordered by the date on the document rather than when it was uploaded: a member browsing for material cares which
     * exam is the newest, not which one an administrator happened to file last.
     *
     * @return CourseDocument[]
     */
    public function findRecent(int $limit): array
    {
        $qb = $this->createQueryBuilder('d')
            ->innerJoin(
                'd.course',
                'c',
            )
            ->addSelect('c')
            ->orderBy(
                'd.date',
                'DESC',
            )
            ->addOrderBy(
                'd.id',
                'DESC',
            )
            ->setMaxResults($limit);

        return $qb->getQuery()->getResult();
    }

    /**
     * The queue the education admin leads with: a document nobody can open is the one thing on those pages that needs
     * somebody to do something.
     *
     * @return Paginator<CourseDocument>
     */
    public function paginateNotReady(
        int $page,
        int $pageSize,
    ): Paginator {
        $qb = $this->notReadyQueryBuilder()
            ->setFirstResult(($page - 1) * $pageSize)
            ->setMaxResults($pageSize);

        return new Paginator($qb);
    }

    /**
     * How many documents are still on their way to being downloadable. Counted on its own because the tile that shows
     * it sits next to a paginated table that no longer knows the total.
     */
    public function countNotReady(): int
    {
        // The shared builder orders by status for the table's benefit. On a count that sorts nothing, and it is a
        // non-aggregated column that a strict `ONLY_FULL_GROUP_BY` would reject outright, so it goes first.
        return (int) $this->notReadyQueryBuilder()
            ->select('COUNT(d.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function notReadyQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('d')
            ->innerJoin(
                'd.course',
                'c',
            )
            ->addSelect('c')
            ->where('d.flattenStatus != :ready')
            ->setParameter(
                'ready',
                DocumentFlattenStatus::Ready,
            )
            ->orderBy(
                'd.flattenStatus',
                'ASC',
            )
            ->addOrderBy(
                'd.id',
                'DESC',
            );
    }

    /**
     * Oldest first, so the backfill works through them in a stable order.
     *
     * @param DocumentFlattenStatus[] $statuses
     *
     * @return CourseDocument[]
     */
    public function findByFlattenStatus(
        array $statuses,
        ?int $limit = null,
    ): array {
        $qb = $this->createQueryBuilder('d')
            ->where('d.flattenStatus IN (:statuses)')
            ->setParameter(
                'statuses',
                $statuses,
            )
            ->orderBy(
                'd.id',
                'ASC',
            );

        if (null !== $limit) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Whether any document still points at the given stored path. Uploads are content-addressed, so the same PDF filed
     * under two courses is one stored file.
     */
    public function isPathReferenced(string $path): bool
    {
        $qb = $this->createQueryBuilder('d');
        $qb->select('1')
            ->where('d.path = :path')
            ->setParameter(
                'path',
                $path,
            )
            ->setMaxResults(1);

        return null !== $qb->getQuery()->getOneOrNullResult();
    }
}
