<?php

declare(strict_types=1);

namespace App\Entity\Activity;

use App\Entity\Activity\Enums\AllocationMethod;
use App\Entity\Activity\Enums\CohortTier;
use App\Entity\Activity\Enums\DrawCutoffRule;
use App\Entity\Activity\Enums\MembershipPriorityMode;
use App\Entity\Activity\Enums\MembershipTier;
use App\Entity\Application\LocalisedText as LocalisedTextModel;
use App\Entity\Application\PriorityTierInterface;
use App\Entity\Application\Traits\IdentifiableTrait;
use App\Entity\Database\Enums\ProgramType;
use App\Entity\Decision\Member as MemberModel;
use App\Repository\Activity\SignupListRepository;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\OrderBy;
use Doctrine\ORM\Mapping\UniqueConstraint;
use SortDirection;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

use function array_filter;
use function array_map;
use function array_values;
use function implode;
use function in_array;
use function is_array;
use function sprintf;
use function strval;
use function usort;

use const PHP_INT_MAX;

/**
 * SignupList model.
 *
 * @phpstan-import-type SignupFieldArrayType from SignupField as ImportedSignupFieldArrayType
 * @phpstan-type SignupListArrayType = array{
 *     id: ?int,
 *     name: ?string,
 *     nameEn: ?string,
 *     openDate: ?DateTime,
 *     closeDate: ?DateTime,
 *     onlyGEWIS: bool,
 *     displaySubscribedNumber: bool,
 *     limitedCapacity: bool,
 *     capacity: ?int,
 *     allocationMethod: string,
 *     drawCutoffRule: ?string,
 *     drawCutoffAt: ?DateTime,
 *     drawAfterDurationHours: ?int,
 *     externalPolicyUrl: ?string,
 *     externalForceOrdering: bool,
 *     externalPaymentByExternal: bool,
 *     customMethodDescription: ?string,
 *     membershipTierOrder: ?list<list<string>>,
 *     membershipPriorityMode: ?string,
 *     membershipPlaces: ?array<string, int>,
 *     cohortTierOrder: ?list<list<string>>,
 *     programTypeOrder: ?list<list<string>>,
 *     organisingCommitteePlaces: ?int,
 *     roles: array<array-key, array{name: string, minimum: int}>,
 *     fields: ImportedSignupFieldArrayType[],
 *     presenceTaken: bool,
 *     promoted: bool,
 * }
 * @phpstan-import-type LocalisedTextGdprArrayType from LocalisedTextModel as ImportedLocalisedTextGdprArrayType
 * @phpstan-import-type SignupFieldGdprArrayType from SignupField as ImportedSignupFieldGdprArrayType
 * @phpstan-type SignupListGdprArrayType = array{
 *     id: ?int,
 *     name: ImportedLocalisedTextGdprArrayType,
 *     openDate: ?string,
 *     closeDate: ?string,
 *     onlyGEWIS: bool,
 *     displaySubscribedNumber: bool,
 *     limitedCapacity: bool,
 *     capacity: ?int,
 *     allocationMethod: string,
 *     drawCutoffRule: ?string,
 *     drawCutoffAt: ?string,
 *     drawAfterDurationHours: ?int,
 *     externalPolicyUrl: ?string,
 *     externalForceOrdering: bool,
 *     externalPaymentByExternal: bool,
 *     customMethodDescription: ?string,
 *     membershipTierOrder: ?list<list<string>>,
 *     membershipPriorityMode: ?string,
 *     membershipPlaces: ?array<string, int>,
 *     cohortTierOrder: ?list<list<string>>,
 *     programTypeOrder: ?list<list<string>>,
 *     organisingCommitteePlaces: ?int,
 *     roles: array<array-key, array{name: string, minimum: int}>,
 *     fields: ImportedSignupFieldGdprArrayType[],
 *     presenceTaken: bool,
 *     promoted: bool
 * }
 */
#[Entity(repositoryClass: SignupListRepository::class)]
#[UniqueConstraint(
    name: 'signup_list_revision_lineage_uniq',
    columns: [
        'activity_revision_id',
        'lineageId',
    ],
)]
class SignupList
{
    use IdentifiableTrait;

