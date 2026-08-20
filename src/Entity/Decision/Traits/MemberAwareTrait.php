<?php

declare(strict_types=1);

namespace App\Entity\Decision\Traits;

use App\Entity\Decision\Member;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;

trait MemberAwareTrait
{
    /**
     * The member involved in this sub-decision.
     *
     * Not all sub-decisions require this, and they share one column in one table, so it is nullable here. A
     * sub-decision that needs the guarantee that it is not null, or that has an inverse side to name, declares the
     * association itself instead of using this trait; {@see \App\Entity\Decision\SubDecision\Installation} is the
     * one that does. For why an override is not the answer, see {@link https://github.com/doctrine/orm/pull/10470}.
     *
     * Deliberately without an `onDelete`: a sub-decision names the member the association decided something about,
     * and neither dropping the sub-decision nor blanking the name is an outcome a member's removal may reach on its
     * own. The constraint refusing the removal is the point — it means the member still has a decision behind them
     * and belongs in the archive rather than out of it.
     */
    #[ManyToOne(targetEntity: Member::class)]
    #[JoinColumn(
        name: 'lidnr',
        referencedColumnName: 'lidnr',
        nullable: true,
    )]
    private ?Member $member = null;

    /**
     * Get the member.
     */
    public function getMember(): ?Member
    {
        return $this->member;
    }

    /**
     * Set the member.
     */
    public function setMember(Member $member): void
    {
        $this->member = $member;
    }
}
