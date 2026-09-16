<?php

declare(strict_types=1);

namespace App\Entity\Activity;

use App\Entity\Activity\Enums\BudgetClearance;
use App\Entity\Activity\Enums\DateOptionStatus;
use App\Entity\Activity\Enums\ProposalStatus;
use App\Entity\Application\Traits\IdentifiableTrait;
use App\Entity\Application\Traits\TimestampableTrait;
use App\Entity\Decision\Member;
use App\Entity\Decision\Organ;
use App\Repository\Activity\ActivityProposalRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\HasLifecycleCallbacks;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\OrderBy;
use Doctrine\ORM\Mapping\PrePersist;
use LogicException;
use SortDirection;

use function count;
use function sprintf;

/**
 * An activity a body would like to host, put forward during an option period with up to three dates it could be on.
 *
 * The board picks one of the dates, which reserves it and starts the real activity off as a draft
 * ({@see self::$activity}). From there it is the ordinary activity workflow; this entity only reserves the date until
 * then, and records whether the financial side has been settled in time to keep it.
 */
#[Entity(repositoryClass: ActivityProposalRepository::class)]
#[HasLifecycleCallbacks]
#[Index(
    fields: [
        'period',
        'organ',
    ],
    name: 'activity_proposal_period_organ',
)]
class ActivityProposal
{
    use IdentifiableTrait;
    use TimestampableTrait;

    /**
     * How many dates a body may put forward for one activity. A house rule that dates from the paper calendar.
     */
    public const int MAX_DATE_OPTIONS = 3;

