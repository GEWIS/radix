<?php

declare(strict_types=1);

namespace App\Repository\Database\SubDecision;

use App\Entity\Database\SubDecision\Continuation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Continuation>
 */
class ContinuationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(
            $registry,
            Continuation::class,
        );
    }
}
