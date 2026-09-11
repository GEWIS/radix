<?php

declare(strict_types=1);

namespace App\Entity\Decision\SubDecision\Board;

use App\Doctrine\Query\Queryable;
use App\Entity\Database\Enums\BoardFunctions;
use App\Entity\Decision\BoardMember;
use App\Entity\Decision\Member;
use App\Entity\Decision\SubDecision;
use App\Entity\Decision\Traits\MemberAwareTrait;
use App\Repository\Decision\SubDecision\Board\InstallationRepository;
use DateTime;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\OneToOne;

use function assert;

/**
 * Installation as board member.
 */
#[Entity(repositoryClass: InstallationRepository::class)]
#[Queryable]
class Installation extends SubDecision
{
    use MemberAwareTrait;

    /**
     * Function given.
     */
    #[Column(
        type: Types::STRING,
        enumType: BoardFunctions::class,
    )]
    public BoardFunctions $function;

    /**
     * The date at which the installation is in effect.
     */
    #[Column(type: Types::DATE_MUTABLE)]
    public DateTime $date;

    /**
     * Discharge.
     */
    #[OneToOne(
        targetEntity: Discharge::class,
        mappedBy: 'installation',
    )]
    public private(set) ?Discharge $discharge = null;

    /**
     * Release.
     */
    #[OneToOne(
        targetEntity: Release::class,
        mappedBy: 'installation',
    )]
    public private(set) ?Release $release = null;

    /**
     * Board member reference.
     */
    #[OneToOne(
        targetEntity: BoardMember::class,
        mappedBy: 'installationDec',
    )]
    final public BoardMember $boardMember;

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
     * Clears the discharge, if it exists.
     */
    public function clearDischarge(): void
    {
        $this->discharge = null;
    }

    /**
     * Clears the release, if it exists.
     */
    public function clearRelease(): void
    {
        $this->release = null;
    }

    /**
     * Forget what was derived from this subdecision, because it no longer exists.
     *
     * Leaves the property uninitialised again, which is how the rest of the code recognises that there is nothing.
     */
    public function clearBoardMember(): void
    {
        unset($this->boardMember);
    }
}
