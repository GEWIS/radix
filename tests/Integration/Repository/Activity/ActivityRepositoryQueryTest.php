<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository\Activity;

use App\Entity\Activity\Activity;
use App\Entity\Activity\ActivityRevision;
use App\Entity\Activity\Enums\ActivityCategories;
use App\Entity\Activity\UserSignup;
use App\Entity\Career\Company;
use App\Entity\Decision\AssociationYear;
use App\Entity\Decision\Member;
use App\Entity\Decision\Organ;
use App\Repository\Activity\ActivityRepository;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\LedgerBuilder;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Override;

use function array_map;
use function array_unique;
use function array_values;
use function count;
use function rsort;

final class ActivityRepositoryQueryTest extends DatabaseTestCase
{
    private ActivityRepository $repository;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = self::getContainer()->get(ActivityRepository::class);
    }

    public function testApprovedYearsAreDistinctAndNewestFirst(): void
    {
        $years = $this->repository->getApprovedActivityYears();

        self::assertNotEmpty($years);
        self::assertContains(
            2023,
            $years,
        );
        self::assertContains(
            2025,
            $years,
        );

        $sorted = $years;
        rsort($sorted);
        self::assertSame(
            $sorted,
            $years,
        );
        self::assertSame(
            $sorted,
            array_values(array_unique($years)),
        );
    }

    public function testSubscribedYearsComeFromTheSignedUpMembersLiveActivities(): void
    {
        $signup = $this->entityManager->createQueryBuilder()
            ->select('su')
            ->from(
                UserSignup::class,
                'su',
            )
            ->join(
                'su.signupList',
                'sl',
            )
            ->join(
                'sl.revision',
                'r',
            )
            ->join(
                'r.activity',
                'a',
            )
            ->where('r.endTime < :now')
            ->andWhere('IDENTITY(a.liveRevision) = r.id')
            ->andWhere('a.unpublishedAt IS NULL')
            ->setParameter(
                'now',
                new DateTimeImmutable(),
            )
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        self::assertInstanceOf(
            UserSignup::class,
            $signup,
        );

        $revision = $signup->signupList->revision;
        self::assertNotNull($revision->beginTime);

        self::assertContains(
            AssociationYear::fromDate($revision->beginTime)->getYear(),
            $this->repository->getSubscribedAssociationYears($signup->user),
        );

        $ledger = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(
            EntityManagerInterface::class,
            $ledger,
        );
        $member = new LedgerBuilder($ledger)->member();
        $projected = $this->entityManager->find(
            Member::class,
            $member->lidnr,
        );
        self::assertInstanceOf(
            Member::class,
            $projected,
        );

        self::assertSame(
            [],
            $this->repository->getSubscribedAssociationYears($projected),
        );
    }

    public function testFindPubliclyVisibleLeavesOutUnpublishedAndUnknownActivities(): void
    {
        $upcoming = $this->repository->findUpcoming(1);
        self::assertNotEmpty($upcoming);
        $id = (int) $upcoming[0]->id;

        $visible = $this->repository->findPubliclyVisible($id);
        self::assertNotNull($visible);
        self::assertSame(
            $id,
            $visible->id,
        );

        $unpublished = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(
                Activity::class,
                'a',
            )
            ->where('a.unpublishedAt IS NOT NULL')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        self::assertInstanceOf(
            Activity::class,
            $unpublished,
        );
        self::assertNull($this->repository->findPubliclyVisible((int) $unpublished->id));

        self::assertNull($this->repository->findPubliclyVisible(999999));
    }

    public function testFindUpcomingByCompanyNarrowsAndOrdersByBeginTime(): void
    {
        $company = $this->entityManager->getRepository(Company::class)->findOneBy(['slugName' => 'nexunt']);
        self::assertInstanceOf(
            Company::class,
            $company,
        );

        $activities = $this->repository->findUpcomingByCompany(
            $company,
            2,
        );

        self::assertNotEmpty($activities);
        self::assertLessThanOrEqual(
            2,
            count($activities),
        );

        $previous = null;
        foreach ($activities as $activity) {
            self::assertSame(
                $company->id,
                $activity->getCompany()?->id,
            );
            self::assertGreaterThan(
                new DateTimeImmutable(),
                $activity->getEndTime(),
            );

            if (null !== $previous) {
                self::assertGreaterThanOrEqual(
                    $previous,
                    $activity->getBeginTime(),
                );
            }

            $previous = $activity->getBeginTime();
        }
    }

    public function testFindUpcomingByOrganNarrowsToThatBody(): void
    {
        $organ = $this->entityManager->getRepository(Organ::class)->findOneBy(['abbr' => 'KEUR']);
        self::assertInstanceOf(
            Organ::class,
            $organ,
        );

        $activities = $this->repository->findUpcomingByOrgan(
            $organ,
            100,
        );

        self::assertNotEmpty($activities);
        foreach ($activities as $activity) {
            self::assertSame(
                $organ->id,
                $activity->getOrgan()?->id,
            );
            self::assertNull(
                $activity->unpublishedAt,
            );
        }
    }

    public function testFindUpcomingRespectsTheLimitAndTheCategory(): void
    {
        $activities = $this->repository->findUpcoming(
            2,
            ActivityCategories::Recreational,
        );

        self::assertLessThanOrEqual(
            2,
            count($activities),
        );

        $previous = null;
        foreach ($activities as $activity) {
            self::assertSame(
                ActivityCategories::Recreational,
                $activity->getCategory(),
            );
            self::assertGreaterThan(
                new DateTimeImmutable(),
                $activity->getEndTime(),
            );

            if (null !== $previous) {
                self::assertGreaterThanOrEqual(
                    $previous,
                    $activity->getBeginTime(),
                );
            }

            $previous = $activity->getBeginTime();
        }
    }

    public function testFindLiveBetweenReturnsEverythingThatOverlapsTheWindow(): void
    {
        $from = new DateTimeImmutable();
        $until = new DateTimeImmutable('+3 days');

        $activities = $this->repository->findLiveBetween(
            $from,
            $until,
        );

        self::assertNotEmpty($activities);

        $previous = null;
        foreach ($activities as $activity) {
            self::assertLessThanOrEqual(
                $until,
                $activity->getBeginTime(),
            );
            self::assertGreaterThanOrEqual(
                $from,
                $activity->getEndTime(),
            );

            if (null !== $previous) {
                self::assertGreaterThanOrEqual(
                    $previous,
                    $activity->getBeginTime(),
                );
            }

            $previous = $activity->getBeginTime();
        }
    }

    public function testPrimeSignupListsAndPrimeLabelsHydrateTheirCollections(): void
    {
        $withSignups = $this->activityByLiveName('Movie Night');
        $signupLists = self::liveRevision($withSignups)->getSignupLists();
        self::assertInstanceOf(
            PersistentCollection::class,
            $signupLists,
        );
        self::assertFalse($signupLists->isInitialized());

        $this->repository->primeSignupLists([$withSignups]);
        $primedLists = self::liveRevision($withSignups)->getSignupLists();
        self::assertInstanceOf(
            PersistentCollection::class,
            $primedLists,
        );
        self::assertTrue($primedLists->isInitialized());
        self::assertNotEmpty($primedLists);

        $withLabels = $this->activityByLiveName('Winter Weekend');
        $labels = self::liveRevision($withLabels)->getLabels();
        self::assertInstanceOf(
            PersistentCollection::class,
            $labels,
        );
        self::assertFalse($labels->isInitialized());

        $this->repository->primeLabels([$withLabels]);
        $primedLabels = self::liveRevision($withLabels)->getLabels();
        self::assertInstanceOf(
            PersistentCollection::class,
            $primedLabels,
        );
        self::assertTrue($primedLabels->isInitialized());
        self::assertNotEmpty($primedLabels);

        // An empty list needs no query at all.
        $this->repository->primeSignupLists([]);
        $this->repository->primeLabels([]);
    }

    public function testFindOrganisingOrgansOffersOnlyBodiesWithAPublishedActivityInTheWindow(): void
    {
        $organs = $this->repository->findOrganisingOrgans(
            null,
            null,
            null,
        );

        self::assertNotEmpty($organs);
        self::assertContains(
            'KEUR',
            array_map(
                static fn (Organ $organ): string => $organ->abbr,
                $organs,
            ),
        );

        $previous = '';
        foreach ($organs as $organ) {
            self::assertGreaterThanOrEqual(
                $previous,
                $organ->abbr,
            );
            $previous = $organ->abbr;
        }

        // A window with nothing in it offers nothing.
        self::assertSame(
            [],
            $this->repository->findOrganisingOrgans(
                false,
                new DateTimeImmutable('+50 years'),
                new DateTimeImmutable('+51 years'),
            ),
        );
    }

    public function testWarmForEditingLoadsTheWorkingRevisionAndItsTexts(): void
    {
        $activities = $this->repository->findUpcoming(1);
        self::assertNotEmpty($activities);
        $activity = $activities[0];

        $this->repository->warmForEditing($activity);

        $revision = $activity->getCurrentRevision();
        self::assertNotNull($revision);
        self::assertNotSame(
            '',
            $revision->name->getValueEN(),
        );
    }

    private function activityByLiveName(string $name): Activity
    {
        $activity = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(
                Activity::class,
                'a',
            )
            ->join(
                'a.liveRevision',
                'r',
            )
            ->join(
                'r.name',
                'n',
            )
            ->where('n.valueEN = :name')
            ->setParameter(
                'name',
                $name,
            )
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        self::assertInstanceOf(
            Activity::class,
            $activity,
        );

        return $activity;
    }

    private static function liveRevision(Activity $activity): ActivityRevision
    {
        $revision = $activity->getLiveRevision();
        self::assertNotNull($revision);

        return $revision;
    }
}
