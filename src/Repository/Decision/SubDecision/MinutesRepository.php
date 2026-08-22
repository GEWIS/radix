<?php

declare(strict_types=1);

namespace App\Repository\Decision\SubDecision;

use App\Entity\Decision\SubDecision\Minutes;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Minutes>
 */
class MinutesRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(
            $registry,
            Minutes::class,
        );
    }
}
