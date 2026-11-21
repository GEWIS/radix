<?php

declare(strict_types=1);

namespace App\Tests\Integration\LiveComponent\User\Admin;

use App\Entity\User\User;
use App\Tests\Integration\DatabaseTestCase;
use App\Twig\Components\User\Admin\CompanyUsersOverview;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function array_map;
use function array_reverse;
use function count;
use function rsort;

/**
 * The company accounts: the representative's name, the company they act for and whether they have enrolled a second
 * factor. Sorted by any of those, and searched by whatever the table shows.
 */
final class CompanyUsersOverviewTest extends DatabaseTestCase
{
    public function testTheAccountsAreListedWithTheirCompany(): void
    {
        $overview = $this->overview();
        $rows = $overview->getCompanyUsers();

        self::assertNotEmpty($rows);
        self::assertLessThanOrEqual(
            $overview->getTotalCount(),
            count($rows),
        );
        foreach ($rows as $row) {
            self::assertNotSame(
                '',
                $row->companyName,
            );
        }
    }

    public function testAskingForTheOtherDirectionReversesTheSameColumn(): void
    {
        $overview = $this->overview();
        $ascending = array_map(
            static fn ($row): string => $row->companyName,
            $overview->getCompanyUsers(),
        );

        $overview->toggleSort('company');

        self::assertSame(
            'desc',
            $overview->direction,
        );
        $descending = array_map(
            static fn ($row): string => $row->companyName,
            $overview->getCompanyUsers(),
        );

        $sorted = $descending;
        rsort($sorted);
        self::assertSame(
            $sorted,
            $descending,
        );
        self::assertSame(
            array_reverse($ascending),
            $descending,
            'The same column, read from the other end.',
        );
    }

    public function testANewColumnStartsAscendingOnTheFirstPage(): void
    {
        $overview = $this->overview();
        $overview->gotoPage(2);

        $overview->toggleSort('mfa');

        self::assertSame(
            'mfa',
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
            'company',
            $overview->sort,
        );
    }

    public function testAColumnThatIsNotOnesIsSortedByCompanyInstead(): void
    {
        $overview = $this->overview();
        $overview->sort = 'nonsense';

        self::assertNotEmpty($overview->getCompanyUsers());
        self::assertLessThanOrEqual(
            $overview->getTotalCount(),
            count($overview->getCompanyUsers()),
        );
    }

    public function testSearchingFindsTheRepresentativeByAddress(): void
    {
        $any = $this->overview()->getCompanyUsers()[0] ?? null;
        self::assertNotNull($any);

        $searching = $this->overview();
        $searching->search = $any->representativeEmail;

        $found = array_map(
            static fn ($found): int => $found->id,
            $searching->getCompanyUsers(),
        );

        self::assertContains(
            $any->id,
            $found,
        );
    }

    private function overview(): CompanyUsersOverview
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

        return self::getContainer()->get(CompanyUsersOverview::class);
    }
}
