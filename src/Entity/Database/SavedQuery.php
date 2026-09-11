<?php

declare(strict_types=1);

namespace App\Entity\Database;

use App\Repository\Database\SavedQueryRepository;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;

/**
 * Saved query model.
 */
#[Entity(repositoryClass: SavedQueryRepository::class)]
class SavedQuery
{
    /**
     * The query ID.
     */
    #[Id]
    #[Column(type: 'integer')]
    #[GeneratedValue(strategy: 'AUTO')]
    public ?int $id = null;

    /**
     * Category.
     */
    #[Column(type: 'string')]
    public string $category;

    /**
     * Name.
     */
    #[Column(type: 'string')]
    public string $name;

    /**
     * The Saved Query.
     */
    #[Column(type: 'text')]
    public string $query;
}
