<?php

declare(strict_types=1);

namespace App\Repository\Database\SubDecision\Member;

use App\Entity\Database\SubDecision\Member\Warning;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Warning>
 */
class WarningRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(
            $registry,
            Warning::class,
        );
    }
}
