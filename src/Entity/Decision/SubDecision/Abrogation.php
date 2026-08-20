<?php

declare(strict_types=1);

namespace App\Entity\Decision\SubDecision;

use App\Doctrine\Query\Queryable;
use App\Repository\Decision\SubDecision\AbrogationRepository;
use Doctrine\ORM\Mapping\Entity;

/**
 * Abrogation of an organ.
 */
#[Entity(repositoryClass: AbrogationRepository::class)]
#[Queryable]
class Abrogation extends FoundationReference
{
}
