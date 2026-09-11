<?php

declare(strict_types=1);

namespace App\Entity\Decision\SubDecision\Member;

use App\Doctrine\Query\Queryable;
use App\Entity\Decision\Member;
use App\Entity\Decision\SubDecision;
use App\Entity\Decision\Traits\MemberAwareTrait;
use App\Repository\Decision\SubDecision\Member\SuspensionRepository;
use DateTime;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;

use function assert;

/**
 * The suspension of a member, for a period that both ends are part of.
 */
#[Entity(repositoryClass: SuspensionRepository::class)]
#[Queryable]
class Suspension extends SubDecision
{
    use MemberAwareTrait;

    /**
     * The first day of the suspension.
     *
     * Named `since` rather than `from`, which is a reserved word in both of the databases this table lives in and
     * would have to be quoted everywhere it is read.
     */
    #[Column(type: Types::DATE_MUTABLE)]
    public DateTime $since;

    /**
     * The last day of the suspension, which is part of it.
     */
    #[Column(type: Types::DATE_MUTABLE)]
    public DateTime $until;

    /**
     * Get the member who is suspended.
     */
    public function getMember(): Member
    {
        // The trait keeps the association nullable for mapping reasons; a suspension always names the member it is
        // handed to.
        assert(null !== $this->member);

        return $this->member;
    }
}
