<?php

declare(strict_types=1);

namespace App\Tests\Integration\LiveComponent\Decision;

use App\Entity\Database\Enums\OrganTypes;
use App\Entity\Decision\Organ;
use App\Repository\Decision\OrganRepository;
use App\Tests\Integration\DatabaseTestCase;
use App\Twig\Components\Decision\BodyOverview;
use Symfony\Component\HttpFoundation\Request;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Metadata\UrlMapping;

use function array_map;
use function array_slice;
use function count;
use function str_contains;
use function strtolower;

/**
 * One overview of the bodies of a kind: the installed ones, the abrogated ones, or a search over either. The labels
 * belong to the committees alone, and the fraternities are drawn in an order that is fixed for the page view.
 */
final class BodyOverviewTest extends DatabaseTestCase
{
    public function testTheCommitteeOverviewOffersItsLabels(): void
    {
        $overview = $this->overview(OrganTypes::Committee);

        self::assertNotEmpty($overview->getLabels());
        self::assertNotEmpty($overview->getOrgans());
        self::assertLessThanOrEqual(
            $overview->getTotalCount(),
            count($overview->getOrgans()),
        );
        self::assertSame(
            $overview->getTotalCount() > count($overview->getOrgans()),
            $overview->hasMore(),
        );
    }

    public function testAnOverviewOfAnotherKindOfBodyOffersNoLabels(): void
    {
        $overview = $this->overview(OrganTypes::Fraternity);
        // A reader can arrive with a label filter in the address, but the panel cannot show it, so it is not applied.
        $overview->labelFilters = [
            1,
            2,
        ];

        self::assertSame(
            [],
            $overview->getLabels(),
        );

        self::assertLessThanOrEqual(
            $overview->getTotalCount(),
            count($overview->getOrgans()),
        );
    }

    public function testTheAbrogatedListIsTheOtherHalf(): void
    {
        $installed = $this->overview(OrganTypes::Committee);
        $abrogated = $this->overview(OrganTypes::Committee);
        $abrogated->abrogated = true;

        $installedIds = array_map(
            static fn (Organ $organ): ?int => $organ->id,
            $installed->getOrgans(),
        );
        $abrogatedIds = array_map(
            static fn (Organ $organ): ?int => $organ->id,
            $abrogated->getOrgans(),
        );

        foreach ($abrogatedIds as $id) {
            self::assertNotContains(
                $id,
                $installedIds,
            );
        }

        foreach ($abrogated->getOrgans() as $organ) {
            self::assertNotNull($organ->abrogationDate);
        }

        foreach ($installed->getOrgans() as $organ) {
            self::assertNull($organ->abrogationDate);
        }
    }

    public function testASearchNarrowsByNameOrAbbreviation(): void
    {
        $all = $this->overview(OrganTypes::Committee);
        $any = $all->getOrgans()[0] ?? null;
        self::assertInstanceOf(
            Organ::class,
            $any,
        );

        $searching = $this->overview(OrganTypes::Committee);
        $searching->search = $any->abbr;

        $found = $searching->getOrgans();

        self::assertNotEmpty($found);
        $needle = strtolower($any->abbr);
        foreach ($found as $organ) {
            self::assertTrue(
                $needle === strtolower($organ->abbr)
                || str_contains(
                    strtolower($organ->name),
                    $needle,
                ),
            );
        }

        self::assertLessThanOrEqual(
            $all->getTotalCount(),
            $searching->getTotalCount(),
        );
    }

    public function testALabelFilterNarrowsToTheBodiesCarryingIt(): void
    {
        $overview = $this->overview(OrganTypes::Committee);
        $label = null;
        foreach ($overview->getOrgans() as $organ) {
            $carried = $organ->organInformation?->getLiveRevision()?->getLabels()->first();
            if (
                false === $carried
                || null === $carried
            ) {
                continue;
            }

            $label = $carried;

            break;
        }

        self::assertNotNull(
            $label,
            'The seed gives a committee page a label.',
        );

        $expected = self::getContainer()->get(OrganRepository::class)->findOverviewIds(
            OrganTypes::Committee,
            false,
            '',
            [(int) $label->id],
        );
        self::assertNotEmpty($expected);

        $filtered = $this->overview(OrganTypes::Committee);
        $filtered->labelFilters = [(int) $label->id];

        self::assertSame(
            $expected,
            array_map(
                static fn (Organ $organ): ?int => $organ->id,
                $filtered->getOrgans(),
            ),
        );
        self::assertSame(
            count($expected),
            $filtered->getTotalCount(),
        );
    }

    public function testASharedLabelLinkPreSelectsWhatTheOverviewOffers(): void
    {
        $offered = array_map(
            static fn ($label): int => (int) $label->id,
            $this->overview(OrganTypes::Committee)->getLabels(),
        );
        self::assertNotEmpty($offered);

        $request = new Request(['labels' => (string) $offered[0] . ',999999']);
        $request->setSession(self::getContainer()->get('session.factory')->createSession());
        self::getContainer()->get('request_stack')->push($request);

        $overview = $this->overview(OrganTypes::Committee);
        $overview->mount();

        self::assertSame(
            [$offered[0]],
            $overview->labelFilters,
        );
    }

    public function testTheDrawnOrderIsKeptForAsLongAsTheVisitorStays(): void
    {
        $overview = $this->overview(OrganTypes::Fraternity);
        $overview->shuffle = true;
        $overview->seed = 424242;

        $first = array_map(
            static fn (Organ $organ): ?int => $organ->id,
            $overview->getOrgans(),
        );

        $same = $this->overview(OrganTypes::Fraternity);
        $same->shuffle = true;
        $same->seed = 424242;

        self::assertSame(
            $first,
            array_map(
                static fn (Organ $organ): ?int => $organ->id,
                $same->getOrgans(),
            ),
        );
    }

    public function testAskingForMoreGrowsTheWindowInsteadOfReshuffling(): void
    {
        $overview = $this->overview(OrganTypes::Committee);
        $overview->shuffle = true;
        $seed = $overview->seed;
        $first = array_map(
            static fn (Organ $organ): ?int => $organ->id,
            $overview->getOrgans(),
        );

        $overview->loadMore();

        self::assertSame(
            $seed,
            $overview->seed,
        );
        self::assertSame(
            $first,
            array_slice(
                array_map(
                    static fn (Organ $organ): ?int => $organ->id,
                    $overview->getOrgans(),
                ),
                0,
                count($first),
            ),
        );
    }

    /**
     * The two lists on one page are the same component, so the abrogated one writes to and reads from its own query
     * string parameter; otherwise the two would filter each other.
     */
    public function testTheAbrogatedListKeepsItsSearchToItself(): void
    {
        $installed = $this->overview(OrganTypes::Committee);
        $abrogated = $this->overview(OrganTypes::Committee);
        $abrogated->abrogated = true;

        self::assertFalse(
            $installed->scopeSearchToThisList(new LiveProp())->url(),
            'The installed list keeps the parameter it already had.',
        );

        $rewritten = $abrogated->scopeSearchToThisList(new LiveProp())->url();
        self::assertInstanceOf(
            UrlMapping::class,
            $rewritten,
        );
        self::assertSame(
            'abrogated-search',
            $rewritten->as,
        );
    }

    private function overview(OrganTypes $type): BodyOverview
    {
        $overview = self::getContainer()->get(BodyOverview::class);
        $overview->type = $type->value;

        return $overview;
    }
}