    /**
     * The round this proposal was submitted for. A real association, so counting a body's proposals in a period is a
     * query on it; the previous design had none and inferred period membership from creation timestamps.
     */
    #[ManyToOne(
        targetEntity: OptionPeriod::class,
        inversedBy: 'proposals',
    )]
    #[JoinColumn(
        referencedColumnName: 'id',
        nullable: false,
    )]
    public OptionPeriod $period;

    /**
     * The body hosting the activity, or null when the board is hosting it itself. The board is not a body, so it
     * cannot be named here, and no proposal limit applies to it either.
     */
    #[ManyToOne(targetEntity: Organ::class)]
    #[JoinColumn(
        referencedColumnName: 'id',
        nullable: true,
    )]
    public ?Organ $organ = null;

    /**
     * A working title. Everyone reads it on the calendar, so it has to say what the activity is.
     */
    #[Column(
        type: Types::STRING,
        length: 128,
    )]
    public string $name;

    /**
     * Anything the board should know while deciding, such as a dependency on somebody outside the association.
     */
    #[Column(
        type: Types::TEXT,
        nullable: true,
    )]
    public ?string $description = null;

    /**
     * The member who submitted the proposal, or null once that member has been removed from the register. The
     * proposal stays: the day it reserves belongs to the body that requested it, not to the person who entered it.
     */
    #[ManyToOne(targetEntity: Member::class)]
    #[JoinColumn(
        referencedColumnName: 'lidnr',
        nullable: true,
        onDelete: 'SET NULL',
    )]
    private ?Member $createdBy = null;

    #[Column(
        type: Types::STRING,
        length: 32,
        enumType: ProposalStatus::class,
    )]
    public ProposalStatus $status = ProposalStatus::Submitted;

    /** @var Collection<array-key, ActivityDateOption> */
    #[OneToMany(
        targetEntity: ActivityDateOption::class,
        mappedBy: 'proposal',
        cascade: [
            'persist',
            'remove',
        ],
        orphanRemoval: true,
    )]
    #[OrderBy(['position' => SortDirection::Ascending])]
    private Collection $dateOptions;

    /**
     * The date the board reserved. A unique association rather than a status anybody has to count, so a proposal
     * cannot end up with two dates however the transitions are applied.
     */
    #[OneToOne(targetEntity: ActivityDateOption::class)]
    #[JoinColumn(
        referencedColumnName: 'id',
        nullable: true,
    )]
    public ?ActivityDateOption $chosenOption = null;

    /**
     * The activity this proposal became, started off as a draft the moment a date was reserved.
     *
     * `SET NULL` on delete because abandoned drafts really are removed
     * ({@see \App\Command\Activity\DeleteStaleDraftsCommand}), and the proposal has to survive that: it is the record
     * of who reserved the date.
     */
    #[OneToOne(targetEntity: Activity::class)]
    #[JoinColumn(
        referencedColumnName: 'id',
        nullable: true,
        onDelete: 'SET NULL',
    )]
    public ?Activity $activity = null;

    #[ManyToOne(targetEntity: Member::class)]
    #[JoinColumn(
        referencedColumnName: 'lidnr',
        nullable: true,
        onDelete: 'SET NULL',
    )]
    public ?Member $decidedBy = null;

    #[Column(
        type: Types::DATETIME_IMMUTABLE,
        nullable: true,
    )]
    public ?DateTimeImmutable $decidedAt = null;

    /**
     * How the financial side was settled, or null while it has not been. The reminder and the lapse act on null;
     * either outcome stops them.
     */
    #[Column(
        type: Types::STRING,
        length: 32,
        nullable: true,
        enumType: BudgetClearance::class,
    )]
    public ?BudgetClearance $budgetClearance = null;

    #[ManyToOne(targetEntity: Member::class)]
    #[JoinColumn(
        referencedColumnName: 'lidnr',
        nullable: true,
        onDelete: 'SET NULL',
    )]
    public ?Member $budgetClearedBy = null;

    #[Column(
        type: Types::DATETIME_IMMUTABLE,
        nullable: true,
    )]
    public ?DateTimeImmutable $budgetClearedAt = null;

    /**
     * When the body was last notified that the date is at risk, so a nightly run does not notify them again every
     * night.
     */
    #[Column(
        type: Types::DATETIME_IMMUTABLE,
        nullable: true,
    )]
    public ?DateTimeImmutable $budgetRemindedAt = null;

    public function __construct()
    {
        $this->dateOptions = new ArrayCollection();
    }

    public function getCreatedBy(): ?Member
    {
        return $this->createdBy;
    }

    public function setCreatedBy(Member $createdBy): void
    {
        $this->createdBy = $createdBy;
    }

    /**
     * @return Collection<array-key, ActivityDateOption>
     */
    public function getDateOptions(): Collection
    {
        return $this->dateOptions;
    }

    public function addDateOption(ActivityDateOption $dateOption): void
    {
        if ($this->dateOptions->contains($dateOption)) {
            return;
        }

        $this->assertRoomForAnotherDateOption();

        $this->dateOptions->add($dateOption);
        $dateOption->proposal = $this;
    }

    public function removeDateOption(ActivityDateOption $dateOption): void
    {
        $this->dateOptions->removeElement($dateOption);
    }

    /**
     * The dates that still block other proposals, in the body's own order of preference.
     *
     * @return ActivityDateOption[]
     */
    public function getStandingDateOptions(): array
    {
        return $this->dateOptions
            ->filter(static fn (ActivityDateOption $option): bool => $option->status->isStanding())
            ->getValues();
    }

    /**
     * Whether the financial side has been settled, either way.
     */
    public function isBudgetCleared(): bool
    {
        return null !== $this->budgetClearance;
    }

    /**
     * Turns every date that was not picked down, which releases those dates for the next proposal in line.
     */
    public function declineDateOptionsOtherThan(?ActivityDateOption $keep): void
    {
        foreach ($this->dateOptions as $dateOption) {
            if ($dateOption === $keep) {
                continue;
            }

            $dateOption->status = DateOptionStatus::Declined;
        }
    }

    /**
     * A proposal is submitted with its dates in one go, so the count is checked at insert.
     *
     * There is deliberately no `PreUpdate` counterpart: Doctrine only raises that event when a field of the entity
     * itself changed, so an edit that only added a date would not trigger it. Editing goes through the form, where
     * `Count` applies the same check and can report the field that is wrong.
     */
    #[PrePersist]
    public function assertDateOptionCount(): void
    {
        $count = count($this->dateOptions);

        if (
            $count >= 1
            && $count <= self::MAX_DATE_OPTIONS
        ) {
            return;
        }

        throw new LogicException(sprintf(
            'A proposal must put forward between 1 and %d dates, got %d.',
            self::MAX_DATE_OPTIONS,
            $count,
        ));
    }

    private function assertRoomForAnotherDateOption(): void
    {
        if (count($this->dateOptions) < self::MAX_DATE_OPTIONS) {
            return;
        }

        throw new LogicException(sprintf(
            'A proposal cannot put forward more than %d dates.',
            self::MAX_DATE_OPTIONS,
        ));
    }
}
