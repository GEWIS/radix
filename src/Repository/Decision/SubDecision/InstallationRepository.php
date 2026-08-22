<?php

declare(strict_types=1);

namespace App\Repository\Decision\SubDecision;

use App\Entity\Decision\SubDecision\Installation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Installation>
 */
class InstallationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(
            $registry,
            Installation::class,
        );
    }
}