    /**
     * The revision this SignupList belongs to. Each revision owns its own (cloned) lists, so list edits are staged
     * with the rest of the revision and only become public when the revision is approved.
     */
    #[ManyToOne(
        targetEntity: ActivityRevision::class,
        cascade: ['persist'],
        inversedBy: 'signupLists',
    )]
    #[JoinColumn(
        name: 'activity_revision_id',
        referencedColumnName: 'id',
        nullable: false,
    )]
    public ActivityRevision $revision;

    /**
     * Stable identity shared by every clone of this logical list across revisions. On approval, sign-ups are migrated
     * from the outgoing live revision's list to the newly-approved revision's clone with the same lineage id.
     */
    #[Column(type: UuidType::NAME)]
    public Uuid $lineageId;

    /**
     * The name of the SignupList.
     */
    #[OneToOne(
        targetEntity: ActivityLocalisedText::class,
        cascade: [
            'persist',
            'remove',
        ],
        fetch: 'EAGER',
        orphanRemoval: true,
    )]
    #[JoinColumn(
        name: 'name_id',
        referencedColumnName: 'id',
        nullable: false,
    )]
    public ActivityLocalisedText $name;

    #[Column(
        type: Types::DATETIME_MUTABLE,
        nullable: true,
    )]
    public ?DateTime $openDate = null;

    #[Column(
        type: Types::DATETIME_MUTABLE,
        nullable: true,
    )]
    public ?DateTime $closeDate = null;

    /**
     * When subscribers were told this was about to close, so they are told once rather than every time the reminder
     * job runs.
     */
    #[Column(
        type: Types::DATETIME_IMMUTABLE,
        nullable: true,
    )]
    public ?DateTimeImmutable $remindedAt = null;

    /**
     * Determines if people outside of GEWIS can sign up.
     */
    #[Column(type: Types::BOOLEAN)]
    public bool $onlyGEWIS = false;

    /**
     * Determines if the number of signed up members should be displayed
     * when the user is NOT logged in.
     */
    #[Column(type: Types::BOOLEAN)]
    public bool $displaySubscribedNumber = false;

    /**
     * If the sign-up list has limited capacity, we should show users a warning that this is the case.
     */
    #[Column(type: Types::BOOLEAN)]
    public bool $limitedCapacity = false;

    /**
     * The maximum number of admitted sign-ups when {@see self::$limitedCapacity} is set; null when unlimited.
     * Subscribees to a limited list must first be drawn (admitted) up to this number before attendance can be taken.
     */
    #[Column(
        type: Types::INTEGER,
        nullable: true,
    )]
    public ?int $capacity = null;

    /**
     * When the admission draw was performed and locked, or null if it has not been drawn yet. A non-null value marks
     * the draw as immutable: the bulk draw can no longer be re-run, only individual admissions adjusted. Mirrors the
     * reviewer/reviewedAt audit on {@see \App\Entity\Application\AbstractRevision}.
     */
    #[Column(
        type: Types::DATETIME_MUTABLE,
        nullable: true,
    )]
    public ?DateTime $drawnAt = null;

    /**
     * The board member who performed (and locked) the draw; null while not drawn, and also for a draw performed
     * automatically at its deadline (so null with a non-null {@see self::$drawnAt} means an automated draw).
     */
    #[ManyToOne(targetEntity: MemberModel::class)]
    #[JoinColumn(
        referencedColumnName: 'lidnr',
        nullable: true,
        onDelete: 'SET NULL',
    )]
    public ?MemberModel $drawnBy = null;

    /**
     * How the limited places are allocated among subscribers (only meaningful when {@see self::$limitedCapacity}).
     */
    #[Column(
        type: Types::STRING,
        enumType: AllocationMethod::class,
    )]
    public AllocationMethod $allocationMethod = AllocationMethod::FirstComeFirstServed;

    /**
     * For an {@see AllocationMethod::ConditionalDraw}: when the draw should be performed.
     */
    #[Column(
        type: Types::STRING,
        nullable: true,
        enumType: DrawCutoffRule::class,
    )]
    public ?DrawCutoffRule $drawCutoffRule = null;

    /**
     * For {@see DrawCutoffRule::IfFullBefore}: the moment the list must be full by.
     */
    #[Column(
        type: Types::DATETIME_MUTABLE,
        nullable: true,
    )]
    public ?DateTime $drawCutoffAt = null;

    /**
     * For {@see DrawCutoffRule::AfterDurationOpen}: how many hours after opening the draw happens.
     */
    #[Column(
        type: Types::INTEGER,
        nullable: true,
    )]
    public ?int $drawAfterDurationHours = null;

    /**
     * For an {@see AllocationMethod::ExternalParty}: a URL describing the external party's allocation policy.
     */
    #[Column(
        type: Types::STRING,
        nullable: true,
    )]
    public ?string $externalPolicyUrl = null;

    /**
     * For an {@see AllocationMethod::ExternalParty}: whether the external party dictates the ordering of admissions.
     */
    #[Column(type: Types::BOOLEAN)]
    public bool $externalForceOrdering = false;

    /**
     * For an {@see AllocationMethod::ExternalParty}: whether payment is collected by the external party.
     */
    #[Column(type: Types::BOOLEAN)]
    public bool $externalPaymentByExternal = false;

    /**
     * For a {@see AllocationMethod::Custom}: a free-form description of how places are allocated.
     */
    #[Column(
        type: Types::TEXT,
        nullable: true,
    )]
    public ?string $customMethodDescription = null;

    /** @var ?list<list<string>> */
    #[Column(
        type: Types::JSON,
        nullable: true,
    )]
    private ?array $membershipTierOrder = null;

    #[Column(
        type: Types::STRING,
        nullable: true,
        enumType: MembershipPriorityMode::class,
    )]
    public ?MembershipPriorityMode $membershipPriorityMode = null;

    /** @var ?array<string, int> */
    #[Column(
        type: Types::JSON,
        nullable: true,
    )]
    private ?array $membershipPlaces = null;

    /** @var ?list<list<string>> */
    #[Column(
        type: Types::JSON,
        nullable: true,
    )]
    private ?array $cohortTierOrder = null;

    /** @var ?list<list<string>> */
    #[Column(
        type: Types::JSON,
        nullable: true,
    )]
    private ?array $programTypeOrder = null;

    #[Column(
        type: Types::INTEGER,
        nullable: true,
    )]
    public ?int $organisingCommitteePlaces = null;

    /** @var Collection<array-key, SignupRole> */
    #[OneToMany(
        mappedBy: 'signupList',
        targetEntity: SignupRole::class,
        cascade: [
            'persist',
            'remove',
        ],
        orphanRemoval: true,
    )]
    #[OrderBy([
        'position' => SortDirection::Ascending,
        'id' => SortDirection::Ascending,
    ])]
    private Collection $roles;

    /**
     * All additional fields belonging to the activity.
     *
     * @var Collection<array-key, SignupField>
     */
    #[OneToMany(
        mappedBy: 'signupList',
        targetEntity: SignupField::class,
        cascade: [
            'persist',
            'remove',
        ],
        orphanRemoval: true,
    )]
    #[OrderBy([
        'position' => SortDirection::Ascending,
        'id' => SortDirection::Ascending,
    ])]
    private Collection $fields;

    /**
     * All the people who signed up for this SignupList.
     *
     * @var Collection<array-key, Signup>
     */
    #[OneToMany(
        mappedBy: 'signupList',
        targetEntity: Signup::class,
        orphanRemoval: true,
        fetch: 'EXTRA_LAZY',
    )]
    #[OrderBy(value: ['id' => SortDirection::Ascending])]
    private Collection $signUps;

    /**
     * Determines if presence was taken for this SignupList
     */
    #[Column(type: Types::BOOLEAN)]
    public bool $presenceTaken = false;

    /**
     * Determines if the signup list should appear before other signup lists on the same activity.
     */
    #[Column(type: Types::BOOLEAN)]
    public bool $promoted = false;

    public function __construct()
    {
        $this->signUps = new ArrayCollection();
        $this->fields = new ArrayCollection();
        $this->roles = new ArrayCollection();
        // Initialise the required scalars/relations so a freshly-formed (not-yet-hydrated) list is form-ready;
        // Doctrine bypasses the constructor when hydrating, so existing rows keep their persisted values.
        $this->name = new ActivityLocalisedText();
        // A brand-new list starts its own lineage; the cloner copies this id onto each clone.
        $this->lineageId = Uuid::v4();
    }

    public function addField(SignupField $field): void
    {
        if ($this->fields->contains($field)) {
            return;
        }

        $this->fields->add($field);
        $field->signupList = $this;
    }

    public function removeField(SignupField $field): void
    {
        $this->fields->removeElement($field);
    }

    /**
     * Whether anyone has signed up for this list. Once true, the list's structure is frozen (only safe metadata may
     * change) so existing sign-ups are never invalidated.
     */
    public function hasSignUps(): bool
    {
        return !$this->signUps->isEmpty();
    }

    /**
     * A draft clone's own sign-ups stay on the live revision until approval migrates them, so anything that must not
     * disturb people who have already committed looks through the lineage rather than at this copy.
     */
    public function hasLineageSignUps(): bool
    {
        return $this->hasSignUps()
            || (bool) $this->liveCounterpart()?->hasSignUps();
    }

    /**
     * The live revision's list this one descends from (matched by lineage), or null when there is none. A draft's
     * own sign-ups and draw state stay empty until approval, so anything about people or windows already shown to
     * members is answered by the counterpart.
     */
    public function liveCounterpart(): ?SignupList
    {
        if (!$this->belongsToRevision()) {
            return null;
        }

        $live = $this->revision->activity->getLiveRevision();
        if (null === $live) {
            return null;
        }

        foreach ($live->getSignupLists() as $liveList) {
            if ($liveList->lineageId->equals($this->lineageId)) {
                return $liveList;
            }
        }

        return null;
    }

    /**
     * @return Collection<array-key, Signup>
     */
    public function getSignUps(): Collection
    {
        return $this->signUps;
    }

    /**
     * The sign-ups in the order the draw left them, admitted first and the waiting list behind them in the order it
     * was ranked in. Sign-up order until a draw has run, and on a list that never has one.
     *
     * @return list<Signup>
     */
    public function getSignUpsInAdmissionOrder(): array
    {
        $signUps = $this->signUps->getValues();

        if (!$this->isDrawLocked()) {
            return $signUps;
        }

        usort(
            $signUps,
            static fn (Signup $a, Signup $b): int => [
                $a->drawPosition ?? PHP_INT_MAX,
                $a->getId() ?? 0,
            ] <=> [
                $b->drawPosition ?? PHP_INT_MAX,
                $b->getId() ?? 0,
            ],
        );

        return $signUps;
    }

    /**
     * @param Collection<array-key, Signup> $signUps
     */
    public function setSignUps(Collection $signUps): void
    {
        $this->signUps = $signUps;
    }

    /**
     * Whether any of this list's fields holds sensitive data (so its column is hidden from other subscribers on the
     * public view, and the guest form warns before collecting it).
     */
    public function hasSensitiveField(): bool
    {
        foreach ($this->fields as $field) {
            if ($field->isSensitive) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Collection<array-key, SignupField>
     */
    public function getFields(): Collection
    {
        return $this->fields;
    }

    /**
     * @param Collection<array-key, SignupField> $fields
     */
    public function setFields(Collection $fields): void
    {
        $this->fields = $fields;
    }

    /**
     * Whether the sign-up list period is now.
     *
     * NOTE: this does not indicate that one is able to sign up, that depends on other factors such as approval status
     * of the actual activity.
     */
    public function isOpen(): bool
    {
        if (
            null === $this->openDate
            || null === $this->closeDate
        ) {
            return false;
        }

        $now = new DateTime('now');

        return $now >= $this->openDate && $now < $this->closeDate;
    }

    /**
     * Whether the sign-up period has ended. Not the inverse of {@see self::isOpen()}: a list before its open date is
     * neither open nor closed. Drawing/admission only makes sense once subscriptions can no longer change, i.e. once
     * this is true.
     */
    public function isClosed(): bool
    {
        return null !== $this->closeDate
            && new DateTime('now') >= $this->closeDate;
    }

    /**
     * Whether the admission draw has been performed and locked.
     */
    public function isDrawLocked(): bool
    {
        return null !== $this->drawnAt;
    }

    /**
     * A role is handed out once sign-up has closed, so a draw at the list's own moment would run before anybody held
     * one and would make up no shortfall at all. That moment still fixes who is in the draw, only the running waits.
     */
    public function isDrawnByHand(): bool
    {
        return !$this->roles->isEmpty();
    }

    /**
     * The moment the automated draw is due for this list, or `null` when it is never drawn automatically.
     *
     * Lists with unlimited capacity, manual allocation methods, or a conditional draw without a cutoff rule (only for
     * legacy sign-up lists) have no draw moment. First-come-first-served draws at close; a conditional draw at
     * the moment its {@see DrawCutoffRule} describes.
     */
    public function getAutoDrawAt(): ?DateTime
    {
        if (!$this->limitedCapacity) {
            return null;
        }

        if (AllocationMethod::FirstComeFirstServed === $this->allocationMethod) {
            return $this->closeDate;
        }

        if (AllocationMethod::ConditionalDraw !== $this->allocationMethod) {
            return null;
        }

        return match ($this->drawCutoffRule) {
            DrawCutoffRule::OnClose => $this->closeDate,
            DrawCutoffRule::IfFullBefore => $this->drawCutoffAt,
            DrawCutoffRule::AfterDurationOpen => null === $this->drawAfterDurationHours
                || null === $this->openDate
                    ? null
                    : (clone $this->openDate)->modify(sprintf(
                        '+%d hours',
                        $this->drawAfterDurationHours,
                    )),
            null => null,
        };
    }

    /**
     * Whether the automated draw moment has passed (regardless of whether the draw has actually run yet).
     */
    public function isAutoDrawDue(): bool
    {
        $dueAt = $this->getAutoDrawAt();

        return null !== $dueAt
            && new DateTime('now') >= $dueAt;
    }

    /**
     * @return ?list<list<MembershipTier>>
     */
    public function getMembershipTierOrder(): ?array
    {
        $order = self::tierOrder(
            $this->membershipTierOrder,
            MembershipTier::class,
        );

        if (null === $order) {
            return null;
        }

        $tiers = $this->membershipTiers();
        $ranks = [];
        foreach ($order as $rank) {
            $rank = array_values(array_filter(
                $rank,
                static fn (MembershipTier $tier): bool => in_array(
                    $tier,
                    $tiers,
                    true,
                ),
            ));

            if ([] === $rank) {
                continue;
            }

            $ranks[] = $rank;
        }

        return $ranks;
    }

    /**
     * The order a list starts from before anybody arranges it: the association's own, with the tiers admitted
     * together on one rank, less the rank a members-only list has nobody for.
     *
     * @return list<list<MembershipTier>>
     */
    public function membershipRanks(): array
    {
        $tiers = $this->membershipTiers();
        $ranks = [];
        foreach (MembershipTier::defaultRanks() as $rank) {
            $rank = array_values(array_filter(
                $rank,
                static fn (MembershipTier $tier): bool => in_array(
                    $tier,
                    $tiers,
                    true,
                ),
            ));

            if ([] === $rank) {
                continue;
            }

            $ranks[] = $rank;
        }

        return $ranks;
    }

    /**
     * @return list<MembershipTier>
     */
    public function membershipTiers(): array
    {
        $tiers = MembershipTier::defaultOrder();

        if (!$this->onlyGEWIS) {
            return $tiers;
        }

        return array_values(array_filter(
            $tiers,
            static fn (MembershipTier $tier): bool => MembershipTier::NonMember !== $tier,
        ));
    }

    /**
     * @param ?list<list<MembershipTier>> $order
     */
    public function setMembershipTierOrder(?array $order): void
    {
        $this->membershipTierOrder = self::tierValues($order);
    }

    /**
     * The places held for one rank of the membership order. Tiers admitted together share the places held for them:
     * the rank is the pool, not the tier.
     *
     * @param list<MembershipTier> $rank
     */
    public function getMembershipPlacesForRank(array $rank): ?int
    {
        return $this->membershipPlaces[self::rankKey($rank)] ?? null;
    }

    /**
     * The tiers of a rank as the places held for it are keyed, in the association's own order so that dragging two
     * tiers past one another within a rank does not lose the number held for them.
     *
     * @param list<MembershipTier> $rank
     */
    public static function rankKey(array $rank): string
    {
        $tiers = [];
        foreach (MembershipTier::defaultOrder() as $tier) {
            if (
                !in_array(
                    $tier,
                    $rank,
                    true,
                )
            ) {
                continue;
            }

            $tiers[] = $tier->value;
        }

        return implode(
            '+',
            $tiers,
        );
    }

    /**
     * @return ?array<string, int>
     */
    public function getHeldMembershipPlaces(): ?array
    {
        return $this->membershipPlaces;
    }

    /**
     * @param ?array<string, int> $places
     */
    public function setHeldMembershipPlaces(?array $places): void
    {
        $this->membershipPlaces = [] === $places
            ? null
            : $places;
    }

    /**
     * @return array<string, int>
     */
    public function getMembershipPlaces(): array
    {
        if (MembershipPriorityMode::ReservedPlaces !== $this->membershipPriorityMode) {
            return [];
        }

        $places = [];
        foreach ($this->getMembershipTierOrder() ?? [] as $rank) {
            $key = self::rankKey($rank);
            $places[$key] = $this->membershipPlaces[$key] ?? 0;
        }

        return $places;
    }

    /**
     * @return ?list<list<CohortTier>>
     */
    public function getCohortTierOrder(): ?array
    {
        if (!$this->onlyGEWIS) {
            return null;
        }

        return self::tierOrder(
            $this->cohortTierOrder,
            CohortTier::class,
        );
    }

    /**
     * @param ?list<list<CohortTier>> $order
     */
    public function setCohortTierOrder(?array $order): void
    {
        $this->cohortTierOrder = self::tierValues($order);
    }

    /**
     * @return ?list<list<ProgramType>>
     */
    public function getProgramTypeOrder(): ?array
    {
        if (!$this->onlyGEWIS) {
            return null;
        }

        return self::tierOrder(
            $this->programTypeOrder,
            ProgramType::class,
        );
    }

    /**
     * @param ?list<list<ProgramType>> $order
     */
    public function setProgramTypeOrder(?array $order): void
    {
        $this->programTypeOrder = self::tierValues($order);
    }

    /**
     * @return Collection<array-key, SignupRole>
     */
    public function getRoles(): Collection
    {
        return $this->roles;
    }

    public function addRole(SignupRole $role): void
    {
        if ($this->roles->contains($role)) {
            return;
        }

        $this->roles->add($role);
        $role->signupList = $this;
    }

    public function removeRole(SignupRole $role): void
    {
        $this->roles->removeElement($role);
    }

    public function hasPriorityModifiers(): bool
    {
        // Through the getters: an order the list can no longer act on is stored but not held.
        return null !== $this->getMembershipTierOrder()
            || null !== $this->getCohortTierOrder()
            || null !== $this->getProgramTypeOrder()
            || null !== $this->organisingCommitteePlaces
            || !$this->roles->isEmpty();
    }

    /**
     * @template T of PriorityTierInterface
     *
     * @param ?list<list<string>|string> $stored
     * @param class-string<T>            $tier
     *
     * @return ?list<list<T>>
     */
    private static function tierOrder(
        ?array $stored,
        string $tier,
    ): ?array {
        if (null === $stored) {
            return null;
        }

        $order = [];
        $seen = [];
        foreach ($stored as $rank) {
            $tiers = [];
            foreach (is_array($rank) ? $rank : [$rank] as $value) {
                $case = $tier::tryFrom($value);
                if (
                    null === $case
                    || in_array(
                        $case,
                        $seen,
                        true,
                    )
                ) {
                    continue;
                }

                $seen[] = $case;
                $tiers[] = $case;
            }

            if ([] === $tiers) {
                continue;
            }

            $order[] = $tiers;
        }

        if ([] === $order) {
            return null;
        }

        // What the stored order left out is appended the way the association would rank it, ties and all, so a tier
        // added to the enum lands beside the ones it belongs with rather than alone at the end.
        foreach ($tier::defaultRanks() as $rank) {
            $missing = [];
            foreach ($rank as $case) {
                if (
                    in_array(
                        $case,
                        $seen,
                        true,
                    )
                ) {
                    continue;
                }

                $missing[] = $case;
            }

            if ([] === $missing) {
                continue;
            }

            $order[] = $missing;
        }

        return $order;
    }

    /**
     * @param ?list<list<PriorityTierInterface>> $order
     *
     * @return ?list<list<string>>
     */
    private static function tierValues(?array $order): ?array
    {
        if (
            null === $order
            || [] === $order
        ) {
            return null;
        }

        return array_values(array_map(
            static fn (array $rank): array => array_values(array_map(
                static fn (PriorityTierInterface $tier): string => strval($tier->value),
                $rank,
            )),
            $order,
        ));
    }

    /**
     * Whether this list has been attached to a revision yet. A brand-new list added through the form has none until
     * it is bound; a cloned draft list already does (so its date/freeze rules look through its lineage).
     */
    public function belongsToRevision(): bool
    {
        return isset($this->revision);
    }

    /**
     * Returns the activity this list ultimately belongs to (via its owning revision). Kept so resource/GDPR call
     * sites that reach for the activity keep working unchanged.
     */
    public function getActivity(): Activity
    {
        return $this->revision->activity;
    }

    /**
     * @return array<array-key, array{name: string, minimum: int}>
     */
    private function rolesToArray(): array
    {
        $roles = [];
        foreach ($this->getRoles() as $role) {
            $roles[] = [
                'name' => $role->name,
                'minimum' => $role->minimum,
            ];
        }

        return $roles;
    }

    /**
     * Returns an associative array representation of this object.
     *
     * @return SignupListArrayType
     */
    public function toArray(): array
    {
        $fieldsArrays = [];
        foreach ($this->getFields() as $field) {
            $fieldsArrays[] = $field->toArray();
        }

        $rolesArrays = $this->rolesToArray();

        return [
            'id' => $this->getId(),
            'name' => $this->name->getValueNL(),
            'nameEn' => $this->name->getValueEN(),
            'openDate' => $this->openDate,
            'closeDate' => $this->closeDate,
            'onlyGEWIS' => $this->onlyGEWIS,
            'displaySubscribedNumber' => $this->displaySubscribedNumber,
            'limitedCapacity' => $this->limitedCapacity,
            'capacity' => $this->capacity,
            'allocationMethod' => $this->allocationMethod->value,
            'drawCutoffRule' => $this->drawCutoffRule?->value,
            'drawCutoffAt' => $this->drawCutoffAt,
            'drawAfterDurationHours' => $this->drawAfterDurationHours,
            'externalPolicyUrl' => $this->externalPolicyUrl,
            'externalForceOrdering' => $this->externalForceOrdering,
            'externalPaymentByExternal' => $this->externalPaymentByExternal,
            'customMethodDescription' => $this->customMethodDescription,
            'membershipTierOrder' => self::tierValues($this->getMembershipTierOrder()),
            'membershipPriorityMode' => $this->membershipPriorityMode?->value,
            'membershipPlaces' => $this->membershipPlaces,
            'cohortTierOrder' => self::tierValues($this->getCohortTierOrder()),
            'programTypeOrder' => self::tierValues($this->getProgramTypeOrder()),
            'organisingCommitteePlaces' => $this->organisingCommitteePlaces,
            'roles' => $rolesArrays,
            'presenceTaken' => $this->presenceTaken,
            'promoted' => $this->promoted,
            'fields' => $fieldsArrays,
        ];
    }

    /**
     * @return SignupListGdprArrayType
     */
    public function toGdprArray(): array
    {
        /** @var ImportedSignupFieldGdprArrayType[] $fieldsArrays */
        $fieldsArrays = [];
        foreach ($this->getFields() as $field) {
            $fieldsArrays[] = $field->toGdprArray();
        }

        $rolesArrays = $this->rolesToArray();

        return [
            'id' => $this->getId(),
            'name' => $this->name->toGdprArray(),
            'openDate' => $this->openDate?->format(DateTimeInterface::ATOM),
            'closeDate' => $this->closeDate?->format(DateTimeInterface::ATOM),
            'onlyGEWIS' => $this->onlyGEWIS,
            'displaySubscribedNumber' => $this->displaySubscribedNumber,
            'limitedCapacity' => $this->limitedCapacity,
            'capacity' => $this->capacity,
            'allocationMethod' => $this->allocationMethod->value,
            'drawCutoffRule' => $this->drawCutoffRule?->value,
            'drawCutoffAt' => $this->drawCutoffAt?->format(DateTimeInterface::ATOM),
            'drawAfterDurationHours' => $this->drawAfterDurationHours,
            'externalPolicyUrl' => $this->externalPolicyUrl,
            'externalForceOrdering' => $this->externalForceOrdering,
            'externalPaymentByExternal' => $this->externalPaymentByExternal,
            'customMethodDescription' => $this->customMethodDescription,
            'membershipTierOrder' => self::tierValues($this->getMembershipTierOrder()),
            'membershipPriorityMode' => $this->membershipPriorityMode?->value,
            'membershipPlaces' => $this->membershipPlaces,
            'cohortTierOrder' => self::tierValues($this->getCohortTierOrder()),
            'programTypeOrder' => self::tierValues($this->getProgramTypeOrder()),
            'organisingCommitteePlaces' => $this->organisingCommitteePlaces,
            'roles' => $rolesArrays,
            'presenceTaken' => $this->presenceTaken,
            'promoted' => $this->promoted,
            'fields' => $fieldsArrays,
        ];
    }
}
