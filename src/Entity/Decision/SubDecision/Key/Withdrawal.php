<?php

declare(strict_types=1);

namespace App\Entity\Decision\SubDecision\Key;

use App\Doctrine\Query\Queryable;
use App\Entity\Decision\SubDecision;
use App\Repository\Decision\SubDecision\Key\WithdrawalRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToOne;

#[Entity(repositoryClass: WithdrawalRepository::class)]
#[Queryable]
class Withdrawal extends SubDecision
{
    /**
     * Reference to the granting of a keycode.
     */
    #[OneToOne(
        targetEntity: Granting::class,
        inversedBy: 'withdrawal',
    )]
    #[JoinColumn(
        name: 'r_meeting_type',
        referencedColumnName: 'meeting_type',
    )]
    #[JoinColumn(
        name: 'r_meeting_number',
        referencedColumnName: 'meeting_number',
    )]
    #[JoinColumn(
        name: 'r_decision_point',
        referencedColumnName: 'decision_point',
    )]
    #[JoinColumn(
        name: 'r_decision_number',
        referencedColumnName: 'decision_number',
    )]
    #[JoinColumn(
        name: 'r_sequence',
        referencedColumnName: 'sequence',
    )]
    public Granting $granting;

    /**
     * When the granted keycode is prematurely revoked.
     */
    #[Column(type: Types::DATE_IMMUTABLE)]
    public DateTimeImmutable $withdrawnOn;
}
