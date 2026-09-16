<?php

declare(strict_types=1);

namespace App\Entity\Activity;

use App\Entity\Activity\Enums\DateOptionStatus;
use App\Entity\Activity\Enums\TimeOfDay;
use App\Entity\Application\Traits\IdentifiableTrait;
use App\Entity\Decision\Member;
use App\Repository\Activity\ActivityDateOptionRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;

/**
 * One of the dates a body put forward for a proposed activity.
 *
 * Whole days, not clock times: the option calendar reserves a date, and {@see self::$timeOfDay} records which part
 * of the day is wanted. A body may put forward up to three of these per proposal and the board picks one.
 */
#[Entity(repositoryClass: ActivityDateOptionRepository::class)]
#[Index(
    fields: [
        'beginsAt',
        'endsAt',
    ],
    name: 'activity_date_option_span',
)]
class ActivityDateOption
{
    use IdentifiableTrait;

    #[ManyToOne(
        targetEntity: ActivityProposal::class,
        inversedBy: 'dateOptions',
    )]
    #[JoinColumn(
        referencedColumnName: 'id',
        nullable: false,
    )]
    public ActivityProposal $proposal;

    /**
     * The first day the activity would take place on.
     */
    #[Column(type: Types::DATE_IMMUTABLE)]
    public DateTimeImmutable $beginsAt;

    /**
     * The last day the activity would take place on, the same as {@see self::$beginsAt} for anything within one day.
     */
    #[Column(type: Types::DATE_IMMUTABLE)]
    public DateTimeImmutable $endsAt;

    #[Column(
        type: Types::STRING,
        length: 32,
        enumType: TimeOfDay::class,
    )]
    public TimeOfDay $timeOfDay = TimeOfDay::Evening;

    /**
     * The position of this date in the body's own order of preference, counting from one. The board is not bound by
     * it, but it records what the body prefers.
     */
    #[Column(type: Types::SMALLINT)]
    public int $position = 1;

    #[Column(
        type: Types::STRING,
        length: 32,
        enumType: DateOptionStatus::class,
    )]
    public DateOptionStatus $status = DateOptionStatus::Proposed;

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
     * Whether this option takes up the given day, which for anything spanning several days is every day in between.
     */
    public function coversDay(DateTimeInterface $day): bool
    {
        return $this->beginsAt->format('Y-m-d') <= $day->format('Y-m-d')
            && $this->endsAt->format('Y-m-d') >= $day->format('Y-m-d');
    }

    public function spansMultipleDays(): bool
    {
        return $this->beginsAt->format('Y-m-d') !== $this->endsAt->format('Y-m-d');
    }
}
