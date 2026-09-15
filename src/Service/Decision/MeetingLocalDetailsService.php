<?php

declare(strict_types=1);

namespace App\Service\Decision;

use App\Entity\Decision\Enums\MeetingActivityVerbs;
use App\Entity\Decision\Meeting;
use App\Entity\Decision\MeetingLocalDetails;
use App\Entity\User\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function preg_match;
use function trim;

/**
 * The locally-owned time and place of a meeting; everything else about a meeting is projected from the ledger.
 */
final readonly class MeetingLocalDetailsService
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private EntityManagerInterface $entityManager,
        private MeetingActivityLogger $activityLogger,
    ) {
    }

    /**
     * Upserts the details; an unparseable or empty start time and an empty location clear the respective field.
     */
    public function updateDetails(
        Meeting $meeting,
        ?string $startTime,
        ?string $location,
        User $actor,
    ): void {
        $details = $meeting->localDetails;
        $isNew = null === $details;

        if (null === $details) {
            $details = new MeetingLocalDetails();
            $details->setMeeting($meeting);
            $this->entityManager->persist($details);
        }

        $time = null;
        if (
            null !== $startTime
            && 1 === preg_match(
                '/^\d{1,2}:\d{2}$/',
                trim($startTime),
            )
        ) {
            $time = new DateTimeImmutable(trim($startTime));
        }

        $location = null === $location || '' === trim($location)
            ? null
            : trim($location);

        if (
            !$isNew
            && $time?->format('H:i') === $details->startTime?->format('H:i')
            && $location === $details->location
        ) {
            return;
        }

        $details->startTime = $time;
        $details->location = $location;

        $this->activityLogger->log(
            $actor,
            $meeting,
            MeetingActivityVerbs::DetailsUpdated,
            '',
        );
        $this->entityManager->flush();
    }
}
