<?php

declare(strict_types=1);

namespace App\Tests\Integration\LiveComponent\Decision\Admin;

use App\Entity\User\User;
use App\Repository\Decision\OrganRepository;
use App\Tests\Integration\DatabaseTestCase;
use App\Twig\Components\Decision\Admin\BodyPageOverview;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function array_diff;
use function array_map;
use function array_values;
use function count;
use function sort;

/**
 * The list of bodies whose page a reader may write. A register administrator sees every body, the board the
 * installed ones, and a member the bodies they sit on.
 */
final class BodyPageOverviewTest extends DatabaseTestCase
{
    public function testTheRegisterAdministratorSeesEverythingIncludingWhatIsGone(): void
    {
        $overview = $this->overviewAs(
            8000,
            ['ROLE_DATABASE_ADMIN'],
        );
        $organs = self::getContainer()->get(OrganRepository::class);
        $all = $organs->findAll();

        $overview->pageSize = 100;
        $overview->onPageSizeUpdated();

        self::assertSame(
            count($all),
            $overview->getTotalCount(),
        );
        self::assertCount(
            count($all),
            $overview->getBodies(),
            'A hundred bodies is the whole register of the seed.',
        );
    }

    public function testTheBoardSeesTheInstalledBodies(): void
    {
        $overview = $this->overviewAs(
            8025,
            ['ROLE_BOARD'],
        );
        $active = self::getContainer()->get(OrganRepository::class)->findActive();

        $overview->pageSize = 100;
        $overview->onPageSizeUpdated();

        self::assertSame(
            count($active),
            $overview->getTotalCount(),
        );
        foreach ($overview->getBodies() as $row) {
            self::assertNull($row->organ->abrogationDate);
        }
    }

    public function testAMemberSeesTheBodiesTheySitOnInAlphabeticalOrder(): void
    {
        $lidnr = $this->memberWithInstallations();
        $user = $this->user($lidnr);
        $abbrs = array_map(
            static fn ($installation): string => $installation->organ->abbr,
            $user->member->getCurrentOrganInstallations()->toArray(),
        );

        $overview = $this->overviewAs(
            $lidnr,
            ['ROLE_ACTIVE_MEMBER'],
        );
        $listed = array_map(
            static fn ($row): string => $row->organ->abbr,
            $overview->getBodies(),
        );

        self::assertCount(
            $overview->getTotalCount(),
            $listed,
        );
        self::assertSame(
            [],
            array_values(array_diff($listed, $abbrs)),
            'A member administers the pages of the bodies they sit on and no others.',
        );
        $sorted = $listed;
        sort($sorted);
        self::assertSame(
            $sorted,
            $listed,
        );
    }

    public function testAPageBeyondTheLastOneLandsOnTheLastOne(): void
    {
        $overview = $this->overviewAs(
            8000,
            ['ROLE_DATABASE_ADMIN'],
        );

        $overview->gotoPage(999);

        self::assertSame(
            $overview->getTotalPages(),
            $overview->page,
        );
        self::assertNotEmpty($overview->getBodies());
    }

    /**
     * The seed gives every body member an installation, but not necessarily a current one; the first member with one
     * is the reader here.
     */
    private function memberWithInstallations(): int
    {
        foreach ($this->entityManager->getRepository(User::class)->findAll() as $user) {
            if ([] !== $user->member->getCurrentOrganInstallations()) {
                return $user->lidnr;
            }
        }

        self::fail('The seed has no member with a current installation.');
    }

    /**
     * @param list<string> $roles
     */
    private function overviewAs(
        int $lidnr,
        array $roles,
    ): BodyPageOverview {
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken(
            $this->user($lidnr),
            'main',
            $roles,
        ));

        return self::getContainer()->get(BodyPageOverview::class);
    }

    private function user(int $lidnr): User
    {
        $user = $this->entityManager->getRepository(User::class)->find($lidnr);
        self::assertInstanceOf(
            User::class,
            $user,
        );

        return $user;
    }
}
