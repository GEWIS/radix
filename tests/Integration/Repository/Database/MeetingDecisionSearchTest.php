<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository\Database;

use App\Entity\Database\Decision;
use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Database\Meeting;
use App\Repository\Database\MeetingRepository;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\LedgerBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Override;

use function sprintf;

/**
 * The lookup a decision is cited by while typing its reference: which decisions a prompt may offer, which it must never
 * offer (the annulled, the ones that do the annulling, the virtual one that already repeats something else), the cap
 * that keeps a broad prompt cheap, and the "before this point in the register" limit an annulment is entered under.
 */
final class MeetingDecisionSearchTest extends DatabaseTestCase
{
    private MeetingRepository $repository;

    private LedgerBuilder $build;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $entityManager = self::getContainer()->get('doctrine')->getManager('database');
        self::assertInstanceOf(
            EntityManagerInterface::class,
            $entityManager,
        );

        $this->entityManager = $entityManager;
        $this->repository = self::getContainer()->get(MeetingRepository::class);
        $this->build = new LedgerBuilder($this->entityManager);
    }

    public function testADecisionIsFoundByItsReference(): void
    {
        $meeting = $this->build->meeting(
            MeetingTypes::BV,
            '2033-01-01',
        );
        $first = $this->build->decideFreely(
            $meeting,
            'Een',
        )->decision;
        $this->build->decideFreely(
            $meeting,
            'Twee',
        );

        $found = $this->repository->searchDecision($this->reference($meeting, $first));

        self::assertCount(
            1,
            $found,
        );
        self::assertSame(
            $first,
            $found[0],
        );
        // The sub-decisions are what the decision's text is made of, and they come with it.
        self::assertNotEmpty($found[0]->getSubdecisions());
    }

    public function testTheReferenceIsMatchedWithoutItsTypeOrOnAPartOfIt(): void
    {
        $meeting = $this->build->meeting(
            MeetingTypes::BV,
            '2033-01-05',
        );
        $this->build->decideFreely(
            $meeting,
            'Een',
        );
        $second = $this->build->decideFreely(
            $meeting,
            'Twee',
        )->decision;

        $found = $this->repository->searchDecision(sprintf(
            '%d.2.',
            $meeting->getNumber(),
        ));

        self::assertCount(
            1,
            $found,
        );
        self::assertSame(
            $second,
            $found[0],
        );
    }

    public function testAPromptThatMatchesNothingOffersNothing(): void
    {
        self::assertSame(
            [],
            $this->repository->searchDecision('besluit dat niet bestaat'),
        );
    }

    public function testAnAnnulledDecisionIsWithheldUnlessItIsAskedFor(): void
    {
        $meeting = $this->build->meeting(
            MeetingTypes::BV,
            '2033-02-01',
        );
        $decision = $this->build->decideFreely(
            $meeting,
            'Te niet doen',
        )->decision;

        $annulmentMeeting = $this->build->meeting(
            MeetingTypes::ALV,
            '2033-03-01',
        );
        $this->build->annul(
            $annulmentMeeting,
            $decision,
        );

        self::assertSame(
            [],
            $this->repository->searchDecision($this->reference($meeting, $decision)),
            'A decision that never happened cannot be pointed at.',
        );

        self::assertContains(
            $decision,
            $this->repository->searchDecision(
                $this->reference(
                    $meeting,
                    $decision,
                ),
                includeAnnulled: true,
            ),
        );
    }

    public function testTheDecisionThatAnnullsIsNeverOffered(): void
    {
        $meeting = $this->build->meeting(
            MeetingTypes::BV,
            '2033-04-01',
        );
        $decision = $this->build->decideFreely(
            $meeting,
            'Te niet doen',
        )->decision;

        $annulmentMeeting = $this->build->meeting(
            MeetingTypes::ALV,
            '2033-05-01',
        );
        $annulment = $this->build->annul(
            $annulmentMeeting,
            $decision,
        );

        // Annulling an annulment has no meaning, so the decision that does the annulling is not an answer either.
        self::assertSame(
            [],
            $this->repository->searchDecision(
                $this->reference(
                    $annulmentMeeting,
                    $annulment->decision,
                ),
                includeAnnulled: true,
            ),
        );
    }

    public function testOnlyWhatCameBeforeIsOfferedWhenAPointIsGiven(): void
    {
        $earlier = $this->build->meeting(
            MeetingTypes::BV,
            '2033-06-01',
        );
        $later = $this->build->meeting(
            MeetingTypes::ALV,
            '2033-07-01',
        );
        $first = $this->build->decideFreely(
            $earlier,
            'Een',
        )->decision;
        $second = $this->build->decideFreely(
            $earlier,
            'Twee',
        )->decision;
        $future = $this->build->decideFreely(
            $later,
            'Later',
        )->decision;

        // Before the start of the first meeting nothing has happened yet.
        self::assertSame(
            [],
            $this->repository->searchDecision(
                $this->reference(
                    $earlier,
                    $first,
                ),
                before: $earlier,
                beforePoint: 1,
                beforeNumber: 1,
            ),
        );

        // Before the second decision of that meeting, the first one is on the record.
        self::assertContains(
            $first,
            $this->repository->searchDecision(
                $this->reference(
                    $earlier,
                    $first,
                ),
                before: $earlier,
                beforePoint: 2,
                beforeNumber: 1,
            ),
        );

        // Within the same point, the decision number says which came first.
        self::assertContains(
            $first,
            $this->repository->searchDecision(
                $this->reference(
                    $earlier,
                    $first,
                ),
                before: $earlier,
                beforePoint: 1,
                beforeNumber: 2,
            ),
        );

        // A decision taken later cannot be pointed at from an earlier meeting.
        self::assertSame(
            [],
            $this->repository->searchDecision(
                $this->reference(
                    $later,
                    $future,
                ),
                before: $earlier,
                beforePoint: 99,
                beforeNumber: 99,
            ),
        );

        // A later meeting may point at everything taken in the meeting before it.
        self::assertContains(
            $second,
            $this->repository->searchDecision(
                $this->reference(
                    $earlier,
                    $second,
                ),
                before: $later,
                beforePoint: 1,
                beforeNumber: 1,
            ),
        );
    }

    public function testOnlyAnUnlinkedVirtualDecisionIsOfferedAsACounterpart(): void
    {
        $board = $this->build->meeting(
            MeetingTypes::BV,
            '2033-08-01',
        );
        $original = $this->build->decideFreely(
            $board,
            'Origineel',
        )->decision;

        $virtual = $this->build->meeting(
            MeetingTypes::VIRT,
            '2033-08-01',
        );
        $repeat = $this->build->decision($virtual);
        $this->entityManager->flush();

        self::assertContains(
            $repeat,
            $this->repository->searchDecision(
                $this->reference(
                    $virtual,
                    $repeat,
                ),
                onlyUnlinkedVirtual: true,
            ),
        );

        // A decision taken in a real meeting is not a virtual counterpart, however it is asked for.
        self::assertSame(
            [],
            $this->repository->searchDecision(
                $this->reference(
                    $board,
                    $original,
                ),
                onlyUnlinkedVirtual: true,
            ),
        );

        $repeat->counterpart = $original;
        $this->entityManager->flush();

        self::assertSame(
            [],
            $this->repository->searchDecision(
                $this->reference(
                    $virtual,
                    $repeat,
                ),
                onlyUnlinkedVirtual: true,
            ),
            'A virtual decision that already repeats something is not offered again.',
        );
    }

    public function testAPromptThatMatchesEverythingIsCapped(): void
    {
        $meeting = $this->build->meeting(
            MeetingTypes::BV,
            '2033-09-01',
        );

        for ($i = 0; $i < 30; ++$i) {
            $this->build->decideFreely(
                $meeting,
                'Besluit ' . $i,
            );
        }

        $found = $this->repository->searchDecision(sprintf(
            'bv %d.',
            $meeting->getNumber(),
        ));

        self::assertCount(
            25,
            $found,
            'A broad prompt offers at most 25 decisions.',
        );

        // What it does offer is in register order, oldest point first.
        $previous = 0;
        foreach ($found as $decision) {
            self::assertGreaterThanOrEqual(
                $previous,
                $decision->point,
            );
            $previous = $decision->point;
        }

        self::assertCount(
            25,
            $found,
        );
    }

    private function reference(
        Meeting $meeting,
        Decision $decision,
    ): string {
        return sprintf(
            '%s %d.%d.%d',
            $meeting->type->value,
            $meeting->getNumber(),
            $decision->point,
            $decision->number,
        );
    }
}
