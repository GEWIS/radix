<?php

declare(strict_types=1);

namespace App\Entity\Decision\SubDecision\Board;

use App\Doctrine\Query\Queryable;
use App\Entity\Decision\SubDecision;
use App\Repository\Decision\SubDecision\Board\CandidacyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;

/**
 * That the board puts candidates forward for the board of an association year.
 */
#[Entity(repositoryClass: CandidacyRepository::class)]
#[Queryable]
class Candidacy extends SubDecision
{
    /**
     * The first calendar year of the association year the candidates stand for.
     */
    #[Column(type: Types::INTEGER)]
    public int $boardYear;
}
