<?php

declare(strict_types=1);

namespace App\Service\Activity;

use App\Entity\Activity\OptionPeriod;
use App\Entity\Activity\PeriodProposalLimit;
use App\Repository\Activity\PeriodProposalLimitRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Writing and removing the periods bodies may propose activities in, and the per-body allowances that hang off them.
 *
 * A period takes its allowances with it when it goes: they have no meaning without the period they were set for, and
 * they carry no cascade of their own, so they are removed in the same commit rather than left behind as rows nothing
 * points at.
 *
 * `save()` covers both a new record and an edit to one that already exists: persisting something the entity manager is
 * already tracking does nothing, so the two cases do not need to be told apart here.
 */
final readonly class OptionPeriodService
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private EntityManagerInterface $entityManager,
        private PeriodProposalLimitRepository $periodProposalLimitRepository,
    ) {
    }

    public function save(OptionPeriod $period): void
    {
        $this->entityManager->persist($period);
        $this->entityManager->flush();
    }

    public function delete(OptionPeriod $period): void
    {
        foreach ($this->periodProposalLimitRepository->findForPeriod($period) as $limit) {
            $this->entityManager->remove($limit);
        }

        $this->entityManager->remove($period);
        $this->entityManager->flush();
    }

    public function saveLimit(PeriodProposalLimit $limit): void
    {
        $this->entityManager->persist($limit);
        $this->entityManager->flush();
    }

    public function deleteLimit(PeriodProposalLimit $limit): void
    {
        $this->entityManager->remove($limit);
        $this->entityManager->flush();
    }
}
