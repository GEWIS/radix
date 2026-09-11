<?php

declare(strict_types=1);

namespace App\Repository\User;

use App\Entity\User\Enums\SecurityEventCategory;
use App\Entity\User\Enums\SecurityEventType;
use App\Entity\User\SecurityLog;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;
use SortDirection;

use function array_filter;
use function array_map;
use function array_values;
use function json_encode;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * @extends ServiceEntityRepository<SecurityLog>
 */
class SecurityLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(
            $registry,
            SecurityLog::class,
        );
    }

    /**
     * Write one row, deliberately around the ORM.
     *
     * Recording happens *during* other work, inside a security listener or halfway through a controller with
     * entities part-built, where `persist()` plus `flush()` would write out whatever else is in the unit of work. A
     * plain insert writes to this table only and leaves the caller's state unchanged.
     *
     * It still joins whatever transaction is open, so a row can be rolled back with the request that wrote it. That
     * is what the `security` log channel is for: the file keeps the copy the database discards.
     */
    public function append(SecurityLog $log): void
    {
        $metadata = $this->getClassMetadata();

        $this->getEntityManager()->getConnection()->insert(
            $metadata->getTableName(),
            [
                $metadata->getColumnName('occurredAt') => $log->getOccurredAt(),
                $metadata->getColumnName('event') => $log->getEvent()->value,
                $metadata->getColumnName('userIdentifier') => $log->getUserIdentifier(),
                $metadata->getColumnName('firewallName') => $log->getFirewallName(),
                $metadata->getColumnName('actorIdentifier') => $log->getActorIdentifier(),
                $metadata->getColumnName('ipAddress') => $log->getIpAddress(),
                $metadata->getColumnName('browser') => $log->getBrowser(),
                $metadata->getColumnName('operatingSystem') => $log->getOperatingSystem(),
                $metadata->getColumnName('requestId') => $log->getRequestId(),
                $metadata->getColumnName('detail') => json_encode(
                    $log->getDetail(),
                    JSON_THROW_ON_ERROR,
                ),
            ],
            [
                $metadata->getColumnName('occurredAt') => Types::DATETIME_IMMUTABLE,
                $metadata->getColumnName('event') => Types::STRING,
                $metadata->getColumnName('userIdentifier') => Types::STRING,
                $metadata->getColumnName('firewallName') => Types::STRING,
                $metadata->getColumnName('actorIdentifier') => Types::STRING,
                $metadata->getColumnName('ipAddress') => Types::STRING,
                $metadata->getColumnName('browser') => Types::STRING,
                $metadata->getColumnName('operatingSystem') => Types::STRING,
                $metadata->getColumnName('requestId') => Types::STRING,
                $metadata->getColumnName('detail') => Types::STRING,
            ],
        );
    }

    /**
     * What happened to one account, most recent first. Used by the administration and, through
     * {@see \App\Service\User\GdprService}, by the member's own data export.
     *
     * @return SecurityLog[]
     */
    public function findAllByUser(
        string $userIdentifier,
        ?int $limit = null,
    ): array {
        return $this->findBy(
            ['userIdentifier' => $userIdentifier],
            [
                'occurredAt' => 'DESC',
                'id' => 'DESC',
            ],
            $limit,
        );
    }

    /**
     * The administration's overview. Every filter is optional and they narrow together.
     *
     * @param list<SecurityEventType> $events
     *
     * @return Paginator<SecurityLog>
     */
    public function paginateForAdmin(
        string $search,
        ?SecurityEventCategory $category,
        array $events,
        ?DateTimeImmutable $since,
        int $page,
        int $pageSize,
    ): Paginator {
        $qb = $this->createQueryBuilder('l')
            ->orderBy(
                'l.occurredAt',
                SortDirection::Descending,
            )
            ->addOrderBy(
                'l.id',
                SortDirection::Descending,
            );

        $search = trim($search);
        if ('' !== $search) {
            // The account, whoever acted on it, and the address it came from: the three things somebody already
            // knows when they open this page.
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->eq(
                        'l.userIdentifier',
                        ':search',
                    ),
                    $qb->expr()->eq(
                        'l.actorIdentifier',
                        ':search',
                    ),
                    $qb->expr()->eq(
                        'l.ipAddress',
                        ':search',
                    ),
                ),
            )->setParameter(
                'search',
                $search,
                Types::STRING,
            );
        }

        // A category is a set of event types; resolving it here keeps the column a plain string the index can answer
        // on, rather than something the database has to classify per row.
        $selected = [] !== $events
            ? $events
            : (null !== $category ? self::eventsIn($category) : []);

        if ([] !== $selected) {
            $qb->andWhere(
                $qb->expr()->in(
                    'l.event',
                    ':events',
                ),
            )->setParameter(
                'events',
                array_map(
                    static fn (SecurityEventType $event): string => $event->value,
                    $selected,
                ),
            );
        }

        if (null !== $since) {
            $qb->andWhere(
                $qb->expr()->gte(
                    'l.occurredAt',
                    ':since',
                ),
            )->setParameter(
                'since',
                $since,
                Types::DATETIME_IMMUTABLE,
            );
        }

        $qb->setFirstResult(($page - 1) * $pageSize)
            ->setMaxResults($pageSize);

        // No collection is joined, so the paginator has no rows to de-duplicate and can count without the extra
        // subquery it would otherwise wrap the whole thing in.
        return new Paginator(
            $qb,
            false,
        );
    }

    /**
     * Everything older than the retention period. The one thing that keeps this table from growing without end, and
     * the reason we may keep as much per row as we do.
     */
    public function deleteOccurredBefore(DateTimeImmutable $before): int
    {
        return $this->createQueryBuilder('l')
            ->delete()
            ->where('l.occurredAt < :before')
            ->setParameter(
                'before',
                $before,
                Types::DATETIME_IMMUTABLE,
            )
            ->getQuery()
            ->execute();
    }

    /**
     * Forget an account's history outright, for a member whose account is removed. The rows that named them as the
     * actor rather than the subject are kept: they belong to whoever the action was done to, who is still entitled
     * to know it happened.
     */
    public function deleteAllForUser(string $userIdentifier): int
    {
        return $this->createQueryBuilder('l')
            ->delete()
            ->where('l.userIdentifier = :uid')
            ->setParameter(
                'uid',
                $userIdentifier,
                Types::STRING,
            )
            ->getQuery()
            ->execute();
    }

    /**
     * @return list<SecurityEventType>
     */
    private static function eventsIn(SecurityEventCategory $category): array
    {
        return array_values(array_filter(
            SecurityEventType::cases(),
            static fn (SecurityEventType $event): bool => $event->category() === $category,
        ));
    }
}
