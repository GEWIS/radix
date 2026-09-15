<?php

declare(strict_types=1);

namespace App\Entity\Decision\SubDecision\Financial;

use App\Doctrine\Query\Queryable;
use App\Entity\Decision\SubDecision;
use App\Entity\Decision\Traits\MemberAwareTrait;
use App\Repository\Decision\SubDecision\Financial\BudgetRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;

/**
 * Budget decision.
 */
#[Entity(repositoryClass: BudgetRepository::class)]
#[Queryable]
class Budget extends SubDecision
{
    use MemberAwareTrait;

    /**
     * Name of the budget.
     */
    #[Column(type: Types::STRING)]
    public string $name;

    /**
     * Version of the budget.
     */
    #[Column(
        type: Types::STRING,
        length: 32,
    )]
    public string $version;

    /**
     * Date of the budget.
     */
    #[Column(type: Types::DATE_IMMUTABLE)]
    public DateTimeImmutable $date;

    /**
     * If the budget was approved.
     */
    #[Column(type: Types::BOOLEAN)]
    public bool $approval;

    /**
     * If there were changes made.
     */
    #[Column(type: Types::BOOLEAN)]
    public bool $changes;
}
