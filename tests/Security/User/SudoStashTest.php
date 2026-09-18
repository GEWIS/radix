<?php

declare(strict_types=1);

namespace App\Tests\Security\User;

use App\Controller\Frontpage\AdminPageController;
use App\Security\User\SudoStash;
use App\Service\Application\FileStorage;
use App\Tests\Support\BuildsSudoMode;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * What is kept of a write that was refused for want of a sudo grant, and what is deliberately not.
 */
final class SudoStashTest extends TestCase
{
    use BuildsSudoMode;

    public function testAWriteFromThisSiteIsKeptAndReadBack(): void
    {
        $stash = $this->stash();

        $id = $stash->stash($this->write(['title' => 'Half a page']));

        self::assertNotNull($id);

        $write = $stash->read($id);

        self::assertNotNull($write);
        self::assertSame(
            'POST',
            $write->method,
        );
        self::assertSame(
            ['title' => 'Half a page'],
            $write->parameters,
        );
    }

    /**
     * A refused request has not had its CSRF token checked, because the grant is required before the controller runs.
     * Keeping one that another site caused the browser to make would re-run it with the user's own confirmation
     * behind it.
     */
    public function testAWriteFromAnotherSiteIsNotKept(): void
    {
        $request = $this->write(['title' => 'Half a page']);
        $request->headers->set(
            'Sec-Fetch-Site',
            'cross-site',
        );

        self::assertNull($this->stash()->stash($request));
    }

    public function testAWriteWithNoStatementOfWhereItCameFromIsNotKept(): void
    {
        $request = $this->write(['title' => 'Half a page']);
        $request->headers->remove('Sec-Fetch-Site');
        $request->headers->remove('Origin');

        self::assertNull($this->stash()->stash($request));
    }

    /**
     * A heartbeat arrives every interval for as long as a tab is open, and has no body worth keeping.
     */
    public function testAKeepaliveIsNotKept(): void
    {
        $request = $this->write([]);
        $request->attributes->set(
            '_route',
            'admin/activities/edit_ping',
        );

        self::assertNull($this->stash()->stash($request));
    }

    public function testAReadIsOnlyASafeMethodAway(): void
    {
        $request = $this->write(
            [],
            'GET',
        );

        self::assertNull($this->stash()->stash($request));
    }

    public function testCredentialsAreNotKept(): void
    {
        $stash = $this->stash();

        $id = $stash->stash($this->write([
            'form' => [
                'title' => 'Half a page',
                'password' => 'hunter2',
                'plainPassword' => 'hunter2',
                'mfaCode' => '123456',
                'secret' => 'a shared secret',
            ],
            '_csrf_token' => 'a-token-that-is-long-enough',
        ]));

        self::assertNotNull($id);

        $write = $stash->read($id);

        self::assertNotNull($write);
        self::assertSame(
            [
                'form' => ['title' => 'Half a page'],
                '_csrf_token' => 'a-token-that-is-long-enough',
            ],
            $write->parameters,
        );
    }

    public function testAnUploadIsWrittenToStorageAndPointedAt(): void
    {
        $storage = $this->storage();
        $stash = $this->stash($storage);

        $local = tempnam(
            sys_get_temp_dir(),
            'gws_test_',
        );
        self::assertIsString($local);
        file_put_contents(
            $local,
            'the bytes',
        );

        $request = $this->write(['form' => ['title' => 'Half a page']]);
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

        $id = $stash->stash($request);
        self::assertNotNull($id);

        $write = $stash->read($id);
        self::assertNotNull($write);

        $descriptor = $write->files['form']['attachment'] ?? null;
        self::assertIsArray($descriptor);
        self::assertSame(
            'notes.pdf',
            $descriptor['name'],
        );

        // The record states the position and not the path, so what is read back cannot address another namespace.
        self::assertArrayNotHasKey(
            'path',
            $descriptor,
        );

        $path = $stash->uploadPath(
            $id,
            $descriptor['index'],
        );
        self::assertIsString($path);
        self::assertSame(
            'the bytes',
            $storage->read($path),
        );

        // Acting on the stash removes both halves of it.
        $stash->discard($id);

        self::assertNull($stash->read($id));
        self::assertFalse($storage->exists($path));

        unlink($local);
    }

