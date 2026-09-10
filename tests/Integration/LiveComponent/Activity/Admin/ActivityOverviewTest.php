<?php

declare(strict_types=1);

namespace App\Tests\Integration\LiveComponent\Activity\Admin;

use App\Entity\User\User;
use App\Tests\Integration\DatabaseTestCase;
use App\Twig\Components\Activity\Admin\ActivityOverview;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Twig\Environment;

use function array_intersect;
use function array_map;
use function str_contains;

/**
 * The only overview with two paginated tables, which is what the paging of a named table is for. The seed has forty
 * approved activities and five that are not, so the approved table has four pages at the smallest size and the
 * pending table has one.
 */
final class ActivityOverviewTest extends DatabaseTestCase
{
    public function testEachTablePagesOnItsOwn(): void
    {
        $this->authenticate();
        $overview = $this->overview();
        $firstPage = $this->approvedIds($overview);

        $overview->gotoPage(2);

        self::assertSame(
            2,
            $overview->page,
        );
        self::assertEmpty(array_intersect(
            $firstPage,
            $this->approvedIds($overview),
        ));
        self::assertSame(
            1,
            $overview->getPageOf(ActivityOverview::PENDING),
        );
        self::assertNotEmpty($overview->getPendingRows());
    }

    /**
     * The pending table has one page, so a page number from a crafted URL renders that page rather than an empty
     * table with no control to get back out of it.
     */
    public function testAPendingPageThatDoesNotExistLandsOnTheLastOne(): void
    {
        $this->authenticate();
        $overview = $this->overview();

        $overview->gotoPage(
            99,
            ActivityOverview::PENDING,
        );

        self::assertNotEmpty($overview->getPendingRows());
        self::assertSame(
            1,
            $overview->getPageOf(ActivityOverview::PENDING),
        );
    }

    /**
     * The seed has enough approved activities for four pages, so the controls that render are the approved
     * table's: the shared action, with no table named.
     */
    public function testTheApprovedTablePagesThroughTheSharedAction(): void
    {
        $this->authenticate();

        $html = $this->render();

        self::assertTrue(str_contains(
            $html,
            'data-live-action-param="gotoPage"',
        ));
        self::assertFalse(str_contains(
            $html,
            'data-live-table-param',
        ));
    }

    /**
     * What the controls of a named table send. The seed has too few pending activities for the pending table to
     * render any of its own.
     */
    public function testTheControlsOfAnotherTableNameIt(): void
    {
        $html = self::getContainer()->get(Environment::class)
            ->render(
                'partials/application/pagination.html.twig',
                [
                    'page' => 1,
                    'totalPages' => 3,
                    'totalCount' => 30,
                    'pageSize' => 10,
                    'table' => ActivityOverview::PENDING,
                ],
            );

        self::assertTrue(str_contains(
            $html,
            'data-live-action-param="gotoPage"',
        ));
        self::assertTrue(str_contains(
            $html,
            'data-live-table-param="pending"',
        ));
    }

    /**
     * @return int[]
     */
    private function approvedIds(ActivityOverview $overview): array
    {
        return array_map(
            static fn (object $row): int => $row->id,
            $overview->getApprovedRows(),
        );
    }

    private function authenticate(int $lidnr = 8025): void
    {
        $user = $this->entityManager->getRepository(User::class)->find($lidnr);
        self::assertInstanceOf(
            User::class,
            $user,
        );

        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken(
            $user,
            'main',
            ['ROLE_BOARD'],
        ));
    }

    private function overview(): ActivityOverview
    {
        $overview = self::getContainer()->get(ActivityOverview::class);
        $overview->showAll = true;

        return $overview;
    }

    /**
     * The component as the page draws it, so the template runs against the real component.
     */
    private function render(): string
    {
        // The dates are written in the request's locale, so there has to be a request, and the confirmation modals
        // ask for a CSRF token, which is stored on its session.
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get('request_stack')->push($request);

        // Through a template rather than the renderer, which wants a Twig template on the stack for the live id.
        return self::getContainer()->get(Environment::class)
            ->createTemplate('{{ component(\'Activity:Admin:ActivityOverview\', props) }}')
            ->render([
                'props' => [
                    'showAll' => true,
                    'expanded' => true,
                ],
            ]);
    }
}
