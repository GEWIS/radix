<?php

declare(strict_types=1);

namespace App\Service\Activity;

use App\Entity\Activity\ProposalLimit;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Writing and removing the allowance a body has for a period. The counterpart of {@see ProposalLimitResolver}, which
 * is what reads it back.
 *
 * `save()` covers both a new limit and an edit to one that already exists: persisting something the entity manager is
 * already tracking does nothing, so the two cases do not need to be told apart here.
 */
final readonly class ProposalLimitService
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(ProposalLimit $limit): void
    {
        $this->entityManager->persist($limit);
        $this->entityManager->flush();
    }

    public function delete(ProposalLimit $limit): void
    {
        $this->entityManager->remove($limit);
        $this->entityManager->flush();
    }
}
