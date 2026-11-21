<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Frontpage;

use App\Controller\Frontpage\PageController;
use App\Entity\Application\Enums\Languages;
use App\Entity\Frontpage\FrontpageLocalisedText;
use App\Entity\Frontpage\Page;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Repository\Frontpage\PageRepository;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function strval;

/**
 * A custom page is served at the address it was written under, and only the people its role allows may read it.
 *
 * The seeded pages are the association's own, written before any admin screen existed, so they double as proof that
 * the address written by hand in the old layout is still reachable.
 */
final class PageControllerTest extends DatabaseTestCase
{
    public function testAPageIsServedAtItsAddress(): void
    {
        $this->request();

        $response = $this->controller()->index('association');

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertMatchesRegularExpression(
            '/About the Association|Over de Vereniging/',
            strval($response->getContent()),
        );
    }

    public function testAPageUnderASubCategoryIsServed(): void
    {
        $this->request();

        $content = strval($this->controller()->index('association', 'board')->getContent());

        self::assertMatchesRegularExpression(
            '/Board Members|Bestuursleden/',
            $content,
        );
        // The category root is the parent of this page and is shown above it.
        self::assertMatchesRegularExpression(
            '/About the Association|Over de Vereniging/',
            $content,
        );
    }

    /**
     * Addresses written before the three-segment form existed put the second word in the name position. A single
     * extra segment is read as the sub-category, not as a page that does not exist.
     */
    public function testATwoSegmentAddressIsReadAsASubCategory(): void
    {
        $page = new Page();
        $page->category = new FrontpageLocalisedText(
            'association',
            'vereniging',
        );
        $page->subCategory = new FrontpageLocalisedText(
            null,
            null,
        );
        $page->name = new FrontpageLocalisedText(
            'newsletter',
            'nieuwsbrief',
        );
        $page->title = new FrontpageLocalisedText(
            'The newsletter',
            'De nieuwsbrief',
        );
        $page->content = new FrontpageLocalisedText(
            '<p>News</p>',
            '<p>Nieuws</p>',
        );
        $page->requiredRole = UserRoles::Guest;
        $this->entityManager->persist($page);
        $this->entityManager->flush();
        $this->request();

        $response = $this->controller()->index(
            'association',
            'newsletter',
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'newsletter',
            strval($response->getContent()),
        );
    }

    public function testAnAddressWithNothingBehindItIsNotFound(): void
    {
        $this->request();

        $this->expectException(NotFoundHttpException::class);

        $this->controller()->index(
            'association',
            'does-not-exist',
        );
    }

    /**
     * The role on the page is the page's own gate: there is no route-level check behind it.
     */
    public function testAPageAboveTheReadersRoleIsRefused(): void
    {
        $page = $this->page('association');
        $page->requiredRole = UserRoles::User;
        $this->entityManager->flush();
        $this->request();

        $this->expectException(AccessDeniedException::class);

        $this->controller()->index('association');
    }

    public function testAMemberMayReadAPageThatRequiredAMember(): void
    {
        $page = $this->page('association');
        $page->requiredRole = UserRoles::User;
        $this->entityManager->flush();
        $this->request();

        $user = $this->entityManager->getRepository(User::class)->find(8030);
        self::assertInstanceOf(
            User::class,
            $user,
        );
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken(
            $user,
            'main',
            [UserRoles::Member->value],
        ));

        self::assertSame(
            Response::HTTP_OK,
            $this->controller()->index('association')->getStatusCode(),
        );
    }

    private function controller(): PageController
    {
        return self::getContainer()->get(PageController::class);
    }

    private function page(string $category): Page
    {
        $page = self::getContainer()->get(PageRepository::class)->findPage(
            Languages::English,
            $category,
            null,
            null,
        );
        self::assertInstanceOf(
            Page::class,
            $page,
        );

        return $page;
    }

    private function request(): void
    {
        $request = Request::create('/en/association');
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);
    }
}
