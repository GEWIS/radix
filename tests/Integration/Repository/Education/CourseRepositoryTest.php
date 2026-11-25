<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository\Education;

use App\Entity\Education\Course;
use App\Entity\Education\Enums\CourseFilter;
use App\Entity\Education\Enums\CourseSort;
use App\Entity\Education\Exam;
use App\Entity\Education\Summary;
use App\Repository\Education\CourseRepository;
use App\Tests\Integration\DatabaseTestCase;
use Override;

use function array_column;
use function array_intersect;
use function array_keys;
use function array_map;
use function array_pop;
use function array_values;
use function iterator_to_array;
use function rsort;
use function sort;

/**
 * The course archive, against the seeded quad of courses: one with both kinds of material, one with only exams,
 * one with one of each, and one that is listed but empty and links to the course it replaced.
 */
final class CourseRepositoryTest extends DatabaseTestCase
{
    private CourseRepository $repository;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = self::getContainer()->get(CourseRepository::class);
    }

    public function testSearchMatchesTheCodeAndTheName(): void
    {
        self::assertSame(
            ['2IL50'],
            $this->codes($this->repository->search('2IL50')),
        );

        $found = $this->codes($this->repository->search('data structures'));
        sort($found);
        self::assertSame(
            [
                '2IL50',
                '2IPC0',
            ],
            $found,
        );
    }

    public function testSearchIsCaseInsensitiveAndTreatsWildcardsAsCharacters(): void
    {
        self::assertSame(
            ['2WF50'],
            $this->codes($this->repository->search('DISCRETE')),
        );

        // The `%` and `_` in what was typed are escaped, so neither acts as a wildcard and neither matches anything.
        self::assertSame(
            [],
            $this->repository->search('%'),
        );
        self::assertSame(
            [],
            $this->repository->search('_'),
        );
    }

    public function testFindWithDocumentsLoadsThemAlong(): void
    {
        $course = $this->repository->findWithDocuments('2IL50');

        self::assertInstanceOf(
            Course::class,
            $course,
        );
        self::assertCount(
            4,
            $course->getDocuments(),
        );
    }

    public function testFindWithDocumentsForAnUnknownCodeIsNull(): void
    {
        self::assertNull($this->repository->findWithDocuments('9XXX9'));
    }

    public function testTheOverviewCountsSummariesAndExamsPerCourse(): void
    {
        $rows = [];
        foreach ($this->repository->findForOverview() as $row) {
            $rows[$row->code] = $row;
        }

        self::assertSame(
            2,
            $rows['2IL50']->summaryCount,
        );
        self::assertSame(
            2,
            $rows['2IL50']->examCount,
        );
        self::assertSame(
            0,
            $rows['2IPC0']->summaryCount,
        );
        self::assertSame(
            1,
            $rows['2IPC0']->examCount,
        );
        self::assertSame(
            1,
            $rows['2WF50']->summaryCount,
        );
        self::assertSame(
            1,
            $rows['2WF50']->examCount,
        );

        // The date of the most recent document in the course, not of its latest upload.
        self::assertSame(
            '2025-11-10',
            $rows['2IL50']->lastAddedAt?->format('Y-m-d'),
        );
        // A course with no documents has no date, which the row states instead.
        self::assertTrue($rows['2ITX0']->isEmpty());
        self::assertNull($rows['2ITX0']->lastAddedAt);
    }

    public function testTheOverviewSortsByCodeByDefault(): void
    {
        $codes = array_column(
            $this->repository->findForOverview(),
            'code',
        );

        $sorted = $codes;
        sort($sorted);

        self::assertSame(
            $sorted,
            $codes,
        );
    }

    public function testTheOverviewSortsByHowMuchMaterialACourseHas(): void
    {
        $codes = array_column(
            $this->repository->findForOverview(
                filter: CourseFilter::All,
                sort: CourseSort::MostMaterial,
            ),
            'code',
        );

        // Four documents beat one, and the codes settle the ties.
        self::assertSame(
            '2IL50',
            $codes[0],
        );
    }

    public function testTheOverviewSortsByTheLatestDocument(): void
    {
        $rows = $this->repository->findForOverview(
            filter: CourseFilter::All,
            sort: CourseSort::RecentlyUpdated,
        );

        // A course with no documents has no date to sort on, so it sorts last rather than first.
        $codes = array_column(
            $rows,
            'code',
        );
        self::assertSame(
            '2ITX0',
            array_pop($codes),
            'A course with nothing yet comes after the ones that were added to.',
        );

        $dates = [];
        foreach ($rows as $row) {
            if (null === $row->lastAddedAt) {
                continue;
            }

            $dates[$row->code] = $row->lastAddedAt->getTimestamp();
        }

        $ordered = array_values($dates);
        $sorted = $ordered;
        rsort($sorted);
        self::assertSame(
            $sorted,
            $ordered,
            'The most recently added to course comes first.',
        );
        self::assertSame(
            '2IL50',
            array_keys($dates)[0],
        );
    }

    public function testTheOverviewFiltersByWhatACourseContains(): void
    {
        self::assertSame(
            ['2ITX0'],
            array_column(
                $this->repository->findForOverview(filter: CourseFilter::Empty),
                'code',
            ),
        );

        $withSummaries = array_column(
            $this->repository->findForOverview(filter: CourseFilter::WithSummaries),
            'code',
        );
        sort($withSummaries);
        self::assertSame(
            [
                '2IL50',
                '2WF50',
            ],
            $withSummaries,
        );

        $withExams = array_column(
            $this->repository->findForOverview(filter: CourseFilter::WithExams),
            'code',
        );
        sort($withExams);
        self::assertSame(
            [
                '2IL50',
                '2IPC0',
                '2WF50',
            ],
            $withExams,
        );
    }

    public function testTheOverviewNarrowsAndCapsOnWhatWasTyped(): void
    {
        self::assertSame(
            ['2WF50'],
            array_column(
                $this->repository->findForOverview('discrete'),
                'code',
            ),
        );

        // Whitespace around the prompt is not part of it.
        self::assertSame(
            ['2WF50'],
            array_column(
                $this->repository->findForOverview('  discrete  '),
                'code',
            ),
        );

        self::assertCount(
            2,
            $this->repository->findForOverview(
                filter: CourseFilter::All,
                limit: 2,
            ),
        );
    }

    public function testFindWithMostDocumentsSkipsCoursesWithNothing(): void
    {
        $rows = $this->repository->findWithMostDocuments(2);

        self::assertCount(
            2,
            $rows,
        );
        self::assertSame(
            '2IL50',
            $rows[0]->code,
        );
        self::assertSame(
            4,
            $rows[0]->getDocumentCount(),
        );

        foreach ($this->repository->findWithMostDocuments(100) as $row) {
            self::assertFalse(
                $row->isEmpty(),
                'A course with nothing to read is not one of the most-documented.',
            );
        }
    }

    public function testASimilarLinkIsReadFromBothDirections(): void
    {
        $similar = $this->repository->findSimilarCoursesFor(['2ITX0']);

        self::assertArrayHasKey(
            '2ITX0',
            $similar,
        );
        self::assertSame(
            ['2IL50'],
            $this->codes($similar['2ITX0']),
        );

        // A course with no link is absent rather than present and empty.
        self::assertSame(
            [],
            $this->repository->findSimilarCoursesFor(['2IPC0']),
        );

        self::assertSame(
            [],
            $this->repository->findSimilarCoursesFor([]),
        );
    }

    public function testTheAdminListingSearchesAndPagesByCode(): void
    {
        $first = iterator_to_array(
            $this->repository->paginateForAdmin(
                '',
                CourseFilter::All,
                1,
                2,
            ),
            false,
        );
        $second = iterator_to_array(
            $this->repository->paginateForAdmin(
                '',
                CourseFilter::All,
                2,
                2,
            ),
            false,
        );

        self::assertCount(
            2,
            $first,
        );
        self::assertCount(
            2,
            $second,
        );

        $codes = [
            ...$this->codes($first),
            ...$this->codes($second),
        ];
        $sorted = $codes;
        sort($sorted);
        self::assertSame(
            $sorted,
            $codes,
        );
        self::assertSame(
            [],
            array_intersect(
                $this->codes($first),
                $this->codes($second),
            ),
        );
    }

    public function testTheAdminListingNarrowsBySearchAndByFilter(): void
    {
        $found = iterator_to_array(
            $this->repository->paginateForAdmin(
                'ALGORITHMS',
                CourseFilter::All,
                1,
                10,
            ),
            false,
        );

        self::assertSame(
            ['2IPC0'],
            $this->codes($found),
        );

        self::assertSame(
            ['2ITX0'],
            $this->codes(iterator_to_array(
                $this->repository->paginateForAdmin(
                    '',
                    CourseFilter::Empty,
                    1,
                    10,
                ),
                false,
            )),
        );

        self::assertCount(
            2,
            $this->repository->paginateForAdmin(
                '',
                CourseFilter::WithSummaries,
                1,
                10,
            ),
        );

        self::assertCount(
            3,
            $this->repository->paginateForAdmin(
                '',
                CourseFilter::WithExams,
                1,
                10,
            ),
        );
    }

    public function testCountsForIsKeyedByCode(): void
    {
        $counts = $this->repository->countsFor([
            '2IL50',
            '2ITX0',
        ]);

        self::assertSame(
            [
                '2IL50',
                '2ITX0',
            ],
            array_keys($counts),
        );
        self::assertSame(
            4,
            $counts['2IL50']->getDocumentCount(),
        );
        self::assertTrue($counts['2ITX0']->isEmpty());

        self::assertSame(
            [],
            $this->repository->countsFor([]),
        );
    }

    public function testCountAllCountsEveryListedCourse(): void
    {
        $expected = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(c.code)')
            ->from(
                Course::class,
                'c',
            )
            ->getQuery()
            ->getSingleScalarResult();

        self::assertSame(
            $expected,
            $this->repository->countAll(),
        );
        self::assertGreaterThanOrEqual(
            4,
            $expected,
        );
    }

    public function testCountWithDocumentsCountsDistinctCourses(): void
    {
        self::assertSame(
            3,
            $this->repository->countWithDocuments(),
        );
        self::assertSame(
            3,
            $this->repository->countWithDocuments(Exam::class),
        );
        self::assertSame(
            2,
            $this->repository->countWithDocuments(Summary::class),
        );
    }

    /**
     * @param array<array-key, Course> $courses
     *
     * @return list<string>
     */
    private function codes(array $courses): array
    {
        return array_values(array_map(
            static fn (Course $course): string => $course->code,
            $courses,
        ));
    }
}
