<?php

declare(strict_types=1);

namespace App\Tests\Integration\LiveComponent\Activity;

use App\Entity\Activity\Activity;
use App\Entity\Activity\Enums\ActivityCategories;
use App\Entity\Activity\SignupList;
use App\Entity\Activity\UserSignup;
use App\Entity\Application\Enums\Languages;
use App\Entity\Decision\AssociationYear;
use App\Entity\Decision\Member;
use App\Entity\User\User;
use App\Tests\Integration\DatabaseTestCase;
use App\Twig\Components\Activity\ActivityOverview;
use App\Twig\Components\Application\AbstractInfiniteScrollOverview;
use App\ViewModel\Activity\BodyOption;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function stripos;

/**
 * The overview behind the upcoming page, the year archives and the cross-year search. Each of those fixes the window
 * at mount time; everything else is a live filter that is mirrored into the address.
 */
final class ActivityOverviewTest extends DatabaseTestCase
{
    public function testTheUpcomingOverviewListsWhatHasNotHappenedYet(): void
    {
        $overview = $this->overview();

        $activities = $overview->getActivities();

        self::assertNotEmpty($activities);
        foreach ($activities as $activity) {
            self::assertGreaterThanOrEqual(
                new DateTimeImmutable('today'),
                $activity->getBeginTime(),
            );
        }

        self::assertLessThanOrEqual(
            $overview->getTotalCount(),
            count($activities),
        );
        self::assertSame(
            $overview->getTotalCount() > count($activities),
            $overview->hasMore(),
        );
    }

    public function testAnArchiveStaysInsideItsAssociationYearAndIsGrouped(): void
    {
        $anyActivity = $this->overview()->getActivities()[0] ?? null;
        self::assertInstanceOf(
            Activity::class,
            $anyActivity,
        );
        $year = AssociationYear::fromDate($anyActivity->getBeginTime())->getYear();

        $overview = $this->overview($year);

        self::assertTrue($overview->isGrouped());
        $grouped = $overview->getActivitiesByAssociationYear();
        self::assertLessThanOrEqual(
            1,
            count($grouped),
            'An archive covers one association year.',
        );

        foreach ($grouped as $key => $activities) {
            self::assertStringStartsWith(
                $year . '-',
                $key,
            );
            foreach ($activities as $activity) {
                self::assertLessThanOrEqual(
                    new DateTimeImmutable('now'),
                    $activity->getBeginTime(),
                    'An archive of the current year does not repeat its still-upcoming activities.',
                );
            }
        }
    }

    public function testTheCrossYearSearchIsGroupedAcrossYears(): void
    {
        $overview = $this->overview(crossYear: true);

        self::assertTrue($overview->isGrouped());
        self::assertNotEmpty($overview->getActivities());
        self::assertNotEmpty($overview->getActivitiesByAssociationYear());
    }

    public function testSearchingNarrowsToWhatMatches(): void
    {
        $overview = $this->overview(crossYear: true);
        $overview->search = 'Gala';

        $activities = $overview->getActivities();

        self::assertNotEmpty($activities);
        foreach ($activities as $activity) {
            $name = $activity->getName()->getText(Languages::English);
            self::assertNotNull($name);
            self::assertNotFalse(
                stripos(
                    $name,
                    'gala',
                ),
                'Every result carries what was searched for in its name.',
            );
        }
    }

    public function testACategoryFilterReturnsOnlyThatCategory(): void
    {
        $overview = $this->overview(crossYear: true);
        $overview->category = ActivityCategories::Workshop->value;

        $activities = $overview->getActivities();

        self::assertNotEmpty($activities);
        foreach ($activities as $activity) {
            self::assertSame(
                ActivityCategories::Workshop,
                $activity->getDisplayRevision()->category,
            );
        }

        self::assertContains(
            ActivityCategories::Workshop,
            $overview->getCategories(),
        );
    }

    public function testALabelFilterReturnsOnlyActivitiesCarryingIt(): void
    {
        $overview = $this->overview(crossYear: true);
        $label = $overview->getLabels()[0] ?? null;
        self::assertNotNull(
            $label,
            'The seed offers activity labels.',
        );

        $overview->labelFilters = [(int) $label->id];
        $activities = $overview->getActivities();

        foreach ($activities as $activity) {
            self::assertContains(
                $label,
                $activity->getLabels()->toArray(),
            );
        }
    }

    public function testASharedLabelLinkPreSelectsOnlyTheLabelsThatAreOffered(): void
    {
        $overview = $this->overview(crossYear: true);
        $offered = $this->ids($overview->getLabels());
        self::assertNotEmpty($offered);

        $this->pushRequest(['labels' => (string) $offered[0] . ',999999,0,-4,abc']);

        $mounted = $this->overview(crossYear: true);
        $mounted->mount();

        self::assertSame(
            [$offered[0]],
            $mounted->labelFilters,
        );
    }

    public function testASharedLabelLinkWrittenAsAnArrayIsReadToo(): void
    {
        $offered = $this->ids($this->overview(crossYear: true)->getLabels());
        $this->pushRequest(['labels' => [$offered[0], $offered[1] ?? $offered[0]]]);

        $mounted = $this->overview(crossYear: true);
        $mounted->mount();

        self::assertSame(
            array_values(array_unique([$offered[0], $offered[1] ?? $offered[0]])),
            $mounted->labelFilters,
        );
    }

    public function testTheBodyOptionsAreSplitBetweenTheInstalledAndTheAbrogated(): void
    {
        $overview = $this->overview(crossYear: true);

        $all = $overview->getBodyOptions();
        $active = $overview->getActiveBodyOptions();
        $abrogated = $overview->getAbrogatedBodyOptions();

        self::assertSame(
            count($all),
            count($active) + count($abrogated),
        );
        foreach ($active as $option) {
            self::assertFalse($option->abrogated);
        }

        foreach ($abrogated as $option) {
            self::assertTrue($option->abrogated);
        }
    }

