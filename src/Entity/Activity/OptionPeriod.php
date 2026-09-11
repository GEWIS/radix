<?php

declare(strict_types=1);

namespace App\Entity\Activity;

use App\Entity\Application\Traits\IdentifiableTrait;
use App\Entity\Application\Traits\TimestampableTrait;
use App\Repository\Activity\OptionPeriodRepository;
use DateTime;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\HasLifecycleCallbacks;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OrderBy;
use SortDirection;

/**
 * A round of the option calendar, opened by the board before a quartile.
 *
 * Two windows, which are not the same thing and were never told apart before: bodies may hand in proposals between
 * {@see self::$submissionOpensAt} and {@see self::$submissionClosesAt}, and the dates they propose have to fall
 * between {@see self::$startsAt} and {@see self::$endsAt}. Handing in usually happens well before the quartile the
 * dates are in.
 *
 * A period may carry its own default number of proposals per body. It never carries a list of bodies: which bodies
 * exist is not this period's business, and a body founded after the period was opened has to be able to take part.
 */
#[Entity(repositoryClass: OptionPeriodRepository::class)]
#[HasLifecycleCallbacks]
#[Index(
    fields: [
        'submissionOpensAt',
        'submissionClosesAt',
    ],
    name: 'option_period_submission_window',
)]
#[Index(
    fields: ['startsAt'],
    name: 'option_period_starts_at',
)]
class OptionPeriod
{
    use IdentifiableTrait;
    use TimestampableTrait;

    /**
     * What the board calls this round, such as "Q1 2026-2027".
     */
    #[Column(
        type: Types::STRING,
        length: 128,
    )]
    public string $name;

    /**
     * From when bodies may hand in proposals for this period.
     */
    #[Column(type: Types::DATETIME_MUTABLE)]
    public DateTime $submissionOpensAt;

    /**
     * Until when bodies may hand in proposals for this period.
     */
    #[Column(type: Types::DATETIME_MUTABLE)]
    public DateTime $submissionClosesAt;

    /**
     * The first day a proposed activity may take place on.
     */
    #[Column(type: Types::DATE_MUTABLE)]
    public DateTime $startsAt;

    /**
     * The last day a proposed activity may take place on.
     */
    #[Column(type: Types::DATE_MUTABLE)]
    public DateTime $endsAt;

    /**
     * How many activities a body may propose in this period, when the board wants a different number than usual.
     * Null leaves the association-wide default in place.
     */
    #[Column(
        type: Types::INTEGER,
        nullable: true,
    )]
    public ?int $defaultMaxProposals = null;

    /** @var Collection<array-key, ActivityProposal> */
    #[OneToMany(
        targetEntity: ActivityProposal::class,
        mappedBy: 'period',
    )]
    #[OrderBy(['createdAt' => SortDirection::Ascending])]
    private Collection $proposals;

    public function __construct()
    {
        $this->proposals = new ArrayCollection();
    }

    /**
     * @return Collection<array-key, ActivityProposal>
     */
    public function getProposals(): Collection
    {
        return $this->proposals;
    }

    /**
     * Whether bodies may hand in proposals at the given moment.
     */
    public function isOpenAt(DateTimeInterface $moment): bool
    {
        return $this->submissionOpensAt <= $moment
            && $this->submissionClosesAt >= $moment;
    }

    /**
     * Whether a stretch of days falls entirely inside the days this period covers.
     */
    public function covers(
        DateTimeInterface $from,
        DateTimeInterface $until,
    ): bool {
        return $this->startsAt <= $from
            && $this->endsAt >= $until;
    }
}
