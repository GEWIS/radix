<?php

declare(strict_types=1);

namespace App\Repository\User;

use App\Entity\Database\User\ApiPrincipal;
use DateTime;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SensitiveParameter;
use Throwable;

use function count;

/**
 * @extends ServiceEntityRepository<ApiPrincipal>
 */
class ApiPrincipalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(
            $registry,
            ApiPrincipal::class,
        );
    }

    public function findByToken(
        #[SensitiveParameter]
        string $token,
    ): ?ApiPrincipal {
        /** @var ApiPrincipal[] $results */
        $results = $this->findBy(
            ['tokenHash' => ApiPrincipal::hash($token)],
            limit: 1,
        );

        return count($results) > 0
            ? $results[0]
            : null;
    }

    /**
     * Bookkeeping nobody waits on: it runs on every authenticated request, so a write that fails must not take the
     * request with it. Written straight through the connection rather than the unit of work, which would flush
     * whatever else is pending and let `TimestampableTrait::preUpdate()` rewrite the administrative `updatedAt`.
     */
    public function stampUsage(ApiPrincipal $principal): void
    {
        $today = new DateTime('today');
        $id = $principal->getId();

        if (
            null === $id
            || $today->format('Y-m-d') === $principal->getLastUsedAt()?->format('Y-m-d')
        ) {
            return;
        }

        $principal->markUsedOn($today);

        $manager = $this->getEntityManager();
        $metadata = $manager->getClassMetadata(ApiPrincipal::class);

        try {
            $manager->getConnection()->update(
                $metadata->getTableName(),
                [$metadata->getColumnName('lastUsedAt') => $today->format('Y-m-d')],
                [$metadata->getColumnName('id') => $id],
            );
        } catch (Throwable) {
            // A missed timestamp is not worth a failed request.
        }
    }

    public function persist(ApiPrincipal $principal): void
    {
        $this->getEntityManager()->persist($principal);
        $this->getEntityManager()->flush();
    }

    public function remove(ApiPrincipal $principal): void
    {
        $this->getEntityManager()->remove($principal);
        $this->getEntityManager()->flush();
    }
}
