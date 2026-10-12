<?php

declare(strict_types=1);

namespace App\Repository\Database;

use App\Entity\Database\AuditAddressChange;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditAddressChange>
 */
class AuditAddressChangeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(
            $registry,
            AuditAddressChange::class,
        );
    }
}
