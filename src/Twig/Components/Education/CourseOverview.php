<?php

declare(strict_types=1);

namespace App\Twig\Components\Education;

use App\Attribute\Application\ReadOnlySafe;
use App\Entity\Education\Enums\CourseFilter;
use App\Entity\Education\Enums\CourseSort;
use App\Repository\Education\CourseRepository;
use App\ViewModel\Education\CourseOverviewRow;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function count;

/**
 * Search, filter and sort all mirror into the query string, so the address bar stays a shareable, reload-safe link and
 * the hero's stat tiles can link straight into a filtered view.
 *
 * Courses with nothing in them are listed like any other, because the archive is as much a record of what is missing as
 * of what is there. Those are the rows that get similar courses attached, so an empty course points somewhere.
 *
 * Infinite scroll grows `limit` through the loadMore action, as on the career overviews.
 */
#[AsLiveComponent(
    name: 'Education:CourseOverview',
    template: 'components/Education/CourseOverview.html.twig',
)]
final class CourseOverview
{
    use DefaultActionTrait;

    private const int PAGE_SIZE = 25;

    #[LiveProp(
        writable: true,
        url: true,
    )]
    public string $search = '';

    #[LiveProp(
        writable: true,
        url: true,
    )]
    public string $filter = CourseFilter::All->value;

    #[LiveProp(
        writable: true,
        url: true,
    )]
    public string $sort = CourseSort::Code->value;

    // Not client-writable: it travels in the signed props, so a crafted request cannot ask for the whole archive.
    #[LiveProp]
    public int $limit = self::PAGE_SIZE;

    /** @var CourseOverviewRow[]|null */
    private ?array $window = null;

    /** @var CourseOverviewRow[]|null */
    private ?array $rows = null;

    public function __construct(private readonly CourseRepository $courseRepository)
    {
    }

    #[LiveAction]
    #[ReadOnlySafe]
    public function loadMore(): void
    {
        $this->limit += self::PAGE_SIZE;
    }

    /**
     * @return CourseOverviewRow[]
     */
    public function getRows(): array
    {
        return $this->rows ??= $this->withSimilarCourses(array_slice(
            $this->window(),
            0,
            $this->limit,
        ));
    }

    public function getTotal(): int
    {
        return $this->courseRepository->countAll();
    }

    public function hasMore(): bool
    {
        return count($this->window()) > $this->limit;
    }

    /**
     * One row more than the page shows, which is how the overview knows another page exists without counting the
     * matches: the filters are HAVING clauses over an aggregate, so a count would have to group the whole table.
     *
     * @return CourseOverviewRow[]
     */
    private function window(): array
    {
        return $this->window ??= $this->courseRepository->findForOverview(
            $this->search,
            $this->currentFilter(),
            $this->currentSort(),
            $this->limit + 1,
        );
    }

    public function isNarrowed(): bool
    {
        return '' !== $this->search
            || CourseFilter::All !== $this->currentFilter();
    }

    /**
     * @return CourseFilter[]
     */
    public function getFilters(): array
    {
        return CourseFilter::cases();
    }

    /**
     * @return CourseSort[]
     */
    public function getSorts(): array
    {
        return CourseSort::cases();
    }

    /**
     * Only the rows that have nothing of their own, in one query rather than one per row: a course with material
     * already has somewhere to send the reader.
     *
     * @param CourseOverviewRow[] $rows
     *
     * @return CourseOverviewRow[]
     */
    private function withSimilarCourses(array $rows): array
    {
        $emptyCodes = array_values(array_map(
            static fn (CourseOverviewRow $row): string => $row->code,
            array_filter(
                $rows,
                static fn (CourseOverviewRow $row): bool => $row->isEmpty(),
            ),
        ));

        if ([] === $emptyCodes) {
            return $rows;
        }

        $similar = $this->courseRepository->findSimilarCoursesFor($emptyCodes);

        return array_map(
            static fn (CourseOverviewRow $row): CourseOverviewRow => isset($similar[$row->code])
                ? $row->withSimilarCourses($similar[$row->code])
                : $row,
            $rows,
        );
    }

    private function currentFilter(): CourseFilter
    {
        return CourseFilter::tryFrom($this->filter) ?? CourseFilter::All;
    }

    private function currentSort(): CourseSort
    {
        return CourseSort::tryFrom($this->sort) ?? CourseSort::Code;
    }
}
