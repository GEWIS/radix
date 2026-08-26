<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Database;

use App\Entity\Database\Enums\MeetingTypes;
use App\Exception\Database\CounterpartNotPossible;
use App\Service\Database\Meeting;
use App\Tests\Support\LedgerBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function sprintf;

/**
 * Saying which virtual decision repeats a decision. This is done from the decision being repeated rather than while
 * either is entered, so neither has to be on the record before the other, which is what these check.
 *
 * Every write is rolled back by dama/doctrine-test-bundle, so the seed these decisions are added to survives the run.
 */
#[CoversClass(Meeting::class)]
class MeetingCounterpartTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private Meeting $meetingService;
    private LedgerBuilder $build;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->meetingService = self::getContainer()->get(Meeting::class);
        $this->build = new LedgerBuilder($this->entityManager);
    }

    /**
     * The virtual decision is recorded first here, which is the order a field on the decision form could not have
     * handled.
     */
    public function testLinksAVirtualDecisionRecordedBeforeTheDecisionItRepeats(): void
    {
        $virtual = $this->build->meeting(MeetingTypes::VIRT);
        $repeat = $this->build->decision($virtual);
        $this->entityManager->flush();

        $board = $this->build->meeting(MeetingTypes::BV);
        $original = $this->build->decision($board);
        $this->entityManager->flush();

        self::assertTrue($this->meetingService->linkVirtualCounterpart(
            $board->getType(),
            $board->getNumber(),
            $original->getPoint(),
            $original->getNumber(),
            $virtual->getType(),
            $virtual->getNumber(),
            $repeat->getPoint(),
            $repeat->getNumber(),
        ));

        self::assertSame(
            $original,
            $repeat->getCounterpart(),
        );
    }

    /**
     * The reference sits on the virtual decision precisely so that one decision can be said again more than once.
     */
    public function testADecisionIsRepeatedByMoreThanOneVirtualDecision(): void
    {
        $board = $this->build->meeting(MeetingTypes::BV);
        $original = $this->build->decision($board);

        $virtual = $this->build->meeting(MeetingTypes::VIRT);
        $first = $this->build->decision($virtual);
        $second = $this->build->decision($virtual);
        $this->entityManager->flush();

        foreach ([$first, $second] as $repeat) {
            $this->meetingService->linkVirtualCounterpart(
                $board->getType(),
                $board->getNumber(),
                $original->getPoint(),
                $original->getNumber(),
                $virtual->getType(),
                $virtual->getNumber(),
                $repeat->getPoint(),
                $repeat->getNumber(),
            );
        }

        $this->entityManager->refresh($original);

        self::assertCount(
            2,
            $original->getVirtualCounterparts(),
        );
    }

    public function testUnlinksADecisionAgain(): void
    {
        $virtual = $this->build->meeting(MeetingTypes::VIRT);
        $repeat = $this->build->decision($virtual);
        $board = $this->build->meeting(MeetingTypes::BV);
        $original = $this->build->decision($board);
        $this->entityManager->flush();

        $repeat->setCounterpart($original);
        $this->entityManager->flush();

        self::assertTrue($this->meetingService->unlinkVirtualCounterpart(
            $virtual->getType(),
            $virtual->getNumber(),
            $repeat->getPoint(),
            $repeat->getNumber(),
        ));

        self::assertNull($repeat->getCounterpart());
    }

    /**
     * A virtual decision that is already somebody's counterpart is spoken for, so it is not offered again.
     */
    public function testTheLookupLeavesOutAVirtualDecisionThatIsAlreadyACounterpart(): void
    {
        $virtual = $this->build->meeting(MeetingTypes::VIRT);
        $taken = $this->build->decision($virtual);
        $free = $this->build->decision($virtual);
        $board = $this->build->meeting(MeetingTypes::BV);
        $original = $this->build->decision($board);
        $this->entityManager->flush();

        $reference = sprintf(
            '%s %d.%d.',
            $virtual->getType()->value,
            $virtual->getNumber(),
            $taken->getPoint(),
        );

        self::assertCount(
            1,
            $this->meetingService->searchDecisions(
                $reference,
                onlyUnlinkedVirtual: true,
            ),
        );

        $taken->setCounterpart($original);
        $this->entityManager->flush();

        self::assertSame(
            [],
            $this->meetingService->searchDecisions(
                $reference,
                onlyUnlinkedVirtual: true,
            ),
        );

        // The one that is still free is offered as it was, so the rule leaves out no more than it should.
        self::assertNotEmpty($this->meetingService->searchDecisions(
            sprintf(
                '%s %d.%d.',
                $virtual->getType()->value,
                $virtual->getNumber(),
                $free->getPoint(),
            ),
            onlyUnlinkedVirtual: true,
        ));
    }

    /**
     * A decision of a virtual meeting is not one that is repeated: a chain of them says nothing.
     */
    public function testRefusesToRepeatAVirtualDecision(): void
    {
        $virtual = $this->build->meeting(MeetingTypes::VIRT);
        $decision = $this->build->decision($virtual);
        $other = $this->build->decision($virtual);
        $this->entityManager->flush();

        $this->expectException(CounterpartNotPossible::class);

        $this->meetingService->linkVirtualCounterpart(
            $virtual->getType(),
            $virtual->getNumber(),
            $decision->getPoint(),
            $decision->getNumber(),
            $virtual->getType(),
            $virtual->getNumber(),
            $other->getPoint(),
            $other->getNumber(),
        );
    }

    /**
     * And only a virtual decision repeats one.
     */
    public function testRefusesToBeRepeatedByADecisionOfARealMeeting(): void
    {
        $board = $this->build->meeting(MeetingTypes::BV);
        $decision = $this->build->decision($board);
        $other = $this->build->decision($board);
        $this->entityManager->flush();

        $this->expectException(CounterpartNotPossible::class);

        $this->meetingService->linkVirtualCounterpart(
            $board->getType(),
            $board->getNumber(),
            $decision->getPoint(),
            $decision->getNumber(),
            $board->getType(),
            $board->getNumber(),
            $other->getPoint(),
            $other->getNumber(),
        );
    }
}
