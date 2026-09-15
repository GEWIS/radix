<?php

declare(strict_types=1);

namespace App\Entity\User;

use Ambta\DoctrineEncryptBundle\Configuration\Encrypted;
use App\Entity\Database\Enums\MembershipTypes;
use App\Entity\Decision\Member as MemberModel;
use App\Entity\User\Enums\PhotoVisibility;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\Enums\UserTypes;
use App\Entity\User\Traits\BackupCodeAwareTrait;
use App\Repository\User\UserRepository;
use App\Security\User\MfaEnforcementSwitch;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OneToOne;
use Override;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function in_array;

/**
 * User model.
 *
 * @phpstan-import-type UserRoleGdprArrayType from UserRole as ImportedUserRoleGdprArrayType
 * @phpstan-import-type UserSettingsGdprArrayType from UserSettings as ImportedUserSettingsGdprArrayType
 * @phpstan-type UserGdprArrayType = array{
 *     roles: ImportedUserRoleGdprArrayType[],
 *     settings: ImportedUserSettingsGdprArrayType|null,
 *     passwordChangedOn: ?string,
 * }
 */
#[Entity(repositoryClass: UserRepository::class)]
class User implements UserInterface, PasswordAuthenticatedUserInterface, TwoFactorInterface, BackupCodeAwareInterface
{
    use BackupCodeAwareTrait;

    /**
     * The membership number.
     */
    #[Id]
    #[Column(type: Types::INTEGER)]
    public int $lidnr;

    /**
     * The user's password.
     */
    #[Column(type: Types::STRING)]
    private string $password;

