<?php

declare(strict_types=1);

namespace App\Entity\Decision\SubDecision;

use App\Doctrine\Query\Queryable;
use App\Entity\Decision\SubDecision;
use App\Repository\Decision\SubDecision\OtherRepository;
use Doctrine\ORM\Mapping\Entity;

/**
 * Entity for undefined decisions.
 */
#[Entity(repositoryClass: OtherRepository::class)]
#[Queryable]
class Other extends SubDecision
{
}
