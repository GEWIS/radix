<?php

declare(strict_types=1);

namespace App\Repository\Database;

use App\Entity\Database\AuditEmailChange;
use App\Entity\Database\AuditEntry;
use App\Entity\Database\AuditMailingListMembership;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditEntry>
 */
class AuditEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(
            $registry,
            AuditEntry::class,
        );
    }

    public function persist(AuditEntry $entry): void
    {
        $entry->assertValid();
        $this->getEntityManager()->persist($entry);
        $this->getEntityManager()->flush();
    }

    public function remove(AuditEntry $entry): void
    {
        $this->getEntityManager()->remove($entry);
        $this->getEntityManager()->flush();
    }

    /**
     * Personal-data change entries older than the retention period. The entries remain, but their personal data
     * (old/new email addresses, mailing list emails) is set to null. The audit trail is preserved — we know a change
     * happened, but not what it changed from and to.
     */
    public function anonymizePersonalDataEntriesOccurredBefore(DateTimeImmutable $before): int
    {
        $em = $this->getEntityManager();

        $anonymized = 0;

        // Email change entries: clear the old and new email addresses
        $anonymized += $em
            ->createQueryBuilder()
            ->update(
                AuditEmailChange::class,
                'e',
            )
            ->set(
                'e.oldEmail',
                'null',
            )
            ->set(
                'e.newEmail',
                'null',
            )
            ->where('e.createdAt < :before')
            ->setParameter(
                'before',
                $before,
            )
            ->getQuery()
            ->execute();

        // Mailing list membership entries: clear the email
        $anonymized += $em
            ->createQueryBuilder()
            ->update(
                AuditMailingListMembership::class,
                'e',
            )
            ->set(
                'e.email',
                'null',
            )
            ->where('e.createdAt < :before')
            ->setParameter(
                'before',
                $before,
            )
            ->getQuery()
            ->execute();

        return $anonymized;
    }
}