    /**
     * The corresponding member for this user. An account exists only for as long as the member does: it is fetched
     * eagerly and typed non-nullable, so a row that outlived its member would break every page the account can reach.
     * The cascade removes the account, and with it everything that hangs off the account, in one statement.
     */
    #[OneToOne(
        targetEntity: MemberModel::class,
        fetch: 'EAGER',
    )]
    #[JoinColumn(
        name: 'lidnr',
        referencedColumnName: 'lidnr',
        nullable: false,
        onDelete: 'CASCADE',
    )]
    public MemberModel $member;

    /**
     * User roles.
     *
     * @var Collection<array-key, UserRole>
     */
    #[OneToMany(
        targetEntity: UserRole::class,
        mappedBy: 'lidnr',
        fetch: 'EAGER',
    )]
    private Collection $roles;

    /**
     * Timestamp when the password was last changed.
     */
    #[Column(
        type: Types::DATETIME_IMMUTABLE,
        nullable: true,
    )]
    private ?DateTimeImmutable $passwordChangedOn = null;

    /**
     * Timestamp after which remember-me logins must be refreshed.
     */
    #[Column(
        type: Types::DATETIME_IMMUTABLE,
        nullable: true,
    )]
    public ?DateTimeImmutable $forceReloginAt = null;

    /**
     * Base32-encoded TOTP shared secret. Null when TOTP MFA is disabled. Encrypted at rest via DoctrineEncryptBundle.
     */
    #[Column(
        type: Types::TEXT,
        nullable: true,
    )]
    #[Encrypted]
    public ?string $totpSecret = null;

    /**
     * This member's settings and privacy preferences. NOTE: as the inverse side of a one-to-one, Doctrine always loads
     * this eagerly (a proxy cannot represent "no row yet"), so the `User`-hydrating queries fetch-join it to avoid an
     * N+1. Null means "all defaults"; the null-safe `has*()` accessors below encode that.
     */
    #[OneToOne(
        targetEntity: UserSettings::class,
        mappedBy: 'user',
        cascade: [
            'persist',
            'remove',
        ],
    )]
    public ?UserSettings $settings = null;

    public function __construct()
    {
        $this->roles = new ArrayCollection();
    }

    /**
     * Return the `lidnr` of this user, generalised to `id`.
     */
    public function getId(): int
    {
        return $this->lidnr;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    #[Override]
    public function getUserIdentifier(): string
    {
        return (string) $this->lidnr;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    #[Override]
    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /**
     * The roles that are withheld until multi-factor authentication is enrolled.
     *
     * Everything here opens something that is worth protecting: the website's administration, the board's own pages,
     * and the register in either of its two shapes.
     */
    private const array MFA_PROTECTED_ROLES = [
        UserRoles::Admin->value,
        UserRoles::Board->value,
        UserRoles::DatabaseAdmin->value,
        UserRoles::DatabaseReadOnly->value,
    ];

    /**
     * @see UserInterface
     *
     * @phpstan-return array<array-key, value-of<UserRoles>|string>
     */
    #[Override]
    public function getRoles(): array
    {
        $member = $this->member;
        if (MembershipTypes::Graduate === $member->type) {
            $baseRole = UserRoles::Graduate->value;
        } elseif ($member->isActive()) {
            $baseRole = UserRoles::ActiveMember->value;
        } else {
            $baseRole = UserRoles::Member->value;
        }

        $explicitRoles = array_map(
            static fn (UserRole $role) => $role->role->value,
            $this->roles->filter(static fn (UserRole $role): bool => $role->isActive())->getValues(),
        );

        $roles = [
            $baseRole,
            $member->getSelfRole(),
            ...$explicitRoles,
        ];

        // ROLE_BOARD is granted dynamically based on current board installations, mirroring how the membership-based
        // roles above are derived from `Member` state rather than `UserRole` rows.
        if ($member->isBoardMember()) {
            $roles[] = UserRoles::Board->value;
        }

        // The register's own rights, granted for as long as somebody is the secretary rather than written down
        // against their account. A serving secretary administers it; one who has been relieved but whose year is not
        // yet discharged keeps reading it, so the questions their report raises can still be answered.
        if ($member->isServingSecretary()) {
            $roles[] = UserRoles::DatabaseAdmin->value;
        } elseif ($member->isReleasedButUndischargedSecretary()) {
            $roles[] = UserRoles::DatabaseReadOnly->value;
        }

        // When MFA enforcement is on and this user is in scope (admin, current board member, or holding either of
        // the register's roles) but has not enrolled, strip every role that opens something worth protecting, so that
        // each existing `IsGranted` / `access_control` check fails. The exception is then converted into a redirect
        // to enrolment by `MfaEnforcementListener`.
        if (
            MfaEnforcementSwitch::isEnabled()
            && null === $this->totpSecret
            && $this->isMfaRequiredScope(
                $member,
                $explicitRoles,
            )
        ) {
            $roles = array_values(array_filter(
                $roles,
                static fn (string $role): bool => !in_array(
                    $role,
                    self::MFA_PROTECTED_ROLES,
                    true,
                ),
            ));
        }

        return array_unique($roles);
    }

    /**
     * @return Collection<array-key, UserRole>
     */
    public function getRoleEntities(): Collection
    {
        return $this->roles;
    }

    /**
     * @param string[] $explicitRoles
     */
    private function isMfaRequiredScope(
        MemberModel $member,
        array $explicitRoles,
    ): bool {
        if ($member->isBoardMember()) {
            return true;
        }

        // The register is read and written through these two, so neither is handed out to somebody who has not
        // enrolled -- the same bar the website's own administrator is held to.
        if (
            $member->isServingSecretary()
            || $member->isReleasedButUndischargedSecretary()
        ) {
            return true;
        }

        // Granted outright rather than derived from an office, these still open the same things and are held to the
        // same bar.
        foreach (self::MFA_PROTECTED_ROLES as $role) {
            if (
                in_array(
                    $role,
                    $explicitRoles,
                    true,
                )
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * A human-readable name for this account, for display alongside a {@see CompanyUser}.
     */
    public function getDisplayName(): string
    {
        return $this->member->getFullName();
    }

    public function getPasswordChangedOn(): ?DateTimeImmutable
    {
        return $this->passwordChangedOn;
    }

    public function setPasswordChangedOn(DateTimeImmutable $passwordChangedOn): void
    {
        $this->passwordChangedOn = $passwordChangedOn;
    }

    public function getUserType(): UserTypes
    {
        return UserTypes::User;
    }

    #[Override]
    public function isTotpAuthenticationEnabled(): bool
    {
        return null !== $this->totpSecret;
    }

    #[Override]
    public function getTotpAuthenticationUsername(): string
    {
        return $this->getUserIdentifier();
    }

    #[Override]
    public function getTotpAuthenticationConfiguration(): ?TotpConfigurationInterface
    {
        if (null === $this->totpSecret) {
            return null;
        }

        return new TotpConfiguration(
            $this->totpSecret,
            TotpConfiguration::ALGORITHM_SHA1,
            30,
            6,
        );
    }

    /**
     * Whether this member has turned off the festive cosmetics. Defaults to false when no settings row exists yet.
     */
    public function hasDisabledCosmetics(): bool
    {
        return $this->settings->disableCosmetics ?? false;
    }

    /**
     * Whether this member has hidden their year of birth. Defaults to false when no settings row exists yet.
     */
    public function hasHiddenYearOfBirth(): bool
    {
        return $this->settings->hideYearOfBirth ?? false;
    }

    /**
     * How much of this member's tagged-photo collection is hidden. Defaults to hiding only the photos they select
     * (nothing, until they pick some) when no settings row exists yet.
     */
    public function getPhotoVisibility(): PhotoVisibility
    {
        return $this->settings->photoVisibility ?? PhotoVisibility::HideSelected;
    }

    /**
     * @return UserGdprArrayType
     */
    public function toGdprArray(): array
    {
        return [
            'roles' => array_map(
                static fn (UserRole $role): array => $role->toGdprArray(),
                $this->roles->getValues(),
            ),
            'settings' => $this->settings?->toGdprArray(),
            'passwordChangedOn' => $this->getPasswordChangedOn()?->format(DateTimeInterface::ATOM),
        ];
    }
}
