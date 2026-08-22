<?php

declare(strict_types=1);

namespace App\Entity\Decision\SubDecision;

use App\Doctrine\Query\Queryable;
use App\Entity\Database\Enums\InstallationFunctions;
use App\Entity\Decision\Member;
use App\Entity\Decision\OrganMember;
use App\Repository\Decision\SubDecision\InstallationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OneToOne;

use function assert;

/**
 * Installation into organ.
 */
#[Entity(repositoryClass: InstallationRepository::class)]
#[Queryable]
class Installation extends FoundationReference
{
    /**
     * The member this decision installs.
     *
     * Declared here rather than taken from {@see \App\Entity\Decision\Traits\MemberAwareTrait}: an installation is
     * the one sub-decision that names an inverse side, and naming it through an association override only works
     * because the association happens to be declared in a trait rather than on the parent class. The mapping should
     * not rest on that. The column is shared with every other sub-decision in the single table, so it stays nullable
     * here even though an installation always has a member; {@see self::getMember()} is where that guarantee lives.
     *
     * Deliberately without an `onDelete`: a sub-decision names the member the association decided something about,
     * and neither dropping the sub-decision nor blanking the name is an outcome a member's removal may reach on its
     * own. The constraint refusing the removal is the point.
     */
    #[ManyToOne(
        targetEntity: Member::class,
        inversedBy: 'installations',
    )]
    #[JoinColumn(
        name: 'lidnr',
        referencedColumnName: 'lidnr',
        nullable: true,
    )]
    private ?Member $member = null;

    /**
     * Function given.
     */
    #[Column(
        type: Types::STRING,
        enumType: InstallationFunctions::class,
    )]
    private InstallationFunctions $function;

    /**
     * Reappointment subdecisions if this installation was prolonged (can be done multiple times).
     *
     * @var Collection<array-key, Reappointment>
     */
    #[OneToMany(
        targetEntity: Reappointment::class,
        mappedBy: 'installation',
    )]
    private Collection $reappointments;

    /**
     * Discharges.
     */
    #[OneToOne(
        targetEntity: Discharge::class,
        mappedBy: 'installation',
    )]
    private ?Discharge $discharge = null;

    /**
     * The organmember reference.
     */
    #[OneToOne(
        targetEntity: OrganMember::class,
        mappedBy: 'installation',
    )]
    private OrganMember $organMember;

    public function __construct()
    {
        $this->reappointments = new ArrayCollection();
    }

    /**
     * Get the function.
     */
    public function getFunction(): InstallationFunctions
    {
        return $this->function;
    }

    /**
     * Set the function.
     */
    public function setFunction(InstallationFunctions $function): void
    {
        $this->function = $function;
    }

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
     * Set the member.
     */
    public function setMember(Member $member): void
    {
        $this->member = $member;
    }

    /**
     * Get the reappointments, if they exist.
     *
     * @return Collection<array-key, Reappointment>
     */
    public function getReappointments(): Collection
    {
        return $this->reappointments;
    }

    /**
     * Removes the reappointments, if they exist.
     */
    public function removeReappointment(Reappointment $reappointment): void
    {
        if (!$this->reappointments->contains($reappointment)) {
            return;
        }

        $this->reappointments->removeElement($reappointment);
    }

    /**
     * Get the discharge, if it exists.
     */
    public function getDischarge(): ?Discharge
    {
        return $this->discharge;
    }

    /**
     * Clears the discharge, if it exists.
     */
    public function clearDischarge(): void
    {
        $this->discharge = null;
    }

    /**
     * Get the organ member reference.
     */
    public function getOrganMember(): OrganMember
    {
        return $this->organMember;
    }

    /**
     * Set the organ member reference.
     *
     * Kept in step with the owning side, so that a member only just derived from this installation can be found right
     * away, without having to go through the database for it.
     */
    public function setOrganMember(OrganMember $organMember): void
    {
        $this->organMember = $organMember;
    }

    /**
     * Forget what was derived from this subdecision, because it no longer exists.
     *
     * Leaves the property uninitialised again, which is how the rest of the code recognises that there is nothing.
     */
    public function clearOrganMember(): void
    {
        unset($this->organMember);
    }
}
