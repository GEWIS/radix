<?php

declare(strict_types=1);

namespace App\Entity\Application\Traits;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;

/**
 * A trait which provides an `id` column for entities.
 *
 * Doctrine assigns the identifier on insert, through reflection, and writing it from anywhere else would in most
 * instances result in undefined behaviour. The set visibility says so rather than a comment asking for caution:
 * nothing outside the entity can write `$entity->id`, and neither can Symfony's PropertyAccessor, which is what a
 * form binds through and what resolves a property path by name. A test that needs an entity to carry an identifier
 * without persisting it writes the property by reflection, as the ones setting the hand-rolled identifiers do.
 *
 * `protected(set)` rather than the `private(set)` of those hand-rolled ones because
 * {@see \App\Entity\Photo\VirtualAlbum} is an album that is never stored and assigns the identifier it stands for in
 * its own constructor.
 */
trait IdentifiableTrait
{
    /**
     * The default value must be `null` to prevent issues with auto generating the value. The column is strictly not
     * nullable.
     */
    #[Id]
    #[Column(type: Types::INTEGER)]
    #[GeneratedValue(strategy: 'IDENTITY')]
    public protected(set) ?int $id = null;
}
