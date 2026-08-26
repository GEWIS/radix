<?php

declare(strict_types=1);

namespace App\Repository\Database\SubDecision\Board;

use App\Entity\Database\SubDecision\Board\Candidate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Candidate>
 */
class CandidateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(
            $registry,
            Candidate::class,
        );
    }
}
