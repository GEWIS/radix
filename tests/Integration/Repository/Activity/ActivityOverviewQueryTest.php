<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository\Activity;

use App\Entity\Activity\Activity;
use App\Entity\Decision\AssociationYear;
use App\Entity\Decision\Organ;
use App\Repository\Activity\ActivityRepository;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Doctrine\ORM\Tools\Pagination\Paginator;

use function array_filter;
use function array_map;
use function array_merge;
use function array_unique;
use function array_values;
use function iterator_to_array;

/**
 * The overview query has three time modes: the default upcoming feed (past = false), a single association-year window,
 * and the cross-year search (past = null) that drops the now-window entirely. These pin the cross-year mode against the
 * seed, and pin the year list that feeds the switcher (which now includes upcoming years).
 */
final class ActivityOverviewQueryTest extends DatabaseTestCase
{
    public function testCrossYearModeReturnsUpcomingAndPastTogetherNewestFirst(): void
    {
        $all = $this->overview(null);
        $allIds = $this->ids($all);
        $upcomingIds = $this->ids($this->overview(false));
        $pastIds = $this->ids($this->overview(true));

        // The seed always has upcoming approved activities; past may be empty, but the union must hold exactly.
        self::assertNotEmpty($upcomingIds);
        self::assertEqualsCanonicalizing(
            array_unique(array_merge(
                $upcomingIds,
                $pastIds,
            )),
            $allIds,
        );

        // Newest first: begin times are non-increasing.
        $activities = iterator_to_array(
            $all->getIterator(),
            false,
        );
        $previous = null;
        foreach ($activities as $activity) {
            $begin = $activity->getBeginTime();
            if (null !== $previous) {
                self::assertGreaterThanOrEqual(
                    $begin->getTimestamp(),
                    $previous->getTimestamp(),
                    'The cross-year search must list activities newest first.',
                );
            }

            $previous = $begin;
        }
    }

    public function testYearArchiveExcludesThatYearsUpcomingActivities(): void
    {
        // A known upcoming activity from the seed: its own association-year archive must not list it, because an
        // archive is past only, even for the current year.
        $upcoming = iterator_to_array(
            $this->overview(false)->getIterator(),
            false,
        );
        self::assertNotEmpty($upcoming);

        $activity = $upcoming[0];
        $window = AssociationYear::fromYear(AssociationYear::fromDate($activity->getBeginTime())->getYear());

        $archiveIds = $this->ids($this->repository()->findForOverview(
            true,
            null,
            '',
            'en',
            null,
            [],
            null,
            false,
            $window->getStartDate(),
            $window->getEndDate(),
            200,
            0,
        ));

        self::assertNotContains(
            (int) $activity->id,
            $archiveIds,
        );
    }

    /**
     * The organising-party filter offers exactly the bodies that organised something in the window the page shows, so
     * an abrogated body without an upcoming activity is not offered on the upcoming overview.
     */
    public function testTheBodyFilterOffersOnlyBodiesWithAnActivityInTheWindow(): void
    {
        $repository = $this->repository();

        $upcoming = $this->bodyIds($this->overview(false));
        self::assertNotEmpty($upcoming);
        self::assertEqualsCanonicalizing(
            $upcoming,
            $this->bodyIdsOf($repository->findOrganisingOrgans(
                false,
                null,
                null,
            )),
        );
        self::assertEqualsCanonicalizing(
            $this->bodyIds($this->overview(true)),
            $this->bodyIdsOf($repository->findOrganisingOrgans(
                true,
                null,
                null,
            )),
        );
        self::assertEqualsCanonicalizing(
            $this->bodyIds($this->overview(null)),
            $this->bodyIdsOf($repository->findOrganisingOrgans(
                null,
                null,
                null,
            )),
        );

        self::assertSame(
            [],
            $repository->findOrganisingOrgans(
                null,
                new DateTimeImmutable('1990-09-01'),
                new DateTimeImmutable('1991-08-31 23:59:59'),
            ),
        );
    }

    /**
     * @return Paginator<Activity>
     */
    private function overview(?bool $past): Paginator
    {
        return $this->repository()->findForOverview(
            $past,
            null,
            '',
            'en',
            null,
            [],
            null,
            false,
            null,
            null,
            200,
            0,
        );
    }

    /**
     * @param Paginator<Activity> $paginator
     *
     * @return int[]
     */
    private function ids(Paginator $paginator): array
    {
        return array_map(
            static fn (Activity $activity): int => (int) $activity->id,
            iterator_to_array(
                $paginator->getIterator(),
                false,
            ),
        );
    }

    /**
     * @param Paginator<Activity> $paginator
     *
     * @return int[]
     */
    private function bodyIds(Paginator $paginator): array
    {
        return array_values(array_unique(array_filter(
            array_map(
                static fn (Activity $activity): ?int => $activity->getLiveRevision()?->organ?->id,
                iterator_to_array(
                    $paginator->getIterator(),
                    false,
                ),
            ),
            static fn (?int $id): bool => null !== $id,
        )));
    }

    /**
     * @param Organ[] $organs
     *
     * @return int[]
     */
    private function bodyIdsOf(array $organs): array
    {
        return array_map(
            static fn (Organ $organ): int => (int) $organ->id,
            $organs,
        );
    }

    private function repository(): ActivityRepository
    {
        return $this->entityManager->getRepository(Activity::class);
    }
}
