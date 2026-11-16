<?php

declare(strict_types=1);

namespace App\Twig\Components\Decision;

use App\Entity\Application\LabelInterface;
use App\Entity\Database\Enums\OrganTypes;
use App\Entity\Decision\Organ;
use App\Repository\Application\LabelRepositoryInterface;
use App\Repository\Decision\OrganLabelRepository;
use App\Repository\Decision\OrganRepository;
use App\Twig\Components\Application\AbstractInfiniteScrollOverview;
use App\Twig\Components\Application\FiltersLabelsTrait;
use Override;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Metadata\UrlMapping;

use function array_slice;
use function count;
use function explode;
use function is_array;
use function mt_getrandmax;
use function random_int;
use function strval;

/**
 * One overview of the bodies of a kind: the active ones, or the ones that have been abrogated. Every overview is the
 * same list with different arguments, so all of them are this component with the type and the scope passed in.
 *
 * A search narrows by abbreviation and name, and the labels narrow to the bodies whose live page carries one. Labels
 * are the committees' own, so only the committee overviews offer the label filter. Both mirror themselves into the
 * query string, so a filtered list is a shareable link. Infinite scroll grows `limit` through the loadMore action, as
 * on the career overviews.
 *
 * The fraternities are shown in a random order, so no fraternity is structurally favoured by where its name falls in
 * the alphabet. The order is drawn once, at mount, and kept as a seed for as long as the visitor stays on the page;
 * without that, every keystroke would reshuffle the list under the reader.
 */
#[AsLiveComponent(
    name: 'Decision:BodyOverview',
    template: 'components/Decision/BodyOverview.html.twig',
)]
final class BodyOverview extends AbstractInfiniteScrollOverview
{
    use FiltersLabelsTrait;

    #[LiveProp]
    public string $type = '';

    #[LiveProp]
    public bool $abrogated = false;

    /**
     * Whether a link to a body includes the year it was founded. An abbreviation is reused, so the overviews that list
     * what is gone say which one they mean; the ones listing what is around never have to.
     */
    #[LiveProp]
    public bool $dated = false;

    #[LiveProp]
    public bool $shuffle = false;

    /** What the overview says when there is nothing in it at all, which differs per kind of body. */
    #[LiveProp]
    public string $emptyMessage = '';

    /**
     * Scoped per overview, so the two lists on a page of fraternities do not share one box in the address bar.
     */
    #[LiveProp(
        writable: true,
        url: true,
        modifier: 'scopeSearchToThisList',
    )]
    public string $search = '';

    /** @var int[] */
    #[LiveProp(
        writable: true,
        url: new UrlMapping(as: 'labels'),
    )]
    public array $labelFilters = [];

    // Neither is client-writable: they are passed in the signed props, so a crafted request can neither reshuffle the
    // list mid-page nor ask for an arbitrarily large page.
    #[LiveProp]
    public int $seed = 0;

    /** @var int[]|null */
    private ?array $ids = null;

    /** @var Organ[]|null */
    private ?array $organs = null;

    public function __construct(
        private readonly OrganRepository $organRepository,
        private readonly OrganLabelRepository $organLabelRepository,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * Pre-select the label filter from the query string on a full page load. ux-live-component v3 hydrates scalar
     * url-mapped props server-side but leaves an array prop ({@see $labelFilters}) empty, so a shared or reloaded
     * `?labels=` URL would land on an unfiltered list.
     */
    public function mount(): void
    {
        $this->seed = random_int(
            0,
            mt_getrandmax(),
        );

        $request = $this->requestStack->getCurrentRequest();
        if (
            null === $request
            || !$request->query->has('labels')
        ) {
            return;
        }

        $raw = $request->query->all()['labels'];
        $values = is_array($raw)
            ? $raw
            : explode(
                ',',
                strval($raw),
            );

        $this->labelFilters = $this->offeredLabelIds(self::positiveIntIds($values));
    }

    /**
     * What the search of this overview is called in the address bar. A page shows what is installed above what has
     * been abrogated, and both are this component, so the two would otherwise write to and read from the same
     * parameter and filter each other.
     */
    public function scopeSearchToThisList(LiveProp $prop): LiveProp
    {
        if (!$this->abrogated) {
            return $prop;
        }

        return $prop->withUrl(new UrlMapping(as: 'abrogated-search'));
    }

    /**
     * @return Organ[]
     */
    public function getOrgans(): array
    {
        return $this->organs ??= $this->organRepository->findOverviewByIds(array_slice(
            $this->orderedIds(),
            0,
            $this->limit,
        ));
    }

    public function getTotalCount(): int
    {
        return count($this->matchingIds());
    }

    #[Override]
    public function hasMore(): bool
    {
        return $this->getTotalCount() > count($this->getOrgans());
    }

    /**
     * @return int[]
     */
    private function orderedIds(): array
    {
        if (!$this->shuffle) {
            return $this->matchingIds();
        }

        // A seeded Mt19937 rather than the global generator, so drawing this order leaves the rest of the request's
        // randomness alone.
        return new Randomizer(new Mt19937($this->seed))->shuffleArray($this->matchingIds());
    }

    protected function labelRepository(): LabelRepositoryInterface
    {
        return $this->organLabelRepository;
    }

    /**
     * Labels group the committees by what they offer; an overview of any other kind of body offers none, so its
     * filter panel renders no label select and no pre-selected filter from the query string survives.
     *
     * @return LabelInterface[]
     */
    protected function offeredLabels(): array
    {
        if (!OrganTypes::from($this->type)->allowsLabels()) {
            return [];
        }

        return $this->labelRepository()->findActiveWithName();
    }

    /**
     * @return int[]
     */
    private function selectedLabelIds(): array
    {
        return self::positiveIntIds($this->labelFilters);
    }

    /**
     * @return int[]
     */
    private function matchingIds(): array
    {
        $type = OrganTypes::from($this->type);

        return $this->ids ??= $this->organRepository->findOverviewIds(
            $type,
            $this->abrogated,
            $this->search,
            // Labels group the committees alone, so on any other overview a filter the reader cannot see and clear
            // would silently narrow the list.
            $type->allowsLabels() ? $this->selectedLabelIds() : [],
        );
    }
}
