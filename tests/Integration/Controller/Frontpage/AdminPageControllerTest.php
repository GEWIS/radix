<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Frontpage;

use App\Controller\Frontpage\AdminPageController;
use App\Entity\Application\Enums\Languages;
use App\Entity\Frontpage\Page;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Repository\Frontpage\PageRepository;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function count;
use function imagecolorallocate;
use function imagecreatetruecolor;
use function imagefilledrectangle;
use function imagejpeg;
use function json_decode;
use function str_contains;
use function strval;
use function sys_get_temp_dir;
use function tempnam;

use const JSON_THROW_ON_ERROR;

/**
 * A custom page is addressed by its own words and its content is rendered as-is, which makes two things worth pinning:
 * that no two pages can answer to the same address, and that whatever is written into one has been through the
 * sanitizer before it is stored.
 *
 * The actions are invoked directly with the current user on the token storage, as the other admin tests do and for
 * the same reason: the session guard force-logs-out any session with no managed-session row behind it.
 */
final class AdminPageControllerTest extends DatabaseTestCase
{
    public function testAPageIsWrittenChangedAndTakenDown(): void
    {
        $before = count($this->repository()->findAll());

        $this->write();

        self::assertCount(
            $before + 1,
            $this->repository()->findAll(),
        );

        $page = $this->written();
        self::assertSame(
            UserRoles::User,
            $page->getRequiredRole(),
        );

        $this->revise(
            $page,
            ['title' => 'A changed page'],
        );
        self::assertSame(
            'A changed page',
            $page->getTitle()->getValueEN(),
        );

        $this->controller()->delete($page);
        self::assertCount(
            $before,
            $this->repository()->findAll(),
        );
    }

    /**
     * Whatever the editor sends, what is stored has been through the sanitizer. This is the whole point of saving
     * through the service rather than straight from the form.
     */
    public function testWhatIsStoredHasBeenSanitised(): void
    {
        $this->write(['content' => '<p onclick="alert(1)">Hello</p><script>alert(2)</script>']);

        $stored = strval($this->written()->getContent()->getValueEN());

        self::assertStringNotContainsString(
            'script',
            $stored,
        );
        self::assertStringNotContainsString(
            'onclick',
            $stored,
        );
        self::assertStringContainsString(
            'Hello',
            $stored,
        );
    }

    public function testTwoPagesCannotAnswerToTheSameAddress(): void
    {
        $this->write();
        $before = count($this->repository()->findAll());

        $response = $this->write(['title' => 'A second page']);

        self::assertCount(
            $before,
            $this->repository()->findAll(),
        );
        self::assertStringContainsString(
            'already answers to this address',
            strval($response->getContent()),
        );
    }

    /**
     * Editing a page has to leave it able to keep the address it already has, which naive uniqueness would refuse.
     */
    public function testAPageMayKeepItsOwnAddress(): void
    {
        $this->write();
        $page = $this->written();

        $this->revise(
            $page,
            ['title' => 'Still here'],
        );

        self::assertSame(
            'Still here',
            $page->getTitle()->getValueEN(),
        );
    }

    /**
     * A page under an address the application answers to itself would never be reached, since those routes are
     * matched before the custom-page catch-all sees the request.
     */
    public function testAnAddressTheWebsiteAlreadyUsesIsRefused(): void
    {
        $before = count($this->repository()->findAll());

        $response = $this->write([
            'category' => 'career',
            'subCategory' => '',
            'name' => '',
        ]);

        self::assertCount(
            $before,
            $this->repository()->findAll(),
        );
        self::assertStringContainsString(
            'already answers to this address',
            strval($response->getContent()),
        );
    }

    public function testAnUploadedImageComesBackAsAnAddress(): void
    {
        $request = new Request(
            request: ['_csrf_token' => 'csrf-token'],
            files: ['image' => $this->image()],
            server: ['HTTP_SEC_FETCH_SITE' => 'same-origin'],
        );
        $request->setMethod(Request::METHOD_POST);
        $this->authenticateAsBoard($request);

        $response = $this->controller()->upload($request);

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );

        $body = json_decode(
            strval($response->getContent()),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertMatchesRegularExpression(
            '#^/img/w1280/#',
            strval($body['url']),
        );
    }

    public function testAnUploadWithoutAnImageIsRefused(): void
    {
        $request = new Request(
            request: ['_csrf_token' => 'csrf-token'],
            server: ['HTTP_SEC_FETCH_SITE' => 'same-origin'],
        );
        $request->setMethod(Request::METHOD_POST);
        $this->authenticateAsBoard($request);

        self::assertSame(
            Response::HTTP_BAD_REQUEST,
            $this->controller()->upload($request)->getStatusCode(),
        );
    }

