<?php

declare(strict_types=1);

namespace App\Entity\Decision\SubDecision\Financial;

use App\Doctrine\Query\Queryable;
use App\Repository\Decision\SubDecision\Financial\StatementRepository;
use Doctrine\ORM\Mapping\Entity;

#[Entity(repositoryClass: StatementRepository::class)]
#[Queryable]
class Statement extends Budget
{
}
