<?php

declare(strict_types=1);

namespace App\Repository\Decision\SubDecision;

use App\Entity\Decision\SubDecision\Foundation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Foundation>
 */
class FoundationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(
            $registry,
            Foundation::class,
        );
    }
}
