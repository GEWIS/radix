<?php

declare(strict_types=1);

namespace App\Service\Application;

use App\Entity\Application\LabelInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class LabelService
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(LabelInterface $label): void
    {
        $this->entityManager->persist($label);
        $this->entityManager->flush();
    }

    public function delete(LabelInterface $label): void
    {
        $this->entityManager->remove($label);
        $this->entityManager->flush();
    }

    public function retire(LabelInterface $label): void
    {
        $label->retire();
        $this->entityManager->flush();
    }

    public function restore(LabelInterface $label): void
    {
        $label->restore();
        $this->entityManager->flush();
    }
}
