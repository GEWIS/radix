<?php

declare(strict_types=1);

namespace App\Tests\EventListener\Decision;

use App\Entity\Database\Address;
use App\Entity\Database\Decision;
use App\Entity\Database\MailingList;
use App\Entity\Database\MailingListMember;
use App\Entity\Database\Meeting;
use App\Entity\Database\Member;
use App\EventListener\Decision\DatabaseDeletionListener;
use App\Service\Decision\MeetingService;
use App\Service\Decision\MemberService;
use App\Service\Decision\MiscService;
use App\Service\Decision\ProjectionState;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

/**
 * A ledger record is removed from the projection before deletion, while its relations are still readable.
 */
#[AllowMockObjectsWithoutExpectations]
final class DatabaseDeletionListenerTest extends TestCase
{
    private ProjectionState $state;

    private MeetingService&MockObject $meetingService;

    private MemberService&MockObject $memberService;

    private MiscService&MockObject $miscService;

    private EntityManagerInterface&MockObject $emWeb;

    private bool $expectsFlush = true;

    protected function setUp(): void
    {
        $this->state = new ProjectionState();
        $this->meetingService = $this->createMock(MeetingService::class);
        $this->memberService = $this->createMock(MemberService::class);
        $this->miscService = $this->createMock(MiscService::class);
        $this->emWeb = $this->createMock(EntityManagerInterface::class);
    }

    public function testAnAddressIsRemovedFromTheProjection(): void
    {
        $this->memberService->expects(self::once())->method('deleteAddress');

        $this->remove($this->entity(Address::class));
    }

    public function testAMemberIsRemovedFromTheProjection(): void
    {
        $this->memberService->expects(self::once())->method('deleteMember');

        $this->remove($this->entity(Member::class));
    }

    /**
     * Nothing removes a meeting, and the effect on its projected decisions is undefined, so the removal is refused.
     */
    public function testRemovingAMeetingIsRefused(): void
    {
        $this->expectsFlush = false;
        $this->expectException(RuntimeException::class);

        $this->remove($this->entity(Meeting::class));
    }

    public function testADecisionIsRemovedFromTheProjection(): void
    {
        $this->meetingService->expects(self::once())->method('deleteDecision');

        $this->remove($this->entity(Decision::class));
    }

    public function testAMailingListIsRemovedFromTheProjection(): void
    {
        $this->miscService->expects(self::once())->method('deleteList');

        $this->remove($this->entity(MailingList::class));
    }

    public function testASubscriptionIsRemovedFromTheProjection(): void
    {
        $this->miscService->expects(self::once())->method('deleteListMembership');

        $this->remove($this->entity(MailingListMember::class));
    }

    public function testAnEntityTheProjectionDoesNotKnowIsIgnored(): void
    {
        $this->expectsFlush = false;

        $this->remove(new stdClass());
    }

    public function testNothingIsRemovedWhileTheProjectionIsDisabled(): void
    {
        $this->expectsFlush = false;
        $this->state->disable();

        $this->memberService->expects(self::never())->method('deleteMember');

        $this->remove($this->entity(Member::class));
    }

    private function remove(object $entity): void
    {
        $this->listener()->preRemove(new PreRemoveEventArgs($entity, $this->emWeb));
    }

    private function listener(): DatabaseDeletionListener
    {
        $this->emWeb->expects($this->expectsFlush ? self::once() : self::never())->method('flush');

        return new DatabaseDeletionListener(
            $this->state,
            $this->meetingService,
            $this->memberService,
            $this->miscService,
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
