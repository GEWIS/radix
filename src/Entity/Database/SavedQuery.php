<?php

declare(strict_types=1);

namespace App\Entity\Database;

use App\Repository\Database\SavedQueryRepository;
use Doctrine\DBAL\Types\Types;
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
    #[Column(type: Types::INTEGER)]
    #[GeneratedValue(strategy: 'AUTO')]
    public ?int $id = null;

    /**
     * Category.
     */
    #[Column(type: Types::STRING)]
    public string $category;

    /**
     * Name.
     */
    #[Column(type: Types::STRING)]
    public string $name;

    /**
     * The Saved Query.
     */
    #[Column(type: Types::TEXT)]
    public string $query;
}
