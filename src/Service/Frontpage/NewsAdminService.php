<?php

declare(strict_types=1);

namespace App\Service\Frontpage;

use App\Entity\Frontpage\NewsItem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Writing and removing a news item. The counterpart of {@see \App\Service\Frontpage\HomePageService}, which is what
 * reads them back out onto the front page.
 *
 * `save()` covers both a new item and an edit to one that already exists: persisting something the entity manager is
 * already tracking does nothing, so the two cases do not need to be told apart here.
 */
final readonly class NewsAdminService
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(NewsItem $item): void
    {
        $this->entityManager->persist($item);
        $this->entityManager->flush();
    }

    public function delete(NewsItem $item): void
    {
        $this->entityManager->remove($item);
        $this->entityManager->flush();
    }
}
