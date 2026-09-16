<?php

declare(strict_types=1);

namespace App\Entity\Decision;

use App\Doctrine\Query\Queryable;
use App\Entity\Database\Enums\BoardFunctions;
use App\Entity\Database\Enums\InstallationFunctions;
use App\Entity\Database\Enums\MembershipTypes;
use App\Entity\Database\Enums\Studies;
use App\Entity\Decision\SubDecision\Installation;
use App\Entity\Photo\MemberTag as MemberTagModel;
use App\Entity\User\User as UserModel;
use App\Repository\Decision\MemberRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OneToOne;

use function array_reduce;

/**
 * Member model.
 *
 * @phpstan-type MemberGdprArrayType = array{
 *     lidnr: int,
 *     email: ?string,
 *     fullName: string,
 *     lastName: string,
 *     middleName: string,
 *     initials: string,
 *     firstName: string,
 *     birth: string,
 *     generation: int,
 *     type: string,
 *     study: string,
 *     changedOn: string,
 *     membershipEndsOn: ?string,
 *     expiration: string,
 *     supremum: ?string,
 *     hidden: bool,
 *     deleted: bool,
 * }
 */
#[Entity(repositoryClass: MemberRepository::class)]
#[Cache(
    usage: 'NONSTRICT_READ_WRITE',
    region: 'member_region',
)]
#[Queryable]
class Member
{
    /**
     * The user.
     */
    #[Id]
    #[Column(type: Types::INTEGER)]
    #[OneToOne(targetEntity: UserModel::class)]
    #[JoinColumn(
        name: 'lidnr',
        referencedColumnName: 'lidnr',
    )]
    public int $lidnr;

    /**
     * Member's email address.
     */
    #[Column(
        type: Types::STRING,
        nullable: true,
    )]
    public ?string $email = null;

    /**
     * Member's last name.
     */
    #[Column(type: Types::STRING)]
    public string $lastName;

    /**
     * Middle name.
     */
    #[Column(type: Types::STRING)]
    public string $middleName;

    /**
     * Initials.
     */
    #[Column(type: Types::STRING)]
    public string $initials;

    /**
     * First name.
     */
    #[Column(type: Types::STRING)]
    public string $firstName;

    /**
     * Generation.
     *
     * This is the year that this member became a GEWIS member. This is not
     * a academic year, but rather a calendar year.
     */
    #[Column(type: Types::INTEGER)]
    public int $generation;

    /**
     * Member type.
     *
     * This can be one of the following, as defined by the GEWIS statuten:
     *
     * - ordinary
     * - external
     * - graduate
     * - honorary
     *
     * You can find the GEWIS statuten here: https://gewis.nl/association/regulations/articles-of-association.
     *
     * See artikel 7.
     */
    #[Column(
        type: Types::STRING,
        enumType: MembershipTypes::class,
    )]
    public MembershipTypes $type;

    /**
     * The program the member is enrolled in.
     *
     * Members who joined before the study was recorded, who are not studying, or who follow a program outside M&CS
     * fall into the special cases of {@see Studies}.
     */
    #[Column(
        type: Types::STRING,
        enumType: Studies::class,
    )]
    public Studies $study = Studies::Unknown;

    /**
     * Last changed date of membership.
     */
    #[Column(type: Types::DATE_IMMUTABLE)]
    public DateTimeImmutable $changedOn;

    /**
     * Date when the real membership ("ordinary" or "external") of the member will have ended, in other words, from this
     * date onwards they are "graduate". If `null`, the expiration is rolling and will be silently renewed if the member
     * still meets the requirements as set forth in the bylaws and internal regulations.
     */
    #[Column(
        type: Types::DATE_IMMUTABLE,
        nullable: true,
    )]
    public ?DateTimeImmutable $membershipEndsOn = null;

    /**
     * Member birth date.
     */
    #[Column(type: Types::DATE_IMMUTABLE)]
    public DateTimeImmutable $birth;

    /**
     * The date on which the membership of the member is set to expire and will therefore have to be renewed, which
     * happens either automatically or has to be done manually, as set forth in the bylaws and internal regulations.
     */
    #[Column(type: Types::DATE_IMMUTABLE)]
    public DateTimeImmutable $expiration;

    /**
     * If the member receives a 'supremum'.
     */
    #[Column(
        type: Types::STRING,
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
        type: Types::BOOLEAN,
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
        cascade: ['persist'],
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
     * Organ memberships.
     *
     * @var Collection<array-key, OrganMember>
     */
    #[OneToMany(
        targetEntity: OrganMember::class,
        mappedBy: 'member',
    )]
    private Collection $organInstallations;

    /**
     * Board memberships.
     *
     * @var Collection<array-key, BoardMember>
     */
    #[OneToMany(
        targetEntity: BoardMember::class,
        mappedBy: 'member',
    )]
    private Collection $boardInstallations;

    /**
     * Keyholdership.
     *
     * @var Collection<array-key, Keyholder>
     */
    #[OneToMany(
        targetEntity: Keyholder::class,
        mappedBy: 'member',
    )]
    private Collection $keyGrantings;

    /**
     * Determines if a member is deleted. A deleted member is a member whose basic info needs to be retained to ensure
     * that all decisions that mention this member can be kept (i.e., administrative purposes). This value is only set
     * when deleting a member and cannot be altered via the interface.
     *
     * Additionally, this flag can be used to filter deleted members out of the website and of the services that
     * read the API.
     */
    #[Column(
        type: Types::BOOLEAN,
        options: ['default' => false],
    )]
    public bool $deleted = false;

    /**
     * Member tags (photos this member appears in).
     *
     * @var Collection<array-key, MemberTagModel>
     */
    #[OneToMany(
        targetEntity: MemberTagModel::class,
        mappedBy: 'member',
        fetch: 'EXTRA_LAZY',
    )]
    private Collection $tags;

    public function __construct()
    {
        $this->addresses = new ArrayCollection();
        $this->installations = new ArrayCollection();
        $this->organInstallations = new ArrayCollection();
        $this->boardInstallations = new ArrayCollection();
        $this->keyGrantings = new ArrayCollection();
        $this->mailingListMemberships = new ArrayCollection();
        $this->tags = new ArrayCollection();
    }

    /**
     * Assemble the member's full name.
     */
    public function getFullName(): string
    {
        $name = $this->firstName . ' ';

        $middle = $this->middleName;
        if ('' !== $middle) {
            $name .= $middle . ' ';
        }

        return $name . $this->lastName;
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
     * Get the organ installations.
     *
     * @return Collection<array-key, OrganMember>
     */
    public function getOrganInstallations(): Collection
    {
        return $this->organInstallations;
    }

    /**
     * Member is at least 16 years old on the given date.
     */
    public function hasReached16(DateTimeImmutable $onDate = new DateTimeImmutable()): bool
    {
        return $this->isOlderThan(
            $onDate,
            16,
        );
    }

    /**
     * Member is at least 18 years old on the given date.
     */
    public function hasReached18(DateTimeImmutable $onDate = new DateTimeImmutable()): bool
    {
        return $this->isOlderThan(
            $onDate,
            18,
        );
    }

    /**
     * Member is at least 21 years old on the given date.
     */
    public function hasReached21(DateTimeImmutable $onDate = new DateTimeImmutable()): bool
    {
        return $this->isOlderThan(
            $onDate,
            21,
        );
    }

    private function isOlderThan(
        DateTimeImmutable $onDate,
        int $years,
    ): bool {
        return $onDate->diff($this->birth)->y >= $years;
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
     *     birthdate: string,
     *     is_16_plus: bool,
     *     is_18_plus: bool,
     *     is_21_plus: bool,
     *     membershipEndsOn: ?string,
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
            'generation' => $this->generation,
            'hidden' => $this->hidden,
            'deleted' => $this->deleted,
            'birthdate' => $this->birth->format(DateTimeInterface::ATOM),
            'is_16_plus' => $this->hasReached16(),
            'is_18_plus' => $this->hasReached18(),
            'is_21_plus' => $this->hasReached21(),
            'membershipEndsOn' => $this->membershipEndsOn?->format(DateTimeInterface::ATOM) ?? null,
            'expiration' => $this->expiration->format(DateTimeInterface::ATOM),
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
     * Clear all addresses.
     */
    public function clearAddresses(): void
    {
        $this->addresses = new ArrayCollection();
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
        $address->member = $this;
        $this->addresses[] = $address;
    }

    /**
     * Is currently a keyholder.
     */
    public function isKeyholder(): bool
    {
        return array_reduce(
            $this->keyGrantings->toArray(),
            static function ($c, $kg) {
                return $c || $kg->isCurrent();
            },
            false,
        );
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

        $list->member = $this;
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
     * Get the organ installations of organs that the member is currently part of.
     *
     * @return Collection<array-key, OrganMember>
     */
    public function getCurrentOrganInstallations(
        bool $includeInactive = true,
    ): Collection {
        if ($this->getOrganInstallations()->isEmpty()) {
            return new ArrayCollection();
        }

        // Filter out past installations
        $today = new DateTimeImmutable();

        return $this->getOrganInstallations()->filter(
            static function (OrganMember $organMember) use ($today, $includeInactive) {
                $dischargeDate = $organMember->dischargeDate;

                // Keep installation iff installation is in the past, not discharged or discharged in the future.
                $isCurrentlyInstalled = $organMember->installDate <= $today
                    && (
                        null === $dischargeDate
                        || $dischargeDate > $today
                    );

                if (!$isCurrentlyInstalled) {
                    return false;
                }

                // Keep installation iff when inactive should be included or when not inactive.
                return $includeInactive
                    || InstallationFunctions::InactiveMember !== $organMember->function;
            },
        );
    }

    /**
     * Returns whether the member is currently part of any organs.
     */
    public function isActive(): bool
    {
        return !$this->getCurrentOrganInstallations(false)->isEmpty();
    }

    /**
     * Get the board installations.
     *
     * @return Collection<array-key, BoardMember>
     */
    public function getBoardInstallations(): Collection
    {
        return $this->boardInstallations;
    }

    /**
     * Get the tags (photos this member appears in).
     *
     * @return Collection<array-key, MemberTagModel>
     */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    /**
     * Get the current board the member is part of.
     */
    public function getCurrentBoardInstallation(): ?BoardMember
    {
        // Filter out past board installations
        $today = new DateTimeImmutable();

        $boards = $this->getBoardInstallations()->filter(
            static function (BoardMember $boardMember) use ($today) {
                $dischargeDate = $boardMember->dischargeDate;

                // Keep installation if not discharged or discharged in the future
                return null === $dischargeDate || $dischargeDate > $today;
            },
        );

        if ($boards->isEmpty()) {
            return null;
        }

        // Assume a member has a single board installation at a time
        return $boards[0];
    }

    /**
     * @return MemberGdprArrayType
     */
    public function toGdprArray(): array
    {
        return [
            'lidnr' => $this->lidnr,
            'email' => $this->email,
            'fullName' => $this->getFullName(),
            'lastName' => $this->lastName,
            'middleName' => $this->middleName,
            'initials' => $this->initials,
            'firstName' => $this->firstName,
            'birth' => $this->birth->format(DateTimeInterface::ATOM),
            'generation' => $this->generation,
            'type' => $this->type->value,
            'study' => $this->study->value,
            'changedOn' => $this->changedOn->format(DateTimeInterface::ATOM),
            'membershipEndsOn' => $this->membershipEndsOn?->format(DateTimeInterface::ATOM),
            'expiration' => $this->expiration->format(DateTimeInterface::ATOM),
            'supremum' => $this->supremum,
            'hidden' => $this->hidden,
            'deleted' => $this->deleted,
        ];
    }

    /**
     * Get keyholderships.
     *
     * @return Collection<array-key, Keyholder>
     */
    public function getKeyGrantings(): Collection
    {
        return $this->keyGrantings;
    }

    /**
     * Returns true the member is currently installed as a board member and false otherwise.
     */
    public function isBoardMember(): bool
    {
        foreach ($this->getBoardInstallations() as $boardInstall) {
            if ($this->isCurrentBoard($boardInstall)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this member is the secretary the board has right now.
     *
     * Serving is a matter of dates rather than of a row existing: they must have been installed, that installation
     * must have taken effect, and they must not have been relieved yet. This is what the register's administrator
     * rights depend on, so a member who was secretary last year no longer has them.
     */
    public function isServingSecretary(): bool
    {
        foreach ($this->secretaryInstallations() as $boardMember) {
            if ($this->isCurrentBoard($boardMember)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this member is a secretary who has been relieved but not yet discharged.
     *
     * Between those two the board's report on their year is still being settled, and the questions it raises are
     * asked of the register, so they keep being able to read it without being able to change it.
     */
    public function isReleasedButUndischargedSecretary(): bool
    {
        $now = new DateTimeImmutable();

        foreach ($this->secretaryInstallations() as $boardMember) {
            $released = $boardMember->releaseDate;
            $discharged = $boardMember->dischargeDate;

            if (
                null === $released
                || $released > $now
            ) {
                continue;
            }

            if (
                null !== $discharged
                && $discharged <= $now
            ) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Every time this member was installed as the board's secretary.
     *
     * @return BoardMember[]
     */
    private function secretaryInstallations(): array
    {
        $installations = [];

        foreach ($this->getBoardInstallations() as $boardMember) {
            if (BoardFunctions::Secretary !== $boardMember->function) {
                continue;
            }

            $installations[] = $boardMember;
        }

        return $installations;
    }

    /**
     * Check if this is a current board member.
     */
    public function isCurrentBoard(BoardMember $boardMember): bool
    {
        $now = new DateTimeImmutable();
        $installDate = $boardMember->installDate;
        $releaseDate = $boardMember->releaseDate;
        $dischargeDate = $boardMember->dischargeDate;

        if ($installDate <= $now) {
            // Installation was (before) today.
            if (
                null === $releaseDate
                || $releaseDate > $now
            ) {
                // Not yet released or the release is the in the future.
                if (
                    null === $dischargeDate
                    || $dischargeDate > $now
                ) {
                    // Not yet discharged or the discharge is in the future.
                    return true;
                }
            }
        }

        return false;
    }

    public function isExpired(): bool
    {
        return $this->expiration < new DateTimeImmutable();
    }

    public function getSelfRole(): string
    {
        return 'ROLE_USER_' . $this->lidnr;
    }
}
