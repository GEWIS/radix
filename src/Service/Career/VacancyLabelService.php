<?php

declare(strict_types=1);

namespace App\Service\Career;

use App\Entity\Career\VacancyLabel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Writing and removing a vacancy label.
 *
 * `save()` covers both a new label and an edit to one that already exists: persisting something the entity manager is
 * already tracking does nothing, so the two cases do not need to be told apart here.
 */
final readonly class VacancyLabelService
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(VacancyLabel $label): void
    {
        $this->entityManager->persist($label);
        $this->entityManager->flush();
    }

    public function delete(VacancyLabel $label): void
    {
        $this->entityManager->remove($label);
        $this->entityManager->flush();
    }
}
