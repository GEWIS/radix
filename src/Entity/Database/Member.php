<?php

declare(strict_types=1);

namespace App\Entity\Database;

use App\Entity\Application\AssociationYear;
use App\Entity\Database\Enums\Studies;
use App\Entity\Database\SubDecision\Installation;
use App\Repository\Database\MemberRepository;
use DateTime;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OrderBy;
use RuntimeException;
use SortDirection;
use Symfony\Component\Mime\Address as MailAddress;

use function is_string;

/**
 * Member model.
 */
#[Entity(repositoryClass: MemberRepository::class)]
class Member
{
    /**
     * The user
     */
    #[Id]
    #[Column(type: 'integer')]
    #[GeneratedValue(strategy: 'AUTO')]
    public int $lidnr;

    /**
     * Member's email address.
     */
    #[Column(
        type: 'string',
        nullable: true,
    )]
    public private(set) ?string $email = null;

    /**
     * Member's last name.
     */
    #[Column(type: 'string')]
    public string $lastName;

    /**
     * Middle name.
     */
    #[Column(type: 'string')]
    public string $middleName;

    /**
     * Initials.
     */
    #[Column(type: 'string')]
    public string $initials;

    /**
     * First name.
     */
    #[Column(type: 'string')]
    public string $firstName;

    /**
     * TU/e student number.
     */
    #[Column(
        type: 'string',
        nullable: true,
    )]
    public ?string $studentNumber = null;

    /**
     * Study of the member.
     */
    #[Column(
        enumType: Studies::class,
    )]
    public Studies $study = Studies::Unknown;

    /**
     * Last changed date of member.
     */
    #[Column(type: 'date')]
    public DateTime $changedOn;

    /**
     * Memberships of this member
     *
     * @var Collection<array-key, Membership>
     */
    #[OneToMany(
        targetEntity: Membership::class,
        mappedBy: 'member',
        cascade: [
            'persist',
            'remove',
        ],
    )]
    #[OrderBy(['startDate' => SortDirection::Ascending])]
    private Collection $memberships;

    /**
     * Last date membership status was checked.
     */
    #[Column(
        type: 'date',
        nullable: true,
    )]
    public ?DateTime $lastCheckedOn = null;

    /**
     * Member birthdate.
     */
    #[Column(type: 'date')]
    public private(set) DateTime $birth;

    /**
     * If the member receives a 'supremum'
     */
    #[Column(
        type: 'string',
        nullable: true,
    )]
    public ?string $supremum = null;

    /**
     * Stores whether a member should be 'hidden'.
     *
     * Hidden is honoured by the website to lock logins and hide the birthday on the landing page. It can be used
     * for deleted members and members that are deceased but whose profile should be kept.
     */
    #[Column(
        type: 'boolean',
        options: ['default' => false],
    )]
    public bool $hidden = false;

    /**
     * Addresses of this member.
     *
     * @var Collection<array-key, Address>
     */
    #[OneToMany(
        targetEntity: Address::class,
        mappedBy: 'member',
        cascade: [
            'persist',
            'remove',
        ],
    )]
    private Collection $addresses;

    /**
     * Installations of this member.
     *
     * @var Collection<array-key, Installation>
     */
    #[OneToMany(
        targetEntity: Installation::class,
        mappedBy: 'member',
    )]
    private Collection $installations;

    /**
     * Memberships of mailing lists.
     *
     * @var Collection<array-key, MailingListMember>
     */
    #[OneToMany(
        targetEntity: MailingListMember::class,
        mappedBy: 'member',
        cascade: ['persist'],
    )]
    private Collection $mailingListMemberships;

    /**
     * RenewalLinks of this member.
     *
     * @var Collection<array-key, RenewalLink>
     */
    #[OneToMany(
        targetEntity: RenewalLink::class,
        mappedBy: 'member',
        cascade: [
            'persist',
            'remove',
        ],
    )]
    private Collection $renewalLinks;

    /**
     * Audit entries (e.g. notes) of this member.
     *
     * @var Collection<array-key, AuditEntry>
     */
    #[OneToMany(
        targetEntity: AuditEntry::class,
        mappedBy: 'member',
        cascade: [
            'persist',
            'remove',
        ],
    )]
    private Collection $auditEntries;

    /**
     * Determines if a member is deleted. A deleted member is a member whose basic info needs to be retained to ensure
     * that all decisions that mention this member can be kept (i.e., administrative purposes). This value is only set
     * when deleting a member and cannot be altered via the interface.
     *
     * Additionally, this flag can be used to filter deleted members out of the website and of the services that
     * read the API.
     */
    #[Column(
        type: 'boolean',
        options: ['default' => false],
    )]
    public bool $deleted = false;

    public function __construct()
    {
        // Every mapped collection, not only the ones with an adder: Doctrine fills these on hydration, so anything
        // left out here is initialised for a member that was loaded and uninitialised for one that was just created.
        $this->addresses = new ArrayCollection();
        $this->auditEntries = new ArrayCollection();
        $this->installations = new ArrayCollection();
        $this->mailingListMemberships = new ArrayCollection();
        $this->memberships = new ArrayCollection();
        $this->renewalLinks = new ArrayCollection();
    }

    /**
     * Get the member as an email recipient
     */
    public function getEmailRecipient(): ?MailAddress
    {
        if (null === $this->email) {
            return null;
        }

        return new MailAddress(
            $this->email,
            $this->getFullName(),
        );
    }

    /**
     * Set the member's email address.
     */
    public function setEmail(?string $newEmail): void
    {
        // If the new email address matches the current, we don't have to do anything
        if ($this->email === $newEmail) {
            return;
        }

        $oldEmail = $this->email;
        $this->email = $newEmail;

        if (null === $oldEmail) {
            return;
        }

        $mailAddressExists = $this->mailingListMemberships->exists(
            static function ($key, MailingListMember $list) use ($newEmail) {
                return $newEmail === $list->email;
            },
        );
        if ($mailAddressExists) {
            throw new RuntimeException(
                // phpcs:ignore -- user-visible strings should not be split
                'The e-mail address cannot be updated while there are already (pending) registrations for this member using this email address. Please try again once all list updates have been processed.',
            );
        }

        // For each mailing list memberships, schedule deletion of the old email and
        // registration using the new email address
        // Will be persisted with the member
        foreach ($this->mailingListMemberships as $mailingListMembership) {
            if ($mailingListMembership->toBeDeleted) {
                continue;
            }

            $mailingListMembership->toBeDeleted = true;
            $newMembership = new MailingListMember();
            // Takes the address with it: the member's own is what was just set.
            $newMembership->setMember($this);
            $newMembership->mailingList = $mailingListMembership->mailingList;
            $this->addList($newMembership);
        }
    }

    /**
     * Assemble the member's full name.
     */
    public function getFullName(): string
    {
        $name = $this->firstName . ' ';

        $middle = $this->middleName;
        if (!empty($middle)) {
            $name .= $middle . ' ';
        }

        return $name . $this->lastName;
    }

    /**
     * Get the generation of the member.
     */
    public function getGeneration(): int
    {
        $oldestMembership = $this->memberships->first();

        if (false === $oldestMembership) {
            return 0;
        }

        // A generation is the association year someone joined in, named after the calendar year it started in.
        return AssociationYear::fromDate($oldestMembership->getStartDate())->getYear();
    }

    /**
     * Get the expiration date.
     */
    public function getExpiration(): DateTime
    {
        return $this->computeMembershipEndDate(formalMemberOnly: false) ?? new DateTime('0001-01-01 00:00:00');
    }

    /**
     * Set the birthdate.
     */
    public function setBirth(DateTime|string $birth): void
    {
        if (is_string($birth)) {
            $birth = new DateTime($birth);
        }

        $this->birth = $birth;
    }

    /**
     * Get the date on which the membership of the member will have ended (i.e., they have become "graduate").
     */
    public function getMembershipEndsOn(): DateTime
    {
        return $this->getMembershipEndDate() ?? new DateTime('0001-01-01 00:00:00');
    }

    /**
     * The date on which the membership of the member will have ended, or null if they never were a formal member.
     *
     * {@see self::getMembershipEndsOn()} answers the same question with a sentinel date, which reads as a real answer
     * where it is shown.
     */
    public function getMembershipEndDate(): ?DateTime
    {
        return $this->computeMembershipEndDate(formalMemberOnly: true);
    }

    /**
     * Compute the date of end of membership, or null if none
     */
    private function computeMembershipEndDate(bool $formalMemberOnly): ?DateTime
    {
        $expiration = null;

        foreach ($this->getMemberships() as $membership) {
            if (
                !$membership->type->isFormalMember()
                && $formalMemberOnly
            ) {
                continue;
            }

            if (
                null !== $expiration
                && $membership->endDate <= $expiration
            ) {
                continue;
            }

            $expiration = $membership->endDate;
        }

        return $expiration;
    }

    /**
     * Get the memberships of this member.
     *
     * @return Collection<array-key, Membership>
     */
    public function getMemberships(): Collection
    {
        return $this->memberships;
    }

    /**
     * Add a membership to this member.
     */
    public function addMembership(Membership $membership): void
    {
        if ($membership->member !== $this) {
            throw new RuntimeException('Membership does not belong to this member.');
        }

        $this->memberships[] = $membership;
    }

    /**
     * Delete all memberships of this member.
     * This is only used in the case of clearing/deleting a member.
     * In all other cases, the membership should be updated.
     */
    public function unsetMemberships(): void
    {
        foreach ($this->getMemberships() as $membership) {
            $this->memberships->removeElement($membership);
        }
    }

    /**
     * Get the current membership of this member, or null if none.
     */
    public function getCurrentMembership(): ?Membership
    {
        foreach ($this->getMemberships() as $membership) {
            if ($membership->isCurrent()) {
                return $membership;
            }
        }

        return null;
    }

    /**
     * Get the current membership of this member, or the last if expired.
     */
    public function getCurrentOrLastMembership(): ?Membership
    {
        if (null !== $this->getCurrentMembership()) {
            return $this->getCurrentMembership();
        }

        return $this->getLastMembership();
    }

    /**
     * Get the last (potentially expired, potentially future) membership
     */
    public function getLastMembership(): ?Membership
    {
        $lastMembership = $this->memberships->last();

        return false === $lastMembership
            ? null
            : $lastMembership;
    }

    /**
     * Get the installations.
     *
     * @return Collection<array-key, Installation>
     */
    public function getInstallations(): Collection
    {
        return $this->installations;
    }

    /**
     * Get audit entries related to this member.
     *
     * @return Collection<array-key, AuditEntry>
     */
    public function getAuditEntries(): Collection
    {
        return $this->auditEntries;
    }

    /**
     * Convert most relevant items to array.
     *
     * @return array{
     *     lidnr: int,
     *     email: ?string,
     *     fullName: string,
     *     lastName: string,
     *     middleName: string,
     *     initials: string,
     *     firstName: string,
     *     generation: int,
     *     hidden: bool,
     *     deleted: bool,
     *     expiration: string,
     * }
     */
    public function toArray(): array
    {
        return [
            'lidnr' => $this->lidnr,
            'email' => $this->email,
            'fullName' => $this->getFullName(),
            'lastName' => $this->lastName,
            'middleName' => $this->middleName,
            'initials' => $this->initials,
            'firstName' => $this->firstName,
            'generation' => $this->getGeneration(),
            'hidden' => $this->hidden,
            'deleted' => $this->deleted,
            'expiration' => $this->getExpiration()->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * Get all addresses.
     *
     * @return Collection<array-key, Address>
     */
    public function getAddresses(): Collection
    {
        return $this->addresses;
    }

    /**
     * Add multiple addresses.
     *
     * @param Address[] $addresses
     */
    public function addAddresses(array $addresses): void
    {
        foreach ($addresses as $address) {
            $this->addAddress($address);
        }
    }

    /**
     * Add an address.
     */
    public function addAddress(Address $address): void
    {
        $address->setMember($this);
        $this->addresses[] = $address;
    }

    /**
     * Get mailing list subscriptions.
     *
     * @return Collection<array-key, MailingListMember>
     */
    public function getMailingListMemberships(): Collection
    {
        return $this->mailingListMemberships;
    }

    /**
     * Add a mailing list subscription.
     */
    public function addList(MailingListMember $list): void
    {
        if ($this->mailingListMemberships->contains($list)) {
            return;
        }

        $list->setMember($this);
        $this->mailingListMemberships->add($list);
    }

    /**
     * Add multiple mailing lists.
     *
     * @param MailingListMember[] $lists
     */
    public function addLists(array $lists): void
    {
        foreach ($lists as $list) {
            $this->addList($list);
        }
    }

    /**
     * Set the home address.
     */
    public function setHomeAddress(Address $address): void
    {
        $this->addAddress($address);
    }

    /**
     * Set the student address.
     */
    public function setStudentAddress(Address $address): void
    {
        $this->addAddress($address);
    }

    /**
     * Get renewal links of a member
     *
     * @return Collection<array-key, RenewalLink>
     */
    public function getRenewalLinks(): Collection
    {
        return $this->renewalLinks;
    }

    public function hasActiveRenewalLink(): bool
    {
        return $this->getRenewalLinks()->exists(
            static function ($key, RenewalLink $renewalLink) {
                return !$renewalLink->linkExpired();
            },
        );
    }
}
