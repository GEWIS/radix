<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository\Decision;

use App\Entity\Database\Enums\InstallationFunctions;
use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Database\Enums\MembershipTypes;
use App\Entity\Database\Enums\OrganTypes;
use App\Entity\Decision\Member;
use App\Entity\Decision\Organ;
use App\Entity\User\User;
use App\Repository\Decision\MemberRepository;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\LedgerBuilder;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Override;

use function array_column;
use function iterator_to_array;
use function mb_strtolower;
use function mb_substr;
use function strtoupper;

use const PHP_INT_MAX;

final class MemberRepositoryTest extends DatabaseTestCase
{
    private MemberRepository $repository;

    private EntityManagerInterface $ledger;

    private LedgerBuilder $build;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $ledger = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(
            EntityManagerInterface::class,
            $ledger,
        );

        $this->ledger = $ledger;
        $this->repository = self::getContainer()->get(MemberRepository::class);
        $this->build = new LedgerBuilder($ledger);
    }

    public function testFindByLidnrAndFindSimpleReturnTheMemberAndNullOtherwise(): void
    {
        self::assertSame(
            8030,
            $this->repository->findByLidnr(8030)?->lidnr,
        );
        self::assertSame(
            8030,
            $this->repository->findSimple(8030)?->lidnr,
        );
        self::assertNull($this->repository->findByLidnr(999999));
        self::assertNull($this->repository->findSimple(999999));
    }

    public function testFindByLidnrSkipsADeletedMember(): void
    {
        $ledgerMember = $this->build->member();
        $ledgerMember->deleted = true;
        $this->ledger->flush();

        self::assertNull($this->repository->findByLidnr($ledgerMember->lidnr));
        self::assertNotNull($this->repository->findSimple($ledgerMember->lidnr));
    }

    public function testFindForResetRequiresAMatchingCurrentVisibleMember(): void
    {
        $member = $this->entityManager->find(
            Member::class,
            8030,
        );
        self::assertNotNull($member);
        $email = $member->email;
        self::assertNotNull($email);

        self::assertSame(
            8030,
            $this->repository->findForReset(
                $email,
                8030,
            )?->lidnr,
        );
        self::assertSame(
            8030,
            $this->repository->findForReset(
                strtoupper($email),
                8030,
            )?->lidnr,
        );
        self::assertNull($this->repository->findForReset(
            'wrong@example.org',
            8030,
        ));
        self::assertNull($this->repository->findForReset(
            $email,
            8031,
        ));

        $ledgerMember = $this->build->member(
            MembershipTypes::Ordinary,
            '-2 years',
            '-1 year',
        );
        $this->ledger->flush();
        self::assertNull($this->repository->findForReset(
            (string) $ledgerMember->email,
            $ledgerMember->lidnr,
        ));
    }

    public function testSearchByNameFindsACurrentMemberWithTheExpectedShape(): void
    {
        $member = $this->entityManager->find(
            Member::class,
            8000,
        );
        self::assertNotNull($member);

        $results = $this->repository->searchByName(mb_strtolower(mb_substr(
            $member->lastName,
            0,
            4,
        )));

        self::assertNotEmpty($results);
        self::assertContains(
            8000,
            array_column(
                $results,
                'lidnr',
            ),
        );
        self::assertArrayHasKey(
            'fullName',
            $results[0],
        );
        self::assertArrayHasKey(
            'generation',
            $results[0],
        );
    }

    public function testSearchByNameRespectsTheLimitAndOrdersByGeneration(): void
    {
        $results = $this->repository->searchByName(
            'a',
            5,
        );

        self::assertCount(
            5,
            $results,
        );

        $previous = PHP_INT_MAX;
        foreach ($results as $result) {
            self::assertLessThanOrEqual(
                $previous,
                $result['generation'],
            );
            $previous = $result['generation'];
        }
    }

    public function testSearchByNameLeavesOutHiddenMembers(): void
    {
        $ledgerMember = $this->build->member();
        $ledgerMember->firstName = 'Verborgen';
        $ledgerMember->lastName = 'Zoeknaam';
        $ledgerMember->hidden = true;
        $this->ledger->flush();

        self::assertSame(
            [],
            $this->repository->searchByName('zoeknaam'),
        );
    }

    public function testFindBirthdayMembersOnlyReturnsVisibleMembersWhoseBirthdayFallsInTheWindow(): void
    {
        $today = new DateTimeImmutable('today');

        // A non-leap year, so the day of the year is the same as today's.
        $birthday = $this->build->member();
        $birthday->setBirth(new DateTimeImmutable('2001-' . $today->format('m-d')));
        $hidden = $this->build->member();
        $hidden->setBirth(new DateTimeImmutable('2001-' . $today->format('m-d')));
        $hidden->hidden = true;
        $this->ledger->flush();

        $lidnrs = array_column(
            $this->repository->findBirthdayMembers(0),
            'lidnr',
        );

        self::assertContains(
            $birthday->lidnr,
            $lidnrs,
        );
        self::assertNotContains(
            $hidden->lidnr,
            $lidnrs,
        );
    }

    public function testOrganInstallationsMoveFromCurrentToHistoricalOnDischarge(): void
    {
        $meeting = $this->build->meeting();
        $organ = $this->build->foundOrgan($meeting);
        $ledgerMember = $this->build->member();
        $installation = $this->build->install(
            $meeting,
            $organ,
            $ledgerMember,
            InstallationFunctions::Member,
        );

        $member = $this->projectedMember($ledgerMember->lidnr);

        self::assertContains(
            $organ->abbr,
            $this->organAbbreviations($this->repository->findOrgans($member)),
        );
        self::assertNotEmpty($this->repository->findCurrentInstallations($member));
        self::assertSame(
            [],
            $this->repository->findHistoricalInstallations($member),
        );

        $this->build->discharge(
            $this->build->meeting(
                MeetingTypes::ALV,
                '2026-09-01',
            ),
            $installation,
        );
        $this->entityManager->clear();

        $member = $this->projectedMember($ledgerMember->lidnr);

        self::assertSame(
            [],
            $this->repository->findCurrentInstallations($member),
        );
        self::assertNotEmpty($this->repository->findHistoricalInstallations($member));
    }

    public function testPaginateNormalReturnsAVisibleCurrentPageInAscendingOrder(): void
    {
        $page = iterator_to_array(
            $this->repository->paginateNormal(
                1,
                5,
            ),
            false,
        );

        self::assertCount(
            5,
            $page,
        );

        $previous = 0;
        foreach ($page as $member) {
            self::assertLessThanOrEqual(
                $member->lidnr,
                $previous,
            );
            $previous = $member->lidnr;
            self::assertFalse($member->hidden);
            self::assertFalse($member->deleted);
            self::assertGreaterThanOrEqual(
                new DateTimeImmutable(),
                $member->expiration,
            );
        }
    }

    public function testPaginateActiveHonoursDeletedAndInactiveFraternitySwitches(): void
    {
        $meeting = $this->build->meeting();
        $committee = $this->build->foundOrgan($meeting);
        $fraternity = $this->build->foundOrgan(
            $meeting,
            'FRT',
            'Testdispuut',
            OrganTypes::Fraternity,
        );

        $deleted = $this->build->member();
        $this->build->install(
            $meeting,
            $committee,
            $deleted,
            InstallationFunctions::Member,
        );
        $deleted->deleted = true;

        $inactive = $this->build->member();
        $this->build->install(
            $meeting,
            $fraternity,
            $inactive,
            InstallationFunctions::InactiveMember,
        );
        $this->ledger->flush();

        $withoutDeleted = $this->activeLidnrs(
            false,
            false,
        );
        $withDeleted = $this->activeLidnrs(
            false,
            true,
        );
        $withoutInactive = $this->activeLidnrs(
            false,
            false,
        );
        $withInactive = $this->activeLidnrs(
            true,
            false,
        );

        self::assertNotContains(
            $deleted->lidnr,
            $withoutDeleted,
        );
        self::assertContains(
            $deleted->lidnr,
            $withDeleted,
        );
        self::assertNotContains(
            $inactive->lidnr,
            $withoutInactive,
        );
        self::assertContains(
            $inactive->lidnr,
            $withInactive,
        );
    }

    public function testPaginateForAdminFiltersOnEverySupportedState(): void
    {
        $hidden = $this->build->member();
        $hidden->hidden = true;
        $deleted = $this->build->member();
        $deleted->deleted = true;
        $this->ledger->flush();

        foreach (iterator_to_array($this->adminPage(filters: ['hiddenOnly' => true])) as $member) {
            self::assertTrue($member->hidden);
        }

        foreach (iterator_to_array($this->adminPage(filters: ['deletedOnly' => true])) as $member) {
            self::assertTrue($member->deleted);
        }

        foreach (iterator_to_array($this->adminPage(filters: ['expiredOnly' => true])) as $member) {
            self::assertLessThan(
                new DateTimeImmutable(),
                $member->expiration,
            );
        }

        foreach (iterator_to_array($this->adminPage(filters: ['type' => MembershipTypes::Ordinary])) as $member) {
            self::assertSame(
                MembershipTypes::Ordinary,
                $member->type,
            );
        }

        $activated = array_column(
            iterator_to_array($this->adminPage(filters: ['activatedOnly' => true])),
            'lidnr',
        );
        self::assertContains(
            8000,
            $activated,
        );
        self::assertNotContains(
            $hidden->lidnr,
            $activated,
        );

        // A second factor only exists on an account that has enrolled.
        $user = $this->entityManager->find(
            User::class,
            8000,
        );
        self::assertNotNull($user);
        $user->totpSecret = 'TESTSECRET';
        $this->entityManager->flush();

        $mfa = array_column(
            iterator_to_array($this->adminPage(filters: ['mfaOnly' => true])),
            'lidnr',
        );
        self::assertContains(
            8000,
            $mfa,
        );
        self::assertNotContains(
            $deleted->lidnr,
            $mfa,
        );
    }

    public function testPaginateForAdminSearchesAndSorts(): void
    {
        $member = $this->build->member();
        $member->firstName = 'Zoektarget';
        $member->lastName = 'Achternaam';
        $this->ledger->flush();

        $page = iterator_to_array($this->adminPage(search: 'zoektarget'));

        self::assertCount(
            1,
            $page,
        );
        self::assertSame(
            $member->lidnr,
            $page[0]->lidnr,
        );

        $byName = iterator_to_array(
            $this->repository->paginateForAdmin(
                '',
                'name',
                'asc',
                [],
                1,
                5,
            ),
            false,
        );
        self::assertCount(
            5,
            $byName,
        );

        $previous = '';
        foreach ($byName as $row) {
            self::assertGreaterThanOrEqual(
                $previous,
                $row->lastName,
            );
            $previous = $row->lastName;
        }
    }

    public function testFindAllWithUserDetailsReturnsBothMembersAndUsers(): void
    {
        $result = $this->repository->findAllWithUserDetails();

        self::assertNotEmpty($result);

        $members = 0;
        $users = 0;
        foreach ($result as $row) {
            if ($row instanceof Member) {
                $members++;
            } elseif ($row instanceof User) {
                $users++;
            }
        }

        self::assertGreaterThan(
            0,
            $members,
        );
        self::assertGreaterThan(
            0,
            $users,
        );
    }

    public function testWarmInstallationsLoadsTheCollectionsInOneGo(): void
    {
        $meeting = $this->build->meeting();
        $organ = $this->build->foundOrgan($meeting);
        $ledgerMember = $this->build->member();
        $this->build->install(
            $meeting,
            $organ,
            $ledgerMember,
            InstallationFunctions::Member,
        );

        $this->entityManager->clear();
        $member = $this->projectedMember($ledgerMember->lidnr);

        $this->repository->warmInstallations([$member]);

        $installations = $member->getOrganInstallations();
        self::assertInstanceOf(
            PersistentCollection::class,
            $installations,
        );
        self::assertTrue($installations->isInitialized());
        self::assertNotEmpty($installations);

        // An empty list needs no query at all.
        $this->repository->warmInstallations([]);
    }

    private function projectedMember(int $lidnr): Member
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
     * @param array<Organ> $organs
     *
     * @return list<string>
     */
    private function organAbbreviations(array $organs): array
    {
        $abbrs = [];
        foreach ($organs as $organ) {
            $abbrs[] = $organ->abbr;
        }

        return $abbrs;
    }

    /**
     * @return list<int>
     */
    private function activeLidnrs(
        bool $includeInactiveFraternity,
        bool $includeDeleted,
    ): array {
        return array_column(
            iterator_to_array($this->repository->paginateActive(
                $includeInactiveFraternity,
                $includeDeleted,
                1,
                5000,
            )),
            'lidnr',
        );
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return Paginator<Member>
     */
    private function adminPage(
        string $search = '',
        array $filters = [],
    ): Paginator {
        return $this->repository->paginateForAdmin(
            $search,
            'lidnr',
            'asc',
            $filters,
            1,
            5000,
        );
    }
}
