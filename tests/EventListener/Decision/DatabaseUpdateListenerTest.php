<?php

declare(strict_types=1);

namespace App\Tests\EventListener\Decision;

use App\Entity\Database\Address;
use App\Entity\Database\Decision;
use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Database\MailingList;
use App\Entity\Database\MailingListMember;
use App\Entity\Database\Meeting;
use App\Entity\Database\Member;
use App\Entity\Database\SubDecision\Abrogation as LedgerSubDecision;
use App\Entity\Decision\SubDecision\Abrogation as ProjectionSubDecision;
use App\EventListener\Decision\DatabaseUpdateListener;
use App\Service\Decision\MeetingService;
use App\Service\Decision\MemberService;
use App\Service\Decision\MiscService;
use App\Service\Decision\ProjectionState;
use App\Service\Decision\SubDecisionService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * The projection is derived state, written in the same request as the ledger. The listener stops the recursion that
 * flush would cause.
 */
#[AllowMockObjectsWithoutExpectations]
final class DatabaseUpdateListenerTest extends TestCase
{
    private ProjectionState $state;

    private MeetingService&MockObject $meetingService;

    private MemberService&MockObject $memberService;

    private MiscService&MockObject $miscService;

    private SubDecisionService&MockObject $subDecisionService;

    private EntityManagerInterface&MockObject $emWeb;

    protected function setUp(): void
    {
        $this->state = new ProjectionState();
        $this->meetingService = $this->createMock(MeetingService::class);
        $this->memberService = $this->createMock(MemberService::class);
        $this->miscService = $this->createMock(MiscService::class);
        $this->subDecisionService = $this->createMock(SubDecisionService::class);
        $this->emWeb = $this->createMock(EntityManagerInterface::class);
    }

    public function testAnAddressIsProjected(): void
    {
        $this->memberService->expects(self::once())->method('generateAddress');

        $this->persist($this->entity(Address::class));
    }

    public function testAMemberIsProjected(): void
    {
        $this->memberService->expects(self::once())->method('generateMember');

        $this->persist($this->entity(Member::class));
    }

    public function testAMeetingIsProjected(): void
    {
        $this->meetingService->expects(self::once())->method('generateMeeting');

        $this->persist($this->entity(Meeting::class));
    }

    public function testADecisionIsProjected(): void
    {
        $this->meetingService->expects(self::once())->method('generateDecision');

        $this->persist($this->entity(Decision::class));
    }

    public function testASubDecisionIsProjectedWithWhatItRelatesTo(): void
    {
        $decision = self::createStub(Decision::class);
        $decision->point = 1;
        $decision->number = 1;
        $decision->method('getMeetingType')->willReturn(MeetingTypes::BV);
        $decision->method('getMeetingNumber')->willReturn(1);

        $subDecision = new LedgerSubDecision();
        $subDecision->setDecision($decision);

        $projection = self::createStub(ProjectionSubDecision::class);

        $this->meetingService->method('generateSubDecision')->willReturn($projection);
        $this->subDecisionService->expects(self::once())->method('generateRelated')->with($projection);
        $this->emWeb->expects(self::once())->method('persist')->with($projection);
        $this->meetingService->expects(self::once())->method('refreshDecisionContent')->with($decision);

        $this->persist($subDecision);
    }

    public function testAMailingListIsProjected(): void
    {
        $this->miscService->expects(self::once())->method('generateList');

        $this->persist($this->entity(MailingList::class));
    }

    public function testASubscriptionIsProjected(): void
    {
        $subscription = self::createStub(MailingListMember::class);
        $subscription->toBeDeleted = false;

        $this->miscService->expects(self::once())->method('generateListMembership');
        $this->miscService->expects(self::never())->method('deleteListMembership');

        $this->persist($subscription);
    }

    /**
     * A subscription on its way out is removed from the projection rather than written to it.
     */
    public function testASubscriptionOnItsWayOutIsRemovedFromTheProjection(): void
    {
        $subscription = self::createStub(MailingListMember::class);
        $subscription->toBeDeleted = true;

        $this->miscService->expects(self::once())->method('deleteListMembership');
        $this->miscService->expects(self::never())->method('generateListMembership');

        $this->persist($subscription);
    }

    public function testAnEntityTheProjectionDoesNotKnowIsIgnored(): void
    {
        $this->emWeb->expects(self::never())->method('flush');

        $this->persist(new stdClass());
    }

    public function testNothingIsProjectedWhileTheProjectionIsDisabled(): void
    {
        $this->state->disable();

        $this->memberService->expects(self::never())->method('generateMember');

        $this->persist($this->entity(Member::class));
    }

    /**
     * Generating into the projection flushes it, and that flush reaches this listener again through the entities it
     * just wrote.
     */
    public function testAFlushDuringAProjectionDoesNotFlushAgain(): void
    {
        $this->state->beginProjecting();

        $this->emWeb->expects(self::never())->method('flush');

        $this->persist($this->entity(Member::class));
    }

    public function testAnUpdateIsProjectedLikeAPersist(): void
    {
        $this->memberService->expects(self::once())->method('generateMember');

        $listener = $this->listener();
        $listener->postUpdate(new PostUpdateEventArgs(
            $this->entity(Member::class),
            $this->emWeb,
        ));
    }

    private function persist(object $entity): void
    {
        $this->listener()->postPersist(new PostPersistEventArgs($entity, $this->emWeb));
    }

    private function listener(): DatabaseUpdateListener
    {
        return new DatabaseUpdateListener(
            $this->state,
            $this->meetingService,
            $this->memberService,
            $this->miscService,
            $this->subDecisionService,
            $this->emWeb,
        );
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function entity(string $class): object
    {
        return self::createStub($class);
    }
}
