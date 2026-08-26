<?php

declare(strict_types=1);

namespace App\Entity\Decision\SubDecision;

use App\Doctrine\Query\Queryable;
use App\Repository\Decision\SubDecision\ContinuationRepository;
use Doctrine\ORM\Mapping\Entity;

/**
 * The continuation of a body.
 */
#[Entity(repositoryClass: ContinuationRepository::class)]
#[Queryable]
class Continuation extends FoundationReference
{
}
