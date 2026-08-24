<?php

declare(strict_types=1);

namespace App\Twig\Components\Photo;

use App\Attribute\Application\ReadOnlySafe;
use App\Entity\Photo\Album;
use App\Service\Photo\AlbumService;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

use function array_merge;
use function array_slice;
use function array_values;
use function count;

/**
 * The albums shown on the photo landing page and the cross-year search page, grouped into months. On the landing page
 * the year is chosen by the page and search filters within it; on the search page (`crossYear`) the query searches
 * every year's albums by name. Filtering happens without a page reload.
 *
 * Infinite scroll grows `limit` through the loadMore action, as on the career overviews. The window is taken after the
 * albums have been bucketed rather than in the query, because which albums a visitor may see is decided per album by
 * {@see \App\Security\Photo\AlbumVoter} once they are loaded; a LIMIT would count the ones it then hides.
 */
#[AsLiveComponent(
    name: 'Photo:AlbumOverview',
    template: 'components/Photo/AlbumOverview.html.twig',
)]
final class AlbumOverview
{
    use DefaultActionTrait;

    private const int PAGE_SIZE = 24;

    #[LiveProp]
    public ?int $year = null;

    #[LiveProp]
    public bool $crossYear = false;

    // Not URL-synced: on the landing page the year is a query param, and syncing search could drop it on reload.
    #[LiveProp(writable: true)]
    public string $search = '';

    // Not client-writable: it travels in the signed props, so a crafted request cannot ask for the whole archive.
    #[LiveProp]
    public int $limit = self::PAGE_SIZE;

    /** @var array<string, Album[]>|null */
    private ?array $albumsByMonth = null;

    /** @var array<string, Album[]>|null */
    private ?array $window = null;

    public function __construct(
        private readonly AlbumService $albumService,
    ) {
    }

    #[LiveAction]
    #[ReadOnlySafe]
    public function loadMore(): void
    {
        $this->limit += self::PAGE_SIZE;
    }

    /**
     * @return array<string, Album[]>
     */
    public function getAlbumsByMonth(): array
    {
        return $this->window ??= $this->truncate(
            $this->allAlbumsByMonth(),
            $this->limit,
        );
    }

    public function hasMore(): bool
    {
        return $this->totalCount() > $this->limit;
    }

    /**
     * @return array<string, Album[]>
     */
    private function allAlbumsByMonth(): array
    {
        if (null !== $this->albumsByMonth) {
            return $this->albumsByMonth;
        }

        if ($this->crossYear) {
            // Wait for a query before searching: the whole photo archive is too large to list at once.
            return $this->albumsByMonth = '' === $this->search
                ? []
                : $this->albumService->searchViewableAlbums($this->search);
        }

        if (null === $this->year) {
            return $this->albumsByMonth = [];
        }

        return $this->albumsByMonth = $this->albumService->getViewableRootAlbumsByMonth(
            $this->year,
            '' === $this->search ? null : $this->search,
        );
    }

    private function totalCount(): int
    {
        $total = 0;
        foreach ($this->allAlbumsByMonth() as $albums) {
            $total += count($albums);
        }

        return $total;
    }

    /**
     * The first $limit albums, still grouped by month and in the same order. A month is kept only for as long as the
     * window reaches into it, so the last one shown is partial rather than dropped.
     *
     * @param array<string, Album[]> $grouped
     *
     * @return array<string, Album[]>
     */
    private function truncate(
        array $grouped,
        int $limit,
    ): array {
        $window = [];
        $remaining = $limit;

        foreach ($grouped as $month => $albums) {
            if ($remaining <= 0) {
                break;
            }

            $window[$month] = array_slice(
                $albums,
                0,
                $remaining,
            );
            $remaining -= count($window[$month]);
        }

        return $window;
    }

    /**
     * The sub-album and photo counts the album cards need, batched so the grid does not issue a COUNT per card.
     *
     * @return array{subAlbums: array<int, int>, photos: array<int, int>}
     */
    public function getCardCounts(): array
    {
        $grouped = $this->getAlbumsByMonth();
        if ([] === $grouped) {
            return [
                'subAlbums' => [],
                'photos' => [],
            ];
        }

        return $this->albumService->getCardCounts(
            array_merge(...array_values($grouped)),
            true,
        );
    }
}
