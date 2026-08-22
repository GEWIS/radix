<?php

declare(strict_types=1);

namespace App\Repository\Decision\SubDecision;

use App\Entity\Decision\SubDecision\Annulment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Annulment>
 */
class AnnulmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(
            $registry,
            Annulment::class,
        );
    }
}
