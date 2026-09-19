<?php

declare(strict_types=1);

namespace App\EventListener\Activity;

use App\Entity\Activity\ActivityRevision;
use App\Message\Activity\RenderActivityShareImageMessage;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Workflow\Event\EnteredEvent;

/**
 * Every approval redraws the cards, because an edit can change the name, the date or the location they show.
 */
#[AsEventListener(event: 'workflow.revision.entered.approved')]
final readonly class RenderShareImageOnApprovalListener
{
    public function __construct(private MessageBusInterface $messageBus)
    {
    }

    /**
     * @param EnteredEvent<object> $event
     */
    public function __invoke(EnteredEvent $event): void
    {
        $revision = $event->getSubject();
        if (!$revision instanceof ActivityRevision) {
            return;
        }

        $id = $revision->id;
        if (null === $id) {
            return;
        }

        $this->messageBus->dispatch(new RenderActivityShareImageMessage($id));
    }
}
