<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository\Database;

use App\Entity\Database\Address;
use App\Entity\Database\Enums\AddressTypes;
use App\Entity\Database\Enums\InstallationFunctions;
use App\Entity\Database\Enums\MemberFilter;
use App\Entity\Database\Enums\MembershipTypes;
use App\Entity\Database\Enums\PostalRegions;
use App\Entity\Database\Member;
use App\Entity\Database\Membership;
use App\Repository\Database\MemberRepository;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\LedgerBuilder;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Override;

use function array_column;
use function count;
use function iterator_to_array;
use function ucfirst;

use const PHP_INT_MAX;

final class MemberRepositoryTest extends DatabaseTestCase
{
    private MemberRepository $repository;

    private LedgerBuilder $build;

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
        $this->repository = self::getContainer()->get(MemberRepository::class);
        $this->build = new LedgerBuilder($this->entityManager);

        $this->entityManager->getConnection()->executeStatement('CREATE EXTENSION IF NOT EXISTS unaccent');
    }

    public function testFindByEmailIsCaseInsensitiveAndUnknownIsNull(): void
    {
        $member = $this->build->member();
        $email = $member->email;
        self::assertNotNull($email);

        self::assertSame(
            $member->lidnr,
            $this->repository->findByEmail(ucfirst($email))?->lidnr,
        );
        self::assertTrue($this->repository->hasMemberWith(ucfirst($email)));
        self::assertNull($this->repository->findByEmail('nobody@example.invalid'));
        self::assertFalse($this->repository->hasMemberWith('nobody@example.invalid'));
    }

    public function testSearchFindsByMembershipNumberAndByFullName(): void
    {
        $member = $this->build->member();
        $member->firstName = 'Zoektarget';
        $member->lastName = 'Naamzoek';
        $this->entityManager->flush();

        self::assertContains(
            $member->lidnr,
            array_column(
                $this->repository->search('naamzoek'),
                'lidnr',
            ),
        );
        self::assertContains(
            $member->lidnr,
            array_column(
                $this->repository->search((string) $member->lidnr),
                'lidnr',
            ),
        );
    }

    public function testSearchCapsAnEmptyTermAndFindsNothingForGibberish(): void
    {
        $results = $this->repository->search('');

        self::assertNotEmpty($results);
        self::assertLessThanOrEqual(
            32,
            count($results),
        );

        self::assertSame(
            [],
            $this->repository->search('zzzznotfound'),
        );
    }

    public function testSearchSkipsHiddenAndDeletedMembers(): void
    {
        $member = $this->build->member();
        $member->firstName = 'Zoektarget';
        $member->lastName = 'Unieknaam';
        $this->entityManager->flush();

        self::assertContains(
            $member->lidnr,
            array_column(
                $this->repository->search('unieknaam'),
                'lidnr',
            ),
        );

        $member->hidden = true;
        $this->entityManager->flush();
        self::assertSame(
            [],
            $this->repository->search('unieknaam'),
        );

        $member->hidden = false;
        $member->deleted = true;
        $this->entityManager->flush();
        self::assertSame(
            [],
            $this->repository->search('unieknaam'),
        );
    }

    public function testFindMemberAddressReturnsTheAddressOfTheRequestedType(): void
    {
        $member = $this->build->member();
        $this->repository->persistAddress($this->address(
            $member,
            AddressTypes::Student,
        ));

        $found = $this->repository->findMemberAddress(
            $member,
            AddressTypes::Student,
        );

        self::assertNotNull($found);
        self::assertSame(
            $member->lidnr,
            $found->getMember()?->lidnr,
        );
        self::assertNull($this->repository->findMemberAddress(
            $member,
            AddressTypes::Mail,
        ));
    }

    public function testFindSimpleReturnsAMemberAndNullForAnUnknownNumber(): void
    {
        $member = $this->build->member();

        $found = $this->repository->findSimple($member->lidnr);

        self::assertNotNull($found);
        self::assertSame(
            $member->lidnr,
            $found->lidnr,
        );
        self::assertNull($this->repository->findSimple(999999));
    }

    public function testFindExpiredOnlyReturnsMembersWhoseMembershipHasEnded(): void
    {
        $expired = $this->build->member(
            MembershipTypes::Ordinary,
            '-2 years',
            '-1 year',
        );
        $current = $this->build->member();

        $lidnrs = array_column(
            $this->repository->findExpired(new DateTimeImmutable('today')),
            'lidnr',
        );

        self::assertContains(
            $expired->lidnr,
            $lidnrs,
        );
        self::assertNotContains(
            $current->lidnr,
            $lidnrs,
        );
    }

    public function testFindAttentionWithoutEmailSkipsAMemberWhoHasOne(): void
    {
        $member = $this->build->member(
            MembershipTypes::Ordinary,
            '-2 years',
            '-10 days',
        );
        $member->setEmail(null);
        $this->entityManager->flush();

        self::assertContains(
            $member->lidnr,
            array_column(
                $this->repository->findAttentionWithoutEmail(),
                'lidnr',
            ),
        );

        $member->setEmail('again@example.org');
        $this->entityManager->flush();

        self::assertNotContains(
            $member->lidnr,
            array_column(
                $this->repository->findAttentionWithoutEmail(),
                'lidnr',
            ),
        );
    }

    public function testFindAttentionWithoutStudentNumberOnlyLooksAtOrdinaryMembers(): void
    {
        $ordinary = $this->build->member(
            MembershipTypes::Ordinary,
            '-2 years',
            '-10 days',
        );
        $ordinary->studentNumber = null;
        $graduate = $this->build->member(
            MembershipTypes::Graduate,
            '-2 years',
            '-10 days',
        );
        $graduate->studentNumber = null;
        $this->entityManager->flush();

        $lidnrs = array_column(
            $this->repository->findAttentionWithoutStudentNumber(),
            'lidnr',
        );

        self::assertContains(
            $ordinary->lidnr,
            $lidnrs,
        );
        self::assertNotContains(
            $graduate->lidnr,
            $lidnrs,
        );

        $ordinary->studentNumber = '1888888';
        $this->entityManager->flush();
        self::assertNotContains(
            $ordinary->lidnr,
            array_column(
                $this->repository->findAttentionWithoutStudentNumber(),
                'lidnr',
            ),
        );
    }

    public function testFindAttentionExpiringReturnsNothingWhenNothingIsIncluded(): void
    {
        self::assertSame(
            [],
            $this->repository->findAttentionExpiring(
                false,
                false,
            ),
        );
    }

    public function testFindAttentionExpiringNarrowsOnTypeAndWindow(): void
    {
        $soon = $this->build->member(
            MembershipTypes::Ordinary,
            '-1 year',
            '+10 days',
        );
        $later = $this->build->member(
            MembershipTypes::Ordinary,
            '-1 year',
            '+200 days',
        );

        $lidnrs = array_column(
            $this->repository->findAttentionExpiring(
                specificType: MembershipTypes::Ordinary,
                expiresWithinDays: 30,
            ),
            'lidnr',
        );

        self::assertContains(
            $soon->lidnr,
            $lidnrs,
        );
        self::assertNotContains(
            $later->lidnr,
            $lidnrs,
        );
    }

    public function testFindAttentionExpiringSplitsActiveFromInactiveMembers(): void
    {
        $meeting = $this->build->meeting();
        $organ = $this->build->foundOrgan(
            $meeting,
            'AIN',
            'Actieve aandacht testcommissie',
        );
        $active = $this->build->member(
            MembershipTypes::Ordinary,
            '-1 year',
            '+10 days',
        );
        $this->build->install(
            $meeting,
            $organ,
            $active,
            InstallationFunctions::Member,
        );
        $notInstalled = $this->build->member(
            MembershipTypes::Ordinary,
            '-1 year',
            '+10 days',
        );

        $onlyActive = array_column(
            $this->repository->findAttentionExpiring(
                includeActive: true,
                includeNonActive: false,
                specificType: MembershipTypes::Ordinary,
                expiresWithinDays: 30,
            ),
            'lidnr',
        );
        $onlyNonActive = array_column(
            $this->repository->findAttentionExpiring(
                includeActive: false,
                includeNonActive: true,
                specificType: MembershipTypes::Ordinary,
                expiresWithinDays: 30,
            ),
            'lidnr',
        );

        self::assertContains(
            $active->lidnr,
            $onlyActive,
        );
        self::assertNotContains(
            $notInstalled->lidnr,
            $onlyActive,
        );
        self::assertContains(
            $notInstalled->lidnr,
            $onlyNonActive,
        );
        self::assertNotContains(
            $active->lidnr,
            $onlyNonActive,
        );
    }

    public function testCanRemoveOnlyWithoutSubDecisionsThatNameTheMember(): void
    {
        $meeting = $this->build->meeting();
        $foundation = $this->build->foundOrgan($meeting);
        $plain = $this->build->member();
        $installed = $this->build->member();
        $this->build->install(
            $meeting,
            $foundation,
            $installed,
            InstallationFunctions::Member,
        );

        self::assertTrue($this->repository->canRemove($plain));
        self::assertFalse($this->repository->canRemove($installed));
    }

    public function testGetNonExpiredNonHiddenMembersSkipsHiddenAndExpiredMembers(): void
    {
        $visible = $this->build->member();
        $hidden = $this->build->member();
        $hidden->hidden = true;
        $expired = $this->build->member(
            MembershipTypes::Ordinary,
            '-2 years',
            '-1 year',
        );
        $this->entityManager->flush();

        $lidnrs = array_column(
            $this->repository->getNonExpiredNonHiddenMembers(),
            'lidnr',
        );

        self::assertContains(
            $visible->lidnr,
            $lidnrs,
        );
        self::assertNotContains(
            $hidden->lidnr,
            $lidnrs,
        );
        self::assertNotContains(
            $expired->lidnr,
            $lidnrs,
        );
    }

    public function testCountMembersGrowsByAnAddedCurrentMember(): void
    {
        $before = $this->repository->countMembers();
        $member = $this->build->member();

        self::assertSame(
            $before + 1,
            $this->repository->countMembers(),
        );
        self::assertGreaterThanOrEqual(
            $this->repository->countMembers(),
            $this->repository->countMembers(includeExpired: true),
        );
        self::assertGreaterThan(
            0,
            $member->lidnr,
        );
    }

    public function testCountByMembershipTypeCountsTheAddedMemberUnderItsType(): void
    {
        $before = $this->repository->countByMembershipType();
        $this->build->member(MembershipTypes::Ordinary);
        $after = $this->repository->countByMembershipType();

        self::assertSame(
            $before[MembershipTypes::Ordinary->value] + 1,
            $after[MembershipTypes::Ordinary->value],
        );
        foreach (MembershipTypes::cases() as $type) {
            self::assertArrayHasKey(
                $type->value,
                $after,
            );
        }
    }

    public function testPaginateForOverviewOrdersByNumberDescending(): void
    {
        $page = iterator_to_array(
            $this->repository->paginateForOverview(
                '',
                MemberFilter::Everyone,
                1,
                5,
            ),
            false,
        );

        self::assertCount(
            5,
            $page,
        );

        $previous = PHP_INT_MAX;
        foreach ($page as $member) {
            self::assertLessThanOrEqual(
                $previous,
                $member->lidnr,
            );
            $previous = $member->lidnr;
        }
    }

    public function testPaginateForOverviewFiltersOnStateAndMissingData(): void
    {
        $deleted = $this->build->member();
        $deleted->deleted = true;
        $expired = $this->build->member(
            MembershipTypes::Ordinary,
            '-2 years',
            '-1 year',
        );
        $active = $this->build->member();
        $incomplete = $this->build->member();
        $incomplete->setEmail(null);
        $this->entityManager->flush();

        self::assertContains(
            $deleted->lidnr,
            $this->overviewLidnrs(MemberFilter::Removed),
        );
        self::assertNotContains(
            $deleted->lidnr,
            $this->overviewLidnrs(MemberFilter::Everyone),
        );
        self::assertContains(
            $expired->lidnr,
            $this->overviewLidnrs(MemberFilter::Expired),
        );
        self::assertNotContains(
            $expired->lidnr,
            $this->overviewLidnrs(MemberFilter::Active),
        );
        self::assertContains(
            $active->lidnr,
            $this->overviewLidnrs(MemberFilter::Active),
        );
        self::assertContains(
            $incomplete->lidnr,
            $this->overviewLidnrs(MemberFilter::MissingData),
        );
    }

    public function testPaginateForOverviewSearchesOnMembershipNumber(): void
    {
        $member = $this->build->member();

        $page = iterator_to_array(
            $this->repository->paginateForOverview(
                (string) $member->lidnr,
                MemberFilter::Everyone,
                1,
                10,
            ),
            false,
        );

        self::assertContains(
            $member->lidnr,
            array_column(
                $page,
                'lidnr',
            ),
        );
    }

    public function testCountsForOverviewAgreeWithThePaginator(): void
    {
        $member = $this->build->member();
        $member->firstName = 'Zoektarget';
        $member->lastName = 'Alleen';
        $this->entityManager->flush();

        $counts = $this->repository->countsForOverview('zoektarget');

        self::assertSame(
            1,
            $counts[MemberFilter::Everyone->value],
        );
        self::assertSame(
            0,
            $counts[MemberFilter::Removed->value],
        );
        self::assertSame(
            count(iterator_to_array($this->repository->paginateForOverview(
                'zoektarget',
                MemberFilter::Everyone,
                1,
                10,
            ))),
            1,
        );
    }

    public function testPersistAllRemovePersistAndAddressAreWired(): void
    {
        $first = $this->freshMember('Een');
        $second = $this->freshMember('Twee');

        $this->repository->persistAll([
            $first,
            $second,
        ]);

        $this->entityManager->clear();

        self::assertNotNull($this->entityManager->find(
            Member::class,
            $first->lidnr,
        ));
        self::assertNotNull($this->entityManager->find(
            Member::class,
            $second->lidnr,
        ));

        $first = $this->entityManager->find(
            Member::class,
            $first->lidnr,
        );
        self::assertInstanceOf(
            Member::class,
            $first,
        );

        $first->lastName = 'Aangepast';
        $this->repository->persist($first);

        $this->entityManager->clear();
        $reloaded = $this->repository->findSimple($first->lidnr);
        self::assertNotNull($reloaded);
        self::assertSame(
            'Aangepast',
            $reloaded->lastName,
        );

        $address = $this->address(
            $reloaded,
            AddressTypes::Home,
        );
        $this->repository->persistAddress($address);
        self::assertNotNull($this->repository->findMemberAddress(
            $reloaded,
            AddressTypes::Home,
        ));

        $this->repository->removeAddress($address);
        self::assertNull($this->repository->findMemberAddress(
            $reloaded,
            AddressTypes::Home,
        ));

        $lidnr = $reloaded->lidnr;
        $this->repository->remove($reloaded);
        self::assertNull($this->entityManager->find(
            Member::class,
            $lidnr,
        ));
    }

    /**
     * @return list<int>
     */
    private function overviewLidnrs(MemberFilter $filter): array
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

    private function freshMember(string $lastName): Member
    {
        $member = new Member();
        $member->initials = 'T.';
        $member->firstName = 'Test';
        $member->middleName = '';
        $member->lastName = $lastName;
        $member->setEmail('nog-niet-gezet@example.org');
        $member->changedOn = new DateTimeImmutable();
        $member->setBirth(new DateTimeImmutable('2000-01-01'));
        $member->addMembership(new Membership(
            $member,
            MembershipTypes::Ordinary,
            new DateTimeImmutable('-1 month'),
            new DateTimeImmutable('+11 months'),
        ));

        return $member;
    }

    private function address(
        Member $member,
        AddressTypes $type,
    ): Address {
        $address = new Address();
        $address->setMember($member);
        $address->type = $type;
        $address->country = PostalRegions::Netherlands;
        $address->street = 'Teststraat';
        $address->number = '1';
        $address->postalCode = '5600 AA';
        $address->city = 'Eindhoven';
        $address->phone = '1';

        return $address;
    }
}
