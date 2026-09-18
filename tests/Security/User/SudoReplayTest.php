<?php

declare(strict_types=1);

namespace App\Tests\Security\User;

use App\Attribute\User\Replayable;
use App\Controller\User\CompanyUserController;
use App\Controller\User\UserController;
use App\Security\User\RefusedWrite;
use App\Security\User\ReplayableAction;
use App\Security\User\SudoArea;
use App\Security\User\SudoReplay;
use App\Security\User\SudoStash;
use App\Service\Application\FileStorage;
use App\Tests\Support\BuildsSudoMode;
use App\Util\Application\ControllerAttribute;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Which refused writes are re-run once the grant is given, and what the re-run request is built from.
 *
 * The kernel is stood in for, so what is asserted is the request that would have been handled rather than the effect
 * of handling it.
 */
final class SudoReplayTest extends KernelTestCase
{
    use BuildsSudoMode;

    private ?Request $handled = null;

    private ?int $handledAs = null;

    public function testAWriteToAMarkedActionIsReRun(): void
    {
        self::bootKernel();

        $session = $this->session();
        $stash = $this->stash($session);

        $id = $stash->stash($this->write('/en/admin/pages/create'));
        self::assertNotNull($id);

        $response = $this->replay(
            $stash,
            $session,
        )->replay($id);

        self::assertInstanceOf(
            Response::class,
            $response,
        );
        self::assertNotNull($this->handled);
        self::assertSame(
            '/en/admin/pages/create',
            $this->handled->getPathInfo(),
        );
        self::assertSame(
            'Half a page',
            $this->handled->request->get('title'),
        );

        // The statement that the request came from this site is what the CSRF token manager reads, and it reads it
        // from the request being handled rather than from the one it is a part of.
        self::assertSame(
            'same-origin',
            $this->handled->headers->get('Sec-Fetch-Site'),
        );

        // Handled as a main request, because the listeners that refuse a write stand down for a sub-request: the
        // read-only maintenance window and the firewall's `access_control` both test `isMainRequest()`.
        self::assertSame(
            HttpKernelInterface::MAIN_REQUEST,
            $this->handledAs,
        );

        // Acting on the stash removes it, so a second confirmation cannot run the same write again.
        self::assertNull($stash->read($id));
    }

    /**
     * Re-running a write that was not marked for it is what the attribute exists to prevent, so an action without
     * one reaches the kernel not at all.
     */
    public function testAWriteToAnActionThatIsNotMarkedIsNotReRun(): void
    {
        self::bootKernel();

        $session = $this->session();
        $stash = $this->stash($session);

        $id = $stash->stash($this->write('/en/admin/pages/1/delete'));
        self::assertNotNull($id);

        self::assertNull(
            $this->replay(
                $stash,
                $session,
            )->replay($id),
        );
        self::assertNull($this->handled);

        // The write is still there, because the user is returned to the page and it is what fills the form again.
        self::assertNotNull($stash->read($id));
    }

    public function testAnIdThatNamesNothingReRunsNothing(): void
    {
        self::bootKernel();

        $session = $this->session();
        $stash = $this->stash($session);

        self::assertNull(
            $this->replay(
                $stash,
                $session,
            )->replay('0123456789abcdef0123456789abcdef'),
        );
        self::assertNull($this->handled);
    }

    /**
     * What the prompt states before the user presses anything, which is the difference between a confirmation that
     * completes their submission and one that returns them to it.
     */
    public function testThePromptStatesWhatConfirmingWillDo(): void
    {
        self::bootKernel();

        $session = $this->session();
        $stash = $this->stash($session);
        $replay = $this->replay(
            $stash,
            $session,
        );

        $marked = $stash->stash($this->write('/en/admin/pages/create'));
        self::assertNotNull($marked);
        self::assertSame(
            RefusedWrite::SentOnConfirmation,
            $replay->outcomeFor($marked),
        );

        $unmarked = $stash->stash($this->write('/en/admin/pages/1/delete'));
        self::assertNotNull($unmarked);
        self::assertSame(
            RefusedWrite::SubmittedByHand,
            $replay->outcomeFor($unmarked),
        );

        // Reaching the prompt without having submitted anything, which is every arrival at a guarded page.
        self::assertSame(
            RefusedWrite::None,
            $replay->outcomeFor(''),
        );
        self::assertSame(
            RefusedWrite::None,
            $replay->outcomeFor('0123456789abcdef0123456789abcdef'),
        );
    }

    /**
     * The credential fields are dropped on the way into the stash, so an action that needs one cannot be re-run from
     * it: it would be handed an empty password and refuse, which is worse than asking for the form again. These are
     * the actions that would be, and none of them is marked.
     *
     * @param class-string $controller
     */
    #[DataProvider('actionsThatTakeCredentials')]
    public function testAnActionThatTakesACredentialIsNotMarked(
        string $controller,
        string $method,
    ): void {
        self::assertFalse(
            ControllerAttribute::isPresent(
                $controller . '::' . $method,
                Replayable::class,
            ),
        );
    }

    /**
     * @return iterable<string, array{class-string, string}>
     */
    public static function actionsThatTakeCredentials(): iterable
    {
        yield 'changing a password' => [
            UserController::class,
            'security',
        ];

        yield 'changing a company password' => [
            CompanyUserController::class,
            'security',
        ];

        yield 'enrolling a second factor' => [
            UserController::class,
            'mfaEnable',
        ];

        yield 'dropping a second factor' => [
            UserController::class,
            'mfaDisable',
        ];

        yield 'replacing the backup codes' => [
            UserController::class,
            'mfaRegenerateBackupCodes',
        ];

        yield 'confirming sudo itself' => [
            UserController::class,
            'confirmSudo',
        ];
    }

    private function stash(SessionInterface $session): SudoStash
    {
        return $this->sudoStash(
            $session,
            $this->tokenStorage('first@gewis.nl'),
        );
    }

    private function replay(
        SudoStash $stash,
        SessionInterface $session,
    ): SudoReplay {
        $current = Request::create('/en/user/security/sudo');
        $current->setSession($session);
        $current->headers->set(
            'Sec-Fetch-Site',
            'same-origin',
        );

        $requestStack = new RequestStack();
        $requestStack->push($current);

        $kernel = self::createStub(HttpKernelInterface::class);
        $kernel->method('handle')->willReturnCallback(
            function (
                Request $request,
                int $type,
            ): Response {
                $this->handled = $request;
                $this->handledAs = $type;

                return new Response('re-run');
            },
        );

        $container = self::getContainer();
        $router = $container->get(RouterInterface::class);
        self::assertInstanceOf(
            RouterInterface::class,
            $router,
        );

        $area = $container->get(SudoArea::class);
        self::assertInstanceOf(
            SudoArea::class,
            $area,
        );

        return new SudoReplay(
            $stash,
            $area,
            new ReplayableAction($router),
            $requestStack,
            new FileStorage(new Filesystem(new InMemoryFilesystemAdapter())),
            $kernel,
            new NullLogger(),
        );
    }

    private function write(string $uri): Request
    {
        $request = Request::create(
            $uri,
            'POST',
            ['title' => 'Half a page'],
        );
        $request->headers->set(
            'Sec-Fetch-Site',
            'same-origin',
        );

        return $request;
    }
}