    private function controller(): AdminPageController
    {
        return self::getContainer()->get(AdminPageController::class);
    }

    private function repository(): PageRepository
    {
        return self::getContainer()->get(PageRepository::class);
    }

    private function written(): Page
    {
        $page = $this->repository()->findPage(
            Languages::English,
            'testing',
            'pages',
            'one',
        );
        self::assertInstanceOf(
            Page::class,
            $page,
        );

        return $page;
    }

    /**
     * Write a page the way the flow does: the address, then the content that finishes it. Both steps go through the
     * same session, which is where the flow keeps what has been filled in so far.
     *
     * @param array<string, string> $overrides
     */
    private function write(array $overrides = []): Response
    {
        $session = $this->session();

        $response = $this->controller()->create($this->step(
            $session,
            'address',
            $this->address($overrides),
            'next',
        ));

        if (!$this->reachedTheContentStep($response)) {
            return $response;
        }

        return $this->controller()->create($this->step(
            $session,
            'content',
            $this->content($overrides),
            'finish',
        ));
    }

    /**
     * @param array<string, string> $overrides
     */
    private function revise(
        Page $page,
        array $overrides = [],
    ): Response {
        $session = $this->session();

        $this->controller()->edit(
            $this->step(
                $session,
                'address',
                $this->address($overrides),
                'next',
            ),
            $page,
        );

        return $this->controller()->edit(
            $this->step(
                $session,
                'content',
                $this->content($overrides),
                'finish',
            ),
            $page,
        );
    }

    /**
     * A refused address step renders itself again rather than the content step, which is what says the flow never
     * got past it.
     */
    private function reachedTheContentStep(Response $response): bool
    {
        return str_contains(
            strval($response->getContent()),
            'page_flow[content]',
        );
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function address(array $overrides): array
    {
        $category = $overrides['category'] ?? 'testing';
        $subCategory = $overrides['subCategory'] ?? 'pages';
        $name = $overrides['name'] ?? 'one';

        return [
            'categoryNL' => $category,
            'categoryEN' => $category,
            'subCategoryNL' => $subCategory,
            'subCategoryEN' => $subCategory,
            'nameNL' => $name,
            'nameEN' => $name,
            'requiredRole' => UserRoles::User->value,
        ];
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function content(array $overrides): array
    {
        $title = $overrides['title'] ?? 'A page for testing';
        $content = $overrides['content'] ?? '<p>Hello</p>';

        return [
            'titleNL' => $title,
            'titleEN' => $title,
            'contentNL' => $content,
            'contentEN' => $content,
        ];
    }

    /**
     * @param array<string, string> $fields
     */
    private function step(
        FlashBagAwareSessionInterface $session,
        string $step,
        array $fields,
        string $button,
    ): Request {
        $request = new Request(
            // The run the flow is kept under, which the controller would otherwise mint and redirect to.
            query: ['flow' => 'testing'],
            request: [
                'page_flow' => [
                    $step => $fields,
                    $button => '',
                    '_csrf_token' => 'csrf-token',
                ],
            ],
            server: ['HTTP_SEC_FETCH_SITE' => 'same-origin'],
        );
        $request->setMethod(Request::METHOD_POST);
        $request->setSession($session);
        $this->authenticateAsBoard($request);

        return $request;
    }

    private function session(): FlashBagAwareSessionInterface
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        return $session;
    }

    private function authenticateAsBoard(Request $request): void
    {
        $user = $this->entityManager->getRepository(User::class)->find(8000);
        self::assertInstanceOf(
            User::class,
            $user,
        );

        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken(
            $user,
            'main',
            ['ROLE_BOARD'],
        ));

        if (!$request->hasSession()) {
            $request->setSession($this->session());
        }

        self::getContainer()->get('request_stack')->push($request);
    }

    private function image(): UploadedFile
    {
        $path = tempnam(
            sys_get_temp_dir(),
            'gewisweb-page-image',
        );
        self::assertIsString($path);

        $image = imagecreatetruecolor(
            64,
            64,
        );
        self::assertNotFalse($image);
        $colour = imagecolorallocate(
            $image,
            0x30,
            0x60,
            0x90,
        );
        self::assertNotFalse($colour);
        imagefilledrectangle(
            $image,
            0,
            0,
            64,
            64,
            $colour,
        );
        imagejpeg(
            $image,
            $path,
        );

        return new UploadedFile(
            $path,
            'page.jpg',
            'image/jpeg',
            null,
            true,
        );
    }
}
