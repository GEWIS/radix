<?php

declare(strict_types=1);

namespace App\Entity\Decision;

use App\Entity\Application\Traits\TimestampableTrait;
use App\Entity\Database\Enums\MeetingTypes;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\HasLifecycleCallbacks;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToOne;

/**
 * Locally-owned details of a meeting. The {@see Meeting} itself is projected from the ledger and read-only here
 * (only the date is carried over), so anything the board manages on the website lives here.
 */
#[Entity]
#[HasLifecycleCallbacks]
class MeetingLocalDetails
{
    use TimestampableTrait;

    /**
     * Meeting type.
     */
    #[Id]
    #[Column(type: Types::ENUM)]
    private MeetingTypes $meeting_type;

    /**
     * Meeting number.
     */
    #[Id]
    #[Column(type: Types::INTEGER)]
    private int $meeting_number;

    #[OneToOne(
        targetEntity: Meeting::class,
        inversedBy: 'localDetails',
    )]
    #[JoinColumn(
        name: 'meeting_type',
        referencedColumnName: 'type',
        nullable: false,
    )]
    #[JoinColumn(
        name: 'meeting_number',
        referencedColumnName: 'number',
        nullable: false,
    )]
    public private(set) Meeting $meeting;

    /**
     * The time the meeting starts. Meetings have no end time; they run until closed.
     */
    #[Column(
        type: Types::TIME_IMMUTABLE,
        nullable: true,
    )]
    public ?DateTimeImmutable $startTime = null;

    #[Column(
        type: Types::STRING,
        nullable: true,
    )]
    public ?string $location = null;

    public function setMeeting(Meeting $meeting): void
    {
        $meeting->localDetails = $this;
        $this->meeting = $meeting;
        $this->meeting_type = $meeting->type;
        $this->meeting_number = $meeting->number;
    }
}
