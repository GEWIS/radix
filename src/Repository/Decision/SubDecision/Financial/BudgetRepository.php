<?php

declare(strict_types=1);

namespace App\Repository\Decision\SubDecision\Financial;

use App\Entity\Decision\SubDecision\Financial\Budget;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Budget>
 */
class BudgetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(
            $registry,
            Budget::class,
        );
    }
}
