<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository\Database;

use App\Entity\Database\Address;
use App\Entity\Database\CheckoutSession;
use App\Entity\Database\Enums\AddressTypes;
use App\Entity\Database\Enums\CheckoutSessionStates;
use App\Entity\Database\Enums\PostalRegions;
use App\Entity\Database\Enums\ProspectiveMemberFilter;
use App\Entity\Database\Enums\Studies;
use App\Entity\Database\ProspectiveMember;
use App\Repository\Database\CheckoutSessionRepository;
use App\Repository\Database\ProspectiveMemberRepository;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Override;

use function array_column;
use function iterator_to_array;

use const PHP_INT_MAX;

final class ProspectiveMemberRepositoryTest extends DatabaseTestCase
{
    private ProspectiveMemberRepository $repository;

    private int $sessionCounter = 0;

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
        $this->repository = self::getContainer()->get(ProspectiveMemberRepository::class);
    }

    public function testHasMemberWithIgnoresCase(): void
    {
        $member = $this->prospective('anna@example.org');

        self::assertTrue($this->repository->hasMemberWith('ANNA@example.org'));
        self::assertFalse($this->repository->hasMemberWith('nobody@example.invalid'));
        self::assertGreaterThan(
            0,
            $member->lidnr,
        );
    }

    public function testFindWithoutCheckoutOnlyReturnsOldApplicants(): void
    {
        $without = $this->prospective(
            'zonder@example.org',
            changedOn: '-40 days',
        );
        $recent = $this->prospective(
            'recent@example.org',
            changedOn: '-1 day',
        );
        $with = $this->prospective(
            'met@example.org',
            changedOn: '-40 days',
        );
        $this->session(
            $with,
            CheckoutSessionStates::Paid,
            '+1 hour',
        );

        $lidnrs = array_column(
            $this->repository->findWithoutCheckout(),
            'lidnr',
        );

        self::assertContains(
            $without->lidnr,
            $lidnrs,
        );
        self::assertNotContains(
            $recent->lidnr,
            $lidnrs,
        );
        self::assertNotContains(
            $with->lidnr,
            $lidnrs,
        );
    }

    public function testFindWithFullyExpiredOrFailedCheckoutRespectsItsBoundaries(): void
    {
        $failedLongAgo = $this->prospective('failed-old@example.org');
        $this->session(
            $failedLongAgo,
            CheckoutSessionStates::Failed,
            '-32 days',
        );

        $failedRecently = $this->prospective('failed-new@example.org');
        $this->session(
            $failedRecently,
            CheckoutSessionStates::Failed,
            '-30 days',
        );

        $expiredLongAgo = $this->prospective('expired-old@example.org');
        $this->session(
            $expiredLongAgo,
            CheckoutSessionStates::Expired,
            '-2 days',
        );

        $expiredJustNow = $this->prospective('expired-new@example.org');
        $this->session(
            $expiredJustNow,
            CheckoutSessionStates::Expired,
            '+1 hour',
        );

        $paid = $this->prospective('paid@example.org');
        $this->session(
            $paid,
            CheckoutSessionStates::Paid,
            '-32 days',
        );

        $lidnrs = array_column(
            $this->repository->findWithFullyExpiredOrFailedCheckout(),
            'lidnr',
        );

        self::assertContains(
            $failedLongAgo->lidnr,
            $lidnrs,
        );
        self::assertNotContains(
            $failedRecently->lidnr,
            $lidnrs,
        );
        self::assertContains(
            $expiredLongAgo->lidnr,
            $lidnrs,
        );
        self::assertNotContains(
            $expiredJustNow->lidnr,
            $lidnrs,
        );
        self::assertNotContains(
            $paid->lidnr,
            $lidnrs,
        );
    }

    /**
     * A recovered checkout points at the session it replaces, and the original is judged, because it is the one
     * awaiting payment.
     */
    public function testFindWithFullyExpiredOrFailedCheckoutLooksThroughARecovery(): void
    {
        $member = $this->prospective('recovered@example.org');
        $original = $this->session(
            $member,
            CheckoutSessionStates::Expired,
            '-2 days',
        );
        $recovered = $this->session(
            $member,
            CheckoutSessionStates::Expired,
            '-1 hour',
        );
        $recovered->setRecoveredFrom($original);
        $this->entityManager->flush();

        self::assertContains(
            $member->lidnr,
            array_column(
                $this->repository->findWithFullyExpiredOrFailedCheckout(),
                'lidnr',
            ),
        );
    }

    public function testPaginateForOverviewFiltersOnTheLatestSession(): void
    {
        $paid = $this->prospective('filter-paid@example.org');
        $this->session(
            $paid,
            CheckoutSessionStates::Paid,
            '+1 hour',
        );

        $awaiting = $this->prospective('filter-awaiting@example.org');
        $this->session(
            $awaiting,
            CheckoutSessionStates::Pending,
            '+1 hour',
        );

        $expired = $this->prospective('filter-expired@example.org');
        $this->session(
            $expired,
            CheckoutSessionStates::Expired,
            '-1 hour',
        );

        $none = $this->prospective('filter-none@example.org');

        // An applicant who restarted: the old session is expired, the current one is paid. Only the latter counts.
        $restarted = $this->prospective('filter-restarted@example.org');
        $this->session(
            $restarted,
            CheckoutSessionStates::Expired,
            '-1 day',
        );
        $this->session(
            $restarted,
            CheckoutSessionStates::Paid,
            '+1 hour',
        );

        $paidLidnrs = $this->overviewLidnrs(ProspectiveMemberFilter::Paid);
        $awaitingLidnrs = $this->overviewLidnrs(ProspectiveMemberFilter::AwaitingPayment);
        $expiredLidnrs = $this->overviewLidnrs(ProspectiveMemberFilter::ExpiredOrFailed);
        $allLidnrs = $this->overviewLidnrs(ProspectiveMemberFilter::All);

        self::assertContains(
            $paid->lidnr,
            $paidLidnrs,
        );
        self::assertContains(
            $awaiting->lidnr,
            $awaitingLidnrs,
        );
        self::assertContains(
            $expired->lidnr,
            $expiredLidnrs,
        );
        self::assertContains(
            $none->lidnr,
            $expiredLidnrs,
        );
        self::assertContains(
            $restarted->lidnr,
            $paidLidnrs,
        );
        self::assertNotContains(
            $restarted->lidnr,
            $expiredLidnrs,
        );
        self::assertContains(
            $none->lidnr,
            $allLidnrs,
        );
    }

    public function testPaginateForOverviewSearchesAndOrdersByNumberDescending(): void
    {
        $member = $this->prospective('search-me@example.org');
        $member->firstName = 'Zoektarget';
        $member->lastName = 'Kandidaat';
        $this->entityManager->flush();

        $page = iterator_to_array(
            $this->repository->paginateForOverview(
                'zoektarget',
                ProspectiveMemberFilter::All,
                1,
                10,
            ),
            false,
        );

        self::assertCount(
            1,
            $page,
        );
        self::assertSame(
            $member->lidnr,
            $page[0]->lidnr,
        );

        $all = iterator_to_array(
            $this->repository->paginateForOverview(
                '',
                ProspectiveMemberFilter::All,
                1,
                2,
            ),
            false,
        );
        self::assertCount(
            2,
            $all,
        );
        self::assertSame(
            $member->lidnr,
            $all[0]->lidnr,
        );

        $previous = PHP_INT_MAX;
        foreach ($all as $row) {
            self::assertLessThanOrEqual(
                $previous,
                $row->lidnr,
            );
            $previous = $row->lidnr;
        }

        self::assertCount(
            0,
            iterator_to_array($this->repository->paginateForOverview(
                'niemandhier',
                ProspectiveMemberFilter::All,
                1,
                10,
            )),
        );
    }

    public function testCountsForOverviewAgreeWithCountForFilter(): void
    {
        $member = $this->prospective('counted@example.org');
        $member->firstName = 'Zoektarget';
        $member->lastName = 'Geteld';
        $this->session(
            $member,
            CheckoutSessionStates::Paid,
            '+1 hour',
        );

        $counts = $this->repository->countsForOverview('zoektarget');

        self::assertSame(
            1,
            $counts[ProspectiveMemberFilter::All->value],
        );
        self::assertSame(
            1,
            $counts[ProspectiveMemberFilter::Paid->value],
        );
        self::assertSame(
            0,
            $counts[ProspectiveMemberFilter::ExpiredOrFailed->value],
        );

        $unfiltered = $this->repository->countsForOverview('');
        foreach (ProspectiveMemberFilter::cases() as $filter) {
            self::assertSame(
                $unfiltered[$filter->value],
                $this->repository->countForFilter($filter),
            );
        }
    }

    public function testPersistAndRemove(): void
    {
        $member = $this->prospective('persisted@example.org');
        $lidnr = $member->lidnr;

        $member->lastName = 'Aangepast';
        $this->repository->persist($member);
        $this->entityManager->clear();

        $reloaded = $this->entityManager->find(
            ProspectiveMember::class,
            $lidnr,
        );
        self::assertInstanceOf(
            ProspectiveMember::class,
            $reloaded,
        );
        self::assertSame(
            'Aangepast',
            $reloaded->lastName,
        );

        $this->repository->remove($reloaded);
        self::assertNull($this->entityManager->find(
            ProspectiveMember::class,
            $lidnr,
        ));
    }

    /**
     * @return list<int>
     */
    private function overviewLidnrs(ProspectiveMemberFilter $filter): array
    {
        return array_column(
            iterator_to_array($this->repository->paginateForOverview(
                '',
                $filter,
                1,
                10000,
            )),
            'lidnr',
        );
    }

    private function prospective(
        string $email,
        string $changedOn = 'now',
    ): ProspectiveMember {
        $member = new ProspectiveMember();
        $member->initials = 'T.';
        $member->firstName = 'Test';
        $member->middleName = '';
        $member->lastName = 'Kandidaat';
        $member->email = $email;
        $member->birth = new DateTimeImmutable('2001-01-01');
        $member->changedOn = new DateTimeImmutable($changedOn);
        $member->study = Studies::BAM;

        $address = new Address();
        $address->type = AddressTypes::Student;
        $address->country = PostalRegions::Netherlands;
        $address->street = 'Teststraat';
        $address->number = '1';
        $address->postalCode = '5600 AA';
        $address->city = 'Eindhoven';
        $address->phone = '1';
        $member->setAddress($address);

        $this->repository->persist($member);

        return $member;
    }

    private function session(
        ProspectiveMember $member,
        CheckoutSessionStates $state,
        string $expiration,
    ): CheckoutSession {
        $session = new CheckoutSession();
        $session->checkoutId = 'test-' . $member->lidnr . '-' . ++$this->sessionCounter;
        $session->prospectiveMember = $member;
        $session->created = new DateTimeImmutable();
        $session->expiration = new DateTimeImmutable($expiration);
        $session->state = $state;

        self::getContainer()->get(CheckoutSessionRepository::class)->persist($session);

        return $session;
    }
}