    public function testAnOfferedBodyNarrowsTheListAndOneWithoutActivitiesIsDropped(): void
    {
        $unfiltered = $this->overview(crossYear: true);
        $total = $unfiltered->getTotalCount();

        $dropped = $this->overview(crossYear: true);
        $dropped->organFilter = 999999;
        self::assertSame(
            $total,
            $dropped->getTotalCount(),
            'A shared link naming a body with nothing in this window filters nothing.',
        );

        $offered = $unfiltered->getBodyOptions()[0] ?? null;
        self::assertInstanceOf(
            BodyOption::class,
            $offered,
        );

        $narrowed = $this->overview(crossYear: true);
        $narrowed->organFilter = $offered->id;

        foreach ($narrowed->getActivities() as $activity) {
            self::assertSame(
                $offered->id,
                $activity->getOrgan()?->id,
            );
        }

        self::assertLessThanOrEqual(
            $total,
            $narrowed->getTotalCount(),
        );
    }

    public function testTheDateFiltersBoundTheList(): void
    {
        self::assertEmpty(
            $this->overview(fromDate: '2100-01-01')->getActivities(),
        );
        self::assertEmpty(
            $this->overview(untilDate: '1900-01-01')->getActivities(),
        );

        // An invalid date is ignored instead of emptying the page.
        $unfiltered = $this->overview();
        $nonsense = $this->overview(
            fromDate: 'not-a-date',
            untilDate: '',
        );
        self::assertSame(
            $unfiltered->getTotalCount(),
            $nonsense->getTotalCount(),
        );
    }

    public function testOnlyActivitiesWithAnOpenListAreShownWhenThatIsAskedFor(): void
    {
        $overview = $this->overview();
        $overview->openSignupOnly = true;

        foreach ($overview->getActivities() as $activity) {
            $open = array_filter(
                $activity->getLiveSignupLists()->toArray(),
                static fn (SignupList $list): bool => $list->isOpen(),
            );

            self::assertNotEmpty($open);
        }
    }

    public function testAMemberSeesHowManyListsOfAnActivityTheySignedUpFor(): void
    {
        $this->signIn(8005);
        $overview = $this->overview(
            subscribed: true,
            crossYear: true,
        );

        $activity = $overview->getActivities()[0] ?? null;
        self::assertInstanceOf(
            Activity::class,
            $activity,
        );

        $status = $overview->getViewerSignupStatus($activity->id ?? 0);
        self::assertNotNull($status);
        self::assertGreaterThanOrEqual(
            1,
            $status['signedUp'],
        );
        self::assertSame(
            $activity->getLiveSignupLists()->count(),
            $status['total'],
        );
    }

    public function testAnActivityTheMemberIsNotOnHasNoStatus(): void
    {
        $this->signIn(8005);
        $overview = $this->overview(crossYear: true);
        $overview->getActivities();

        self::assertNull($overview->getViewerSignupStatus(999999));
    }

    public function testTheSubscribedReadingShowsNothingToANonMember(): void
    {
        $overview = $this->overview(subscribed: true);

        self::assertSame(
            [],
            $overview->getActivities(),
        );
        self::assertSame(
            0,
            $overview->getTotalCount(),
        );
        self::assertFalse($overview->hasMore());
    }

    public function testTheSubscribedReadingShowsOnlyWhatTheMemberSignedUpFor(): void
    {
        $this->signIn(8005);
        $overview = $this->overview(
            subscribed: true,
            crossYear: true,
        );

        $activities = $overview->getActivities();

        self::assertNotEmpty($activities);
        foreach ($activities as $activity) {
            $signedUp = false;
            foreach ($activity->getLiveSignupLists() as $list) {
                foreach ($list->getSignUps() as $signup) {
                    if (
                        !($signup instanceof UserSignup)
                        || 8005 !== $signup->user->lidnr
                    ) {
                        continue;
                    }

                    $signedUp = true;
                }
            }

            self::assertTrue(
                $signedUp,
                'The subscriber reading lists only activities the member signed up for.',
            );
        }
    }

    public function testAskingForMoreGrowsTheWindow(): void
    {
        $overview = $this->overview();

        $overview->loadMore();

        self::assertSame(
            AbstractInfiniteScrollOverview::STEP * 2,
            $overview->limit,
        );
    }

    private function overview(
        ?int $year = null,
        bool $crossYear = false,
        bool $subscribed = false,
        ?string $fromDate = null,
        ?string $untilDate = null,
    ): ActivityOverview {
        $overview = self::getContainer()->get(ActivityOverview::class);
        $overview->year = $year;
        $overview->crossYear = $crossYear;
        $overview->subscribed = $subscribed;
        $overview->fromDate = $fromDate;
        $overview->untilDate = $untilDate;

        return $overview;
    }

    /**
     * @param array<array-key, object> $labels
     *
     * @return int[]
     */
    private function ids(array $labels): array
    {
        return array_values(array_map(
            static fn (object $label): int => (int) ($label->id ?? 0),
            $labels,
        ));
    }

    private function signIn(int $lidnr): Member
    {
        $user = $this->entityManager->getRepository(User::class)->find($lidnr);
        self::assertInstanceOf(
            User::class,
            $user,
        );

        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken(
            $user,
            'main',
            ['ROLE_USER'],
        ));

        return $user->member;
    }

    /**
     * @param array<string, mixed> $query
     */
    private function pushRequest(array $query): void
    {
        $request = new Request($query);
        $request->setSession(self::getContainer()->get('session.factory')->createSession());
        self::getContainer()->get('request_stack')->push($request);
    }
}
