<?php

declare(strict_types=1);

namespace App\Tests\EventListener\Activity;

use App\Entity\Activity\ActivityRevision;
use App\Entity\Career\CompanyRevision;
use App\EventListener\Activity\RenderShareImageOnApprovalListener;
use App\Message\Activity\RenderActivityShareImageMessage;
use Override;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Workflow\Event\EnteredEvent;
use Symfony\Component\Workflow\Marking;

/**
 * The cards are redrawn on every approval of an activity revision and on nothing else.
 */
final class RenderShareImageOnApprovalListenerTest extends TestCase
{
    /** @var list<object> */
    private array $dispatched = [];

    private RenderShareImageOnApprovalListener $listener;

    #[Override]
    protected function setUp(): void
    {
        $bus = self::createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            $this->dispatched[] = $message;

            return new Envelope($message);
        });

        $this->listener = new RenderShareImageOnApprovalListener($bus);
    }

    public function testDispatchesTheRevisionThatWasApproved(): void
    {
        $revision = new ActivityRevision();
        new ReflectionProperty(
            $revision,
            'id',
        )->setValue(
            $revision,
            42,
        );

        $this->listener->__invoke($this->enteredEvent($revision));

        self::assertCount(
            1,
            $this->dispatched,
        );
        $message = $this->dispatched[0];
        self::assertInstanceOf(
            RenderActivityShareImageMessage::class,
            $message,
        );
        self::assertSame(
            42,
            $message->getRevisionId(),
        );
    }

    public function testIgnoresOtherRevisions(): void
    {
        $this->listener->__invoke($this->enteredEvent(new CompanyRevision()));

        self::assertSame(
            [],
            $this->dispatched,
        );
    }

    /**
     * @return EnteredEvent<object>
     */
    private function enteredEvent(object $subject): EnteredEvent
    {
        return new EnteredEvent(
            $subject,
            new Marking([]),
        );
    }
}
