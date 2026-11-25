<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository\Frontpage;

use App\Entity\Decision\Member;
use App\Entity\Frontpage\Poll;
use App\Repository\Frontpage\PollRepository;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Override;

use function array_intersect;
use function array_map;
use function array_reverse;
use function array_values;
use function count;
use function iterator_to_array;
use function rsort;

/**
 * The archive, the "other polls" strip and the anonymisation run: which polls are listed, in what order, narrowed by
 * what was typed and by whether this reader answered, and which votes have been waiting a month for their tally.
 */
final class PollArchiveTest extends DatabaseTestCase
{
    private PollRepository $repository;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = self::getContainer()->get(PollRepository::class);
    }

    public function testTheArchiveHoldsOnlyWhatWentLive(): void
    {
        $polls = $this->page($this->repository->getArchivePaginator(1, 100));

        self::assertNotEmpty($polls);
        foreach ($polls as $poll) {
            self::assertNotNull(
                $poll->getLiveRevision(),
                'A question the board has not agreed to is not in the archive.',
            );
        }
    }

    public function testTheArchiveClosesNewestFirstUnlessAskedOtherwise(): void
    {
        $newestFirst = $this->expiries($this->repository->getArchivePaginator(1, 100));

        $sorted = $newestFirst;
        rsort($sorted);
        self::assertSame(
            $sorted,
            $newestFirst,
        );

        $oldestFirst = $this->expiries($this->repository->getArchivePaginator(
            1,
            100,
            '',
            null,
            null,
            true,
        ));

        self::assertSame(
            array_reverse($newestFirst),
            $oldestFirst,
        );
    }

    public function testTheSearchReachesTheAnswersAsWellAsTheQuestion(): void
    {
        // A phrase that only appears as one of the answers.
        $byAnswer = $this->page($this->repository->getArchivePaginator(
            1,
            100,
            'Friday afternoon',
        ));

        self::assertCount(
            1,
            $byAnswer,
        );
        self::assertTrue($byAnswer[0]->isActive());

        // And a phrase from the question itself.
        $byQuestion = $this->page($this->repository->getArchivePaginator(
            1,
            100,
            'board photo',
        ));

        self::assertCount(
            1,
            $byQuestion,
        );
        self::assertFalse($byQuestion[0]->isActive());
    }

    public function testTheArchiveSaysWhetherThisReaderAnswered(): void
    {
        $member = $this->member(8014);

        $answered = $this->page($this->repository->getArchivePaginator(
            1,
            100,
            '',
            $member,
            true,
        ));
        $unanswered = $this->page($this->repository->getArchivePaginator(
            1,
            100,
            '',
            $member,
            false,
        ));

        self::assertNotEmpty($answered);
        self::assertNotEmpty($unanswered);

        $answeredIds = $this->ids($answered);
        $unansweredIds = $this->ids($unanswered);

        self::assertSame(
            [],
            array_intersect(
                $answeredIds,
                $unansweredIds,
            ),
            'A poll is either answered or not, never both.',
        );
        self::assertCount(
            count($answeredIds) + count($unansweredIds),
            $this->page($this->repository->getArchivePaginator(1, 100)),
        );
    }

    public function testTheArchivePages(): void
    {
        $paginator = $this->repository->getArchivePaginator(
            1,
            1,
        );

        self::assertCount(
            1,
            $this->page($paginator),
        );
        self::assertGreaterThan(
            1,
            $paginator->count(),
        );
    }

    public function testTheOtherPollsAreTheOnesThatClosedNoLaterAndNotThisOne(): void
    {
        $running = $this->repository->findActivePolls()[0] ?? null;
        self::assertInstanceOf(
            Poll::class,
            $running,
        );

        $earlier = $this->repository->findEarlierThan(
            $running,
            10,
        );

        self::assertNotEmpty($earlier);
        self::assertNotContains(
            $running->id,
            $this->ids($earlier),
        );

        foreach ($earlier as $poll) {
            self::assertNotNull($poll->getLiveRevision());
            self::assertLessThanOrEqual(
                $running->expiryDate,
                $poll->expiryDate,
            );
        }

        self::assertLessThanOrEqual(
            1,
            count($this->repository->findEarlierThan($running, 1)),
        );
    }

    public function testAPollWhoseVotesAreOverdueIsPickedUpOnlyOnce(): void
    {
        $overdue = $this->poll(new DateTimeImmutable('2020-01-01'));
        $fresh = $this->poll(new DateTimeImmutable('today'));
        $this->entityManager->flush();

        $due = $this->ids($this->repository->findDueForVoteAnonymisation());

        self::assertContains(
            $overdue->id,
            $due,
        );
        self::assertNotContains(
            $fresh->id,
            $due,
            'A poll that has only just closed keeps its attributed votes for a month.',
        );

        $overdue->votesAnonymisedAt = new DateTimeImmutable();
        $this->entityManager->flush();

        self::assertNotContains(
            $overdue->id,
            $this->ids($this->repository->findDueForVoteAnonymisation()),
            'A poll that has already been tallied is not tallied again.',
        );
    }

    public function testThePollsAMemberAskedForAreTheOnesTheyAreTheCreatorOf(): void
    {
        $member = $this->member(8005);

        $polls = $this->repository->findPollsCreatedByMember($member);

        self::assertNotEmpty($polls);
        foreach ($polls as $poll) {
            self::assertSame(
                $member->lidnr,
                $poll->getCreator()?->lidnr,
            );
        }

        // Somebody else's question is not on their list.
        $someoneElse = $this->member(8006);
        self::assertNotContains(
            $polls[0]->id,
            $this->ids($this->repository->findPollsCreatedByMember($someoneElse)),
        );
    }

    public function testTheAdminOverviewPagesThroughTheAgreedQuestions(): void
    {
        $paginator = $this->repository->paginateForAdmin(
            1,
            2,
        );
        $page = $this->page($paginator);

        self::assertCount(
            2,
            $page,
        );
        self::assertCount(
            (int) $this->entityManager->createQueryBuilder()
                ->select('COUNT(p.id)')
                ->from(
                    Poll::class,
                    'p',
                )
                ->where('p.liveRevision IS NOT NULL')
                ->getQuery()
                ->getSingleScalarResult(),
            $paginator,
        );

        $previous = null;
        foreach ($page as $poll) {
            if (null !== $previous) {
                self::assertLessThanOrEqual(
                    $previous,
                    $poll->expiryDate,
                );
            }

            $previous = $poll->expiryDate;
        }
    }

    private function poll(DateTimeImmutable $expiryDate): Poll
    {
        $poll = new Poll();
        $poll->expiryDate = $expiryDate;

        $this->entityManager->persist($poll);

        return $poll;
    }

    private function member(int $lidnr): Member
    {
        $member = $this->entityManager->find(
            Member::class,
            $lidnr,
        );
        self::assertInstanceOf(
            Member::class,
            $member,
        );

        return $member;
    }

    /**
     * @param Paginator<Poll> $paginator
     *
     * @return list<Poll>
     */
    private function page(Paginator $paginator): array
    {
        return iterator_to_array(
            $paginator,
            false,
        );
    }

    /**
     * @param Paginator<Poll> $paginator
     *
     * @return list<string>
     */
    private function expiries(Paginator $paginator): array
    {
        $expiries = [];
        foreach ($this->page($paginator) as $poll) {
            $expiries[] = $poll->expiryDate?->format('Y-m-d') ?? '';
        }

        return $expiries;
    }

    /**
     * @param Poll[] $polls
     *
     * @return list<int|null>
     */
    private function ids(array $polls): array
    {
        return array_values(array_map(
            static fn (Poll $poll): ?int => $poll->id,
            $polls,
        ));
    }
}
