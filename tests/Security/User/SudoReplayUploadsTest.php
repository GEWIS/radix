<?php

declare(strict_types=1);

namespace App\Tests\Security\User;

use App\Controller\Frontpage\AdminPageController;
use App\Security\User\ReplayableAction;
use App\Security\User\SudoArea;
use App\Security\User\SudoReplay;
use App\Security\User\SudoStash;
use App\Service\Application\FileStorage;
use App\Tests\Support\BuildsSudoMode;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\RouterInterface;

use function file_put_contents;
use function is_file;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * A returned form cannot include a file, so a refused write that is sent again restores its uploads from storage onto
 * the filesystem the action reads.
 */
final class SudoReplayUploadsTest extends KernelTestCase
{
    use BuildsSudoMode;

    private ?Request $handled = null;

    private ?string $handledUpload = null;

    public function testAnUploadIsHandedToTheReRunAsAFile(): void
    {
        self::bootKernel();

        $storage = $this->storage();
        $session = $this->session();
        $stash = $this->stash(
            $session,
            $storage,
        );

        $request = $this->writeWithUpload();
        $id = $stash->stash($request);
        self::assertNotNull($id);
        $this->removeUpload($request);

        $response = $this->replay(
            $stash,
            $session,
            $storage,
        )->replay($id);

        self::assertInstanceOf(
            Response::class,
            $response,
        );
        self::assertNotNull($this->handled);

        $upload = $this->handled->files->get('form')['attachment'] ?? null;
        self::assertInstanceOf(
            UploadedFile::class,
            $upload,
        );
        self::assertSame(
            'notes.pdf',
            $upload->getClientOriginalName(),
        );
        self::assertSame(
            'the bytes',
            $this->handledUpload,
        );

        // The bytes were copied out to a temporary file for the action to move, and that file is cleaned up after.
        $temporary = $upload->getPathname();
        self::assertFalse(is_file($temporary));
        self::assertNull($stash->read($id));
    }

    /**
     * A file that is no longer in storage is left out rather than failing the whole re-run: the rest of the form is
     * still worth sending.
     */
    public function testAnUploadThatIsNoLongerStoredIsLeftOut(): void
    {
        self::bootKernel();

        $storage = $this->storage();
        $session = $this->session();
        $stash = $this->stash(
            $session,
            $storage,
        );

        $request = $this->writeWithUpload();
        $id = $stash->stash($request);
        self::assertNotNull($id);
        $this->removeUpload($request);

        $write = $stash->read($id);
        self::assertNotNull($write);
        $index = $write->files['form']['attachment']['index'] ?? null;
        self::assertIsInt($index);

        $path = $stash->uploadPath(
            $id,
            $index,
        );
        self::assertIsString($path);
        $storage->delete($path);

        $response = $this->replay(
            $stash,
            $session,
            $storage,
        )->replay($id);

        self::assertInstanceOf(
            Response::class,
            $response,
        );
        self::assertNotNull($this->handled);
        self::assertSame(
            [],
            $this->handled->files->get('form'),
        );
    }

    /**
     * The write is not re-run when the kernel refuses it, and the stash is gone either way so a second confirmation
     * cannot try again.
     */
    public function testAFailureIsLoggedAndTheStashDiscarded(): void
    {
        self::bootKernel();

        $storage = $this->storage();
        $session = $this->session();
        $stash = $this->stash(
            $session,
            $storage,
        );

        $id = $stash->stash($this->write());
        self::assertNotNull($id);

        $log = new TestHandler();

        $replay = $this->replay(
            $stash,
            $session,
            $storage,
            kernel: $this->kernelThatFails(),
            logger: new Logger(
                'security',
                [$log],
            ),
        );

        self::assertNull($replay->replay($id));
        self::assertNull($stash->read($id));
        self::assertTrue($log->hasRecordThatContains('Could not re-run', Level::Error));
    }

    public function testWithoutACurrentRequestNothingIsReRun(): void
    {
        self::bootKernel();

        $storage = $this->storage();
        $session = $this->session();
        $stash = $this->stash(
            $session,
            $storage,
        );

        $id = $stash->stash($this->write());
        self::assertNotNull($id);

        $replay = $this->replay(
            $stash,
            $session,
            $storage,
            requestStack: new RequestStack(),
        );

        self::assertNull($replay->replay($id));
        self::assertNotNull($stash->read($id));
    }

    private function stash(
        SessionInterface $session,
        FileStorage $storage,
    ): SudoStash {
        return $this->sudoStash(
            $session,
            $this->tokenStorage('first@gewis.nl'),
            $storage,
        );
    }

    private function replay(
        SudoStash $stash,
        SessionInterface $session,
        FileStorage $storage,
        ?HttpKernelInterface $kernel = null,
        ?RequestStack $requestStack = null,
        ?Logger $logger = null,
    ): SudoReplay {
        if (null === $requestStack) {
            $current = Request::create('/en/user/security/sudo');
            $current->setSession($session);
            $current->headers->set(
                'Sec-Fetch-Site',
                'same-origin',
            );

            $requestStack = new RequestStack();
            $requestStack->push($current);
        }

        $kernel ??= $this->kernelThatHandles();

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
            $storage,
            $kernel,
            $logger ?? new Logger('security'),
        );
    }

    private function kernelThatHandles(): HttpKernelInterface
    {
        $kernel = self::createStub(HttpKernelInterface::class);
        $kernel->method('handle')->willReturnCallback(
            function (Request $request): Response {
                $this->handled = $request;

                $upload = $request->files->get('form')['attachment'] ?? null;
                if ($upload instanceof UploadedFile) {
                    $this->handledUpload = $upload->getContent();
                }

                return new Response('re-run');
            },
        );

        return $kernel;
    }

    private function kernelThatFails(): HttpKernelInterface
    {
        $kernel = self::createStub(HttpKernelInterface::class);
        $kernel->method('handle')->willThrowException(new RuntimeException('the action failed'));

        return $kernel;
    }

    private function write(): Request
    {
        $request = Request::create(
            '/en/admin/pages/create',
            'POST',
            ['title' => 'Half a page'],
        );
        $request->headers->set(
            'Sec-Fetch-Site',
            'same-origin',
        );
        $request->attributes->set(
            '_controller',
            AdminPageController::class . '::create',
        );

        return $request;
    }

    private function writeWithUpload(): Request
    {
        $local = tempnam(
            sys_get_temp_dir(),
            'gws_test_',
        );
        self::assertIsString($local);
        file_put_contents(
            $local,
            'the bytes',
        );

        $request = $this->write();
        $request->files->set(
            'form',
            [
                'attachment' => new UploadedFile(
                    $local,
                    'notes.pdf',
                    'application/pdf',
                    null,
                    true,
                ),
            ],
        );

        return $request;
    }

    private function removeUpload(Request $request): void
    {
        $upload = $request->files->get('form')['attachment'] ?? null;
        self::assertInstanceOf(
            UploadedFile::class,
            $upload,
        );

        if (!is_file($upload->getPathname())) {
            return;
        }

        unlink($upload->getPathname());
    }

    private function storage(): FileStorage
    {
        return new FileStorage(new Filesystem(new InMemoryFilesystemAdapter()));
    }
}
