<?php

declare(strict_types=1);

namespace App\EventListener\Activity;

use App\Entity\Activity\ActivityProposal;
use App\Entity\Activity\Enums\DateOptionStatus;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\Event;

/**
 * Frees the days a proposal had reserved once it is out of the running. It also keeps the budget timestamp accurate.
 *
 * A day that is no longer reserved has to be free the moment the proposal releases it, or the calendar quietly fills
 * with claims that no longer mean anything, which is what the old overdue email existed to clean up by hand.
 *
 * An activity that was already started for the body is deliberately left where it is. If no member ever edits it,
 * {@see \App\Command\Activity\DeleteStaleDraftsCommand} reaps it after a month, which is its whole job and is more
 * careful about it than a workflow listener could be; and if a member did edit it, it is their work and losing a day
 * is no reason to throw it away. The proposal keeps pointing at it either way, and the association is `SET NULL` on
 * delete, so the record of who reserved the day survives the reaping.
 */
final readonly class ReleaseProposalDaysListener
{
    /**
     * @param Event<object> $event
     */
    #[AsEventListener(event: 'workflow.activity_proposal.entered.withdrawn')]
    public function onWithdrawn(Event $event): void
    {
        $this->release(
            $event,
            DateOptionStatus::Withdrawn,
        );
    }

    /**
     * @param Event<object> $event
     */
    #[AsEventListener(event: 'workflow.activity_proposal.entered.lapsed')]
    public function onLapsed(Event $event): void
    {
        $this->release(
            $event,
            DateOptionStatus::Declined,
        );
    }

    /**
     * @param Event<object> $event
     */
    #[AsEventListener(event: 'workflow.activity_proposal.entered.declined')]
    public function onDeclined(Event $event): void
    {
        $this->release(
            $event,
            DateOptionStatus::Declined,
        );
    }

    /**
     * Reaching `scheduled` means the financial side is not settled: either it never was, or the board has just
     * withdrawn a clearance. Either way the timestamp is cleared and the reminder is armed again.
     *
     * @param Event<object> $event
     */
    #[AsEventListener(event: 'workflow.activity_proposal.entered.scheduled')]
    public function onScheduled(Event $event): void
    {
        $proposal = $event->getSubject();

        if (!$proposal instanceof ActivityProposal) {
            return;
        }

        $proposal->budgetClearance = null;
        $proposal->budgetClearedBy = null;
        $proposal->budgetClearedAt = null;
        $proposal->budgetRemindedAt = null;
    }

    /**
     * @param Event<object> $event
     */
    private function release(
        Event $event,
        DateOptionStatus $status,
    ): void {
        $proposal = $event->getSubject();

        if (!$proposal instanceof ActivityProposal) {
            return;
        }

        foreach ($proposal->getDateOptions() as $dateOption) {
            if (!$dateOption->status->isStanding()) {
                continue;
            }

            $dateOption->status = $status;
        }
    }
}
