<?php

declare(strict_types=1);

namespace App\Tests\Security\User;

use App\Security\User\SudoStash;
use App\Service\Application\FileStorage;
use App\Tests\Support\BuildsSudoMode;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
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

    private const int TTL_SECONDS = 3600;

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
        self::assertSame(
            'the bytes',
            $storage->read($descriptor['path']),
        );

        // Acting on the stash removes both halves of it.
        $stash->discard($id);

        self::assertNull($stash->read($id));
        self::assertFalse($storage->exists($descriptor['path']));

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

    private function stash(
        ?FileStorage $storage = null,
        mixed $session = null,
        string $identifier = 'first@gewis.nl',
        mixed $valkey = null,
    ): SudoStash {
        $session ??= $this->session();

        return new SudoStash(
            $this->sudoSession(
                $session,
                $this->tokenStorage($identifier),
            ),
            $storage ?? $this->storage(),
            new NullLogger(),
            $valkey ?? $this->valkey(),
            self::TTL_SECONDS,
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
    ): Request {
        $request = Request::create(
            '/en/admin/pages/1/edit',
            $method,
            $parameters,
        );
        $request->headers->set(
            'Sec-Fetch-Site',
            'same-origin',
        );

        return $request;
    }
}
