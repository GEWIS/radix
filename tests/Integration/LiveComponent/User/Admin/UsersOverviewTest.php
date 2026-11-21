<?php

declare(strict_types=1);

namespace App\Tests\Integration\LiveComponent\User\Admin;

use App\Entity\Database\Enums\MembershipTypes;
use App\Entity\User\User;
use App\Tests\Integration\DatabaseTestCase;
use App\Twig\Components\User\Admin\UsersOverview;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function array_map;
use function count;
use function rsort;
use function sort;

/**
 * The member administration: which members a page holds once the search, the flags and the sort have been applied,
 * and what the table offers to sort by.
 */
final class UsersOverviewTest extends DatabaseTestCase
{
    public function testAPageOfMembersArrivesWithTheirTypesAndUser(): void
    {
        $overview = $this->overview();
        $rows = $overview->getMembers();

        self::assertNotEmpty($rows);
        self::assertLessThanOrEqual(
            10,
            count($rows),
        );
        self::assertLessThanOrEqual(
            $overview->getTotalCount(),
            count($rows),
        );

        // The default sort is by membership number, ascending.
        $lidnrs = [];
        foreach ($rows as $row) {
            $lidnrs[] = $row->lidnr;
        }

        $sorted = $lidnrs;
        sort($sorted);
        self::assertSame(
            $sorted,
            $lidnrs,
        );
    }

    public function testAskingForTheOtherDirectionReversesTheSameColumn(): void
    {
        $overview = $this->overview();
        $ascending = array_map(
            static fn ($row): int => $row->lidnr,
            $overview->getMembers(),
        );

        $overview->toggleSort('lidnr');
        self::assertSame(
            'desc',
            $overview->direction,
        );

        $descending = array_map(
            static fn ($row): int => $row->lidnr,
            $overview->getMembers(),
        );

        $reversed = $descending;
        rsort($reversed);
        self::assertSame(
            $reversed,
            $descending,
        );
        self::assertGreaterThan(
            $ascending[0],
            $descending[0],
            'The same column, read from the other end.',
        );
    }

    public function testANewColumnStartsAscendingOnTheFirstPage(): void
    {
        $overview = $this->overview();
        $overview->gotoPage(2);

        $overview->toggleSort('name');

        self::assertSame(
            'name',
            $overview->sort,
        );
        self::assertSame(
            'asc',
            $overview->direction,
        );
        self::assertSame(
            1,
            $overview->page,
        );
    }

    public function testAColumnThatIsNotOfferedIsIgnored(): void
    {
        $overview = $this->overview();

        $overview->toggleSort('password');

        self::assertSame(
            'lidnr',
            $overview->sort,
        );
        self::assertSame(
            [
                'lidnr',
                'name',
                'type',
                'expiration',
            ],
            $overview->getAllowedSorts(),
        );
    }

    public function testAColumnThatIsNotOnesIsSortedByMembershipNumberInstead(): void
    {
        $overview = $this->overview();
        $overview->sort = 'nonsense';

        $rows = $overview->getMembers();

        self::assertNotEmpty($rows);
        $lidnrs = array_map(
            static fn ($row): int => $row->lidnr,
            $rows,
        );
        $sorted = $lidnrs;
        sort($sorted);
        self::assertSame(
            $sorted,
            $lidnrs,
        );
    }

    public function testEachFlagNarrowsTheListToWhatItNames(): void
    {
        $flags = [
            'hiddenOnly' => 'hidden',
            'deletedOnly' => 'deleted',
            'expiredOnly' => 'expired',
            'activatedOnly' => 'isActivated',
            'mfaOnly' => 'mfaEnabled',
        ];

        foreach ($flags as $flag => $property) {
            $overview = $this->overview();
            $overview->{$flag} = true;

            $rows = $overview->getMembers();

            self::assertLessThanOrEqual(
                $overview->getTotalCount(),
                count($rows),
            );
            foreach ($rows as $row) {
                self::assertTrue(
                    $row->{$property},
                    $flag . ' shows only the members it names.',
                );
            }
        }
    }

    public function testATypeFilterNarrowsToThatKindOfMembership(): void
    {
        $overview = $this->overview();
        $overview->typeFilter = MembershipTypes::Ordinary->value;

        $rows = $overview->getMembers();

        self::assertNotEmpty($rows);
        foreach ($rows as $row) {
            self::assertSame(
                MembershipTypes::Ordinary,
                $row->type,
            );
        }
    }

    public function testATypeThatDoesNotExistFiltersNothing(): void
    {
        $unfiltered = $this->overview();
        $filtered = $this->overview();
        $filtered->typeFilter = 'nonsense';

        self::assertSame(
            $unfiltered->getTotalCount(),
            $filtered->getTotalCount(),
        );
    }

    public function testSearchingFindsAMemberByName(): void
    {
        $overview = $this->overview();
        $row = $overview->getMembers()[0] ?? null;
        self::assertNotNull($row);

        $searching = $this->overview();
        $searching->search = $row->fullName;

        $found = array_map(
            static fn ($found): int => $found->lidnr,
            $searching->getMembers(),
        );

        self::assertContains(
            $row->lidnr,
            $found,
        );
    }

    public function testTheFilterOffersEveryKindOfMembership(): void
    {
        self::assertSame(
            MembershipTypes::cases(),
            $this->overview()->getMembershipTypes(),
        );
    }

    private function overview(): UsersOverview
    {
        $user = $this->entityManager->getRepository(User::class)->find(8000);
        self::assertInstanceOf(
            User::class,
            $user,
        );
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken(
            $user,
            'main',
            ['ROLE_ADMIN'],
        ));

        return self::getContainer()->get(UsersOverview::class);
    }
}
