<?php

declare(strict_types=1);

namespace App\Entity\Decision\SubDecision\Key;

use App\Doctrine\Query\Queryable;
use App\Entity\Decision\Keyholder;
use App\Entity\Decision\Member;
use App\Entity\Decision\SubDecision;
use App\Entity\Decision\Traits\MemberAwareTrait;
use App\Repository\Decision\SubDecision\Key\GrantingRepository;
use DateTime;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\OneToOne;

use function assert;

#[Entity(repositoryClass: GrantingRepository::class)]
#[Queryable]
class Granting extends SubDecision
{
    use MemberAwareTrait;

    /**
     * Till when the keycode is granted.
     */
    #[Column(type: Types::DATE_MUTABLE)]
    public DateTime $until;

    /**
     * Discharges.
     */
    #[OneToOne(
        targetEntity: Withdrawal::class,
        mappedBy: 'granting',
    )]
    public private(set) ?Withdrawal $withdrawal = null;

    /**
     * Keyholder reference.
     */
    #[OneToOne(
        targetEntity: Keyholder::class,
        mappedBy: 'grantingDec',
    )]
    final public Keyholder $keyholder;

    /**
     * Get the member.
     */
    public function getMember(): Member
    {
        // The trait keeps the association nullable for mapping reasons; this sub-decision always names a member.
        assert(null !== $this->member);

        return $this->member;
    }

    /**
     * Clears the withdrawal, if it exists.
     */
    public function clearWithdrawal(): void
    {
        $this->withdrawal = null;
    }

    /**
     * Forget what was derived from this subdecision, because it no longer exists.
     *
     * Leaves the property uninitialised again, which is how the rest of the code recognises that there is nothing.
     */
    public function clearKeyholder(): void
    {
        unset($this->keyholder);
    }
}
