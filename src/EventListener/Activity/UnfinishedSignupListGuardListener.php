<?php

declare(strict_types=1);

namespace App\EventListener\Activity;

use App\Entity\Activity\ActivityRevision;
use App\Util\Activity\SignupListRule;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\GuardEvent;

final readonly class UnfinishedSignupListGuardListener
{
    /**
     * @param GuardEvent<object> $event
     */
    #[AsEventListener(event: 'workflow.revision.guard.submit')]
    #[AsEventListener(event: 'workflow.revision.guard.approve')]
    public function __invoke(GuardEvent $event): void
    {
        $revision = $event->getSubject();

        if (
            !$revision instanceof ActivityRevision
            || null === SignupListRule::firstUnfinished($revision)
        ) {
            return;
        }

        $event->setBlocked(
            true,
            'A sign-up list of this revision has not been filled in.',
        );
    }
}
