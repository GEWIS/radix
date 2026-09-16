<?php

declare(strict_types=1);

namespace App\EventListener\Activity;

use App\Entity\Activity\ActivityDateOption;
use App\Entity\Activity\ActivityLocalisedText;
use App\Entity\Activity\ActivityProposal;
use App\Entity\Activity\Enums\DateOptionStatus;
use App\Service\Activity\ActivityDraftFactory;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\Event;

use function sprintf;

/**
 * Turns a reserved day into the activity it is meant to become.
 *
 * The whole point of connecting the two. A body that has just been given a day would otherwise have to go to another
 * screen and type its own proposal in again; instead the activity is already there as a draft with the body, the
 * working title, the description and the days, and the body finishes it through the ordinary revision workflow. It is
 * also what the budget reminder measures against.
 *
 * The days have no clock time, because the calendar reserves days rather than hours, so the draft opens at midnight
 * and the schedule is the first thing left to fill in. Creating a time from "evening" would be making data up.
 */
#[AsEventListener(event: 'workflow.activity_proposal.entered.scheduled')]
final readonly class SeedActivityFromProposalListener
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private EntityManagerInterface $entityManager,
        private ActivityDraftFactory $activityDraftFactory,
    ) {
    }

    /**
     * @param Event<object> $event
     */
    public function __invoke(Event $event): void
    {
        $proposal = $event->getSubject();

        if (!$proposal instanceof ActivityProposal) {
            return;
        }

        $chosen = $proposal->chosenOption;

        // Scheduling without a day picked is meaningless; the caller that applied the transition must set one.
        if (null === $chosen) {
            return;
        }

        // The statuses are settled here rather than by the caller that applied the transition, so a day reserved from
        // the queue, from a script or from a test all end up in the same state: the chosen day reserved, every other
        // day the body requested released for the next proposal in line.
        $proposal->declineDateOptionsOtherThan($chosen);
        $chosen->status = DateOptionStatus::Approved;

        // Reopening and scheduling again must not start a second activity.
        if (null !== $proposal->activity) {
            return;
        }

        $activity = $this->activityDraftFactory->newActivity($proposal->getCreatedBy());
        $revision = $activity->getCurrentRevision();

        if (null === $revision) {
            return;
        }

        $revision->organ = $proposal->organ;
        $revision->name = new ActivityLocalisedText(
            $proposal->name,
            $proposal->name,
        );

        $description = $proposal->description;

        if (null !== $description) {
            $revision->description = new ActivityLocalisedText(
                $description,
                $description,
            );
        }

        $revision->beginTime = $this->startOf($chosen);
        $revision->endTime = $this->endOf($chosen);

        $this->entityManager->persist($activity);
        $proposal->activity = $activity;
    }

    private function startOf(ActivityDateOption $option): DateTimeImmutable
    {
        return new DateTimeImmutable(sprintf(
            '%s 00:00:00',
            $option->beginsAt->format('Y-m-d'),
        ));
    }

    private function endOf(ActivityDateOption $option): DateTimeImmutable
    {
        return new DateTimeImmutable(sprintf(
            '%s 23:59:59',
            $option->endsAt->format('Y-m-d'),
        ));
    }
}