    /**
     * The bytes are read back only where the write is sent again, so an action that is not re-run is kept as its
     * fields alone. Being refused on one repeatedly would otherwise fill the storage with files nothing asks for.
     */
    public function testAnUploadIsNotKeptForAnActionThatIsNotSentAgain(): void
    {
        $storage = $this->storage();
        $stash = $this->stash($storage);

        $local = tempnam(
            sys_get_temp_dir(),
            'gws_test_',
        );
        self::assertIsString($local);
        file_put_contents(
            $local,
            'the bytes',
        );

        $request = $this->write(
            ['form' => ['title' => 'Half a page']],
            'POST',
            'delete',
        );
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

        $id = $stash->stash($request);
        self::assertNotNull($id);

        $write = $stash->read($id);
        self::assertNotNull($write);

        // The fields are still there: the prompt states that the write was kept and not sent.
        self::assertSame(
            ['form' => ['title' => 'Half a page']],
            $write->parameters,
        );
        self::assertSame(
            [],
            $write->files,
        );

        $path = $stash->uploadPath(
            $id,
            0,
        );
        self::assertIsString($path);
        self::assertFalse($storage->exists($path));

        unlink($local);
    }

    /**
     * The account is checked on the way out, so a session that a second account is later given cannot read what the
     * first one wrote.
     */
    public function testAnotherAccountOnTheSameSessionReadsNothing(): void
    {
        $session = $this->session();
        $storage = $this->storage();
        $valkey = $this->valkey();

        $id = $this->stash(
            $storage,
            $session,
            'first@gewis.nl',
            $valkey,
        )->stash($this->write(['title' => 'Half a page']));

        self::assertNotNull($id);

        self::assertNull(
            $this->stash(
                $storage,
                $session,
                'second@gewis.nl',
                $valkey,
            )->read($id),
        );
    }

    /**
     * The id reaches the stash from the address, so it is the one part of a key an attacker states. A stash
     * directory is named after it, and a discard removes that directory whole.
     */
    public function testAnIdThatIsAPathReachesNothing(): void
    {
        $storage = $this->storage();
        $stash = $this->stash($storage);

        $storage->write(
            'photos/albums/1/a-photo.jpg',
            'a stored photo',
        );

        foreach (
            [
                '../photos/albums/1',
                '../../photos/albums/1',
                'sudo-stash/../photos',
                '',
                'NOTHEX0123456789abcdef0123456789',
            ] as $id
        ) {
            self::assertNull($stash->read($id));
            self::assertNull(
                $stash->uploadPath(
                    $id,
                    0,
                ),
            );

            $stash->discard($id);
        }

        self::assertTrue($storage->exists('photos/albums/1/a-photo.jpg'));
    }

    public function testAnUploadIsOnlyEverAddressedInsideItsOwnStash(): void
    {
        $stash = $this->stash();

        $id = $stash->stash($this->write(['title' => 'Half a page']));
        self::assertNotNull($id);

        self::assertSame(
            'sudo-stash/' . $id . '/0',
            $stash->uploadPath(
                $id,
                0,
            ),
        );
        self::assertNull(
            $stash->uploadPath(
                $id,
                -1,
            ),
        );
    }

    private function stash(
        ?FileStorage $storage = null,
        mixed $session = null,
        string $identifier = 'first@gewis.nl',
        mixed $valkey = null,
    ): SudoStash {
        $session ??= $this->session();

        return $this->sudoStash(
            $session,
            $this->tokenStorage($identifier),
            $storage ?? $this->storage(),
            $valkey,
        );
    }

    private function storage(): FileStorage
    {
        return new FileStorage(new Filesystem(new InMemoryFilesystemAdapter()));
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    private function write(
        array $parameters,
        string $method = 'POST',
        string $action = 'edit',
    ): Request {
        $request = Request::create(
            '/en/admin/pages/1/' . $action,
            $method,
            $parameters,
        );
        $request->headers->set(
            'Sec-Fetch-Site',
            'same-origin',
        );

        // What the router names on a request it matched, which is what the stash reads to decide whether the
        // uploads are worth keeping. `edit` is marked as re-run and `delete` deliberately is not.
        $request->attributes->set(
            '_controller',
            AdminPageController::class . '::' . $action,
        );

        return $request;
    }
}
