<?php

declare(strict_types=1);

namespace App\Tests\EventListener\Activity;

use App\Entity\Activity\ActivityProposal;
use App\Entity\Application\Enums\AlertTypes;
use App\Entity\Application\Enums\NotificationType;
use App\Entity\Application\Notification;
use App\Entity\Decision\Member;
use App\Entity\User\User;
use App\EventListener\Activity\NotifyOnProposalDecisionListener;
use App\Repository\User\UserRepository;
use App\Service\Application\NotificationPublisher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;
use stdClass;
use Symfony\Component\Workflow\Event\Event;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Transition;

/**
 * A body that requested a day must be told the board's decision, or it cannot plan around it.
 */
final class NotifyOnProposalDecisionListenerTest extends TestCase
{
    private ?Notification $published = null;

    public function testAScheduledProposalNotifiesTheMemberWhoSubmittedIt(): void
    {
        $this->listener()->onScheduled($this->event($this->proposal(), 'schedule'));

        self::assertNotNull($this->published);
        self::assertSame(
            NotificationType::ActivityProposalScheduled,
            $this->published->type,
        );
        self::assertSame(
            AlertTypes::Success,
            $this->published->level,
        );
        self::assertSame(
            [
                'proposal' => '42',
                'proposalName' => 'A pub quiz',
            ],
            $this->published->context,
        );
        self::assertNotNull($this->published->recipientUser);
    }

    /**
     * Withdrawing a clearance also enters `scheduled`, and that is not a new decision: the body already knows.
     */
    public function testWithdrawingAClearanceDoesNotNotify(): void
    {
        $this->listener()->onScheduled($this->event($this->proposal(), 'withdraw'));

        self::assertNull($this->published);
    }

    public function testADeclinedProposalNotifiesTheMemberWhoSubmittedIt(): void
    {
        $this->listener()->onDeclined($this->event($this->proposal(), 'decline'));

        self::assertNotNull($this->published);
        self::assertSame(
            NotificationType::ActivityProposalDeclined,
            $this->published->type,
        );
        self::assertSame(
            AlertTypes::Info,
            $this->published->level,
        );
    }

    public function testASubjectThatIsNotAProposalIsIgnored(): void
    {
        $this->listener()->onDeclined($this->event(new stdClass(), 'decline'));

        self::assertNull($this->published);
    }

    public function testAProposalThatWasNeverStoredIsIgnored(): void
    {
        $this->listener()->onDeclined($this->event($this->proposal(withId: false), 'decline'));

        self::assertNull($this->published);
    }

    public function testAProposalWithNoCreatorIsIgnored(): void
    {
        $this->listener()->onDeclined($this->event($this->proposal(withCreator: false), 'decline'));

        self::assertNull($this->published);
    }

    public function testAProposalWhoseCreatorHasNoAccountIsIgnored(): void
    {
        $this->listener(userExists: false)->onDeclined($this->event($this->proposal(), 'decline'));

        self::assertNull($this->published);
    }

    private function listener(bool $userExists = true): NotifyOnProposalDecisionListener
    {
        $users = self::createStub(UserRepository::class);
        $users->method('find')->willReturn(
            $userExists ? self::createStub(User::class) : null,
        );

        $entityManager = self::createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(
            function (object $entity): void {
                if (!($entity instanceof Notification)) {
                    return;
                }

                $this->published = $entity;
            },
        );

        return new NotifyOnProposalDecisionListener(
            $users,
            new NotificationPublisher(
                $entityManager,
                new NullLogger(),
                [],
            ),
        );
    }

    private function proposal(
        bool $withId = true,
        bool $withCreator = true,
    ): ActivityProposal {
        $proposal = new ActivityProposal();
        $proposal->name = 'A pub quiz';

        if ($withId) {
            new ReflectionProperty(
                ActivityProposal::class,
                'id',
            )->setValue(
                $proposal,
                42,
            );
        }

        if ($withCreator) {
            $member = self::createStub(Member::class);
            $member->lidnr = 8000;
            $proposal->setCreatedBy($member);
        }

        return $proposal;
    }

    /**
     * @return Event<object>
     */
    private function event(
        object $subject,
        string $transition,
    ): Event {
        return new Event(
            $subject,
            new Marking(),
            new Transition(
                $transition,
                'from',
                'to',
            ),
        );
    }
}
