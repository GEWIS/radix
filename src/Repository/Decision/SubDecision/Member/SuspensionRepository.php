<?php

declare(strict_types=1);

namespace App\Repository\Decision\SubDecision\Member;

use App\Entity\Decision\SubDecision\Member\Suspension;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Suspension>
 */
class SuspensionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(
            $registry,
            Suspension::class,
        );
    }
}
