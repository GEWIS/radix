<?php

declare(strict_types=1);

namespace App\Tests\Security\User;

use App\Controller\Frontpage\AdminPageController;
use App\Security\User\SudoStash;
use App\Service\Application\FileStorage;
use App\Tests\Support\BuildsSudoMode;
use DateTimeImmutable;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

use function bin2hex;
use function is_dir;
use function is_file;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function str_repeat;
use function sys_get_temp_dir;
use function time;
use function touch;
use function unlink;

/**
 * The uploads of a stash outlive the key that names them, so a sweep has to find them without being able to ask the
 * stash where they are. It acts only on the layout {@see SudoStash::uploadPath()} writes.
 */
final class SudoStashPruneTest extends TestCase
{
    use BuildsSudoMode;

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/gws_prune_' . bin2hex(random_bytes(4));
        mkdir(
            $this->root,
            0o777,
            true,
        );
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testAStashLastWrittenBeforeTheCutoffIsThrownAway(): void
    {
        $storage = $this->storage();
        $stash = $this->stash($storage);

        $old = str_repeat(
            'a',
            32,
        );
        $new = str_repeat(
            'b',
            32,
        );

        $storage->write(
            'sudo-stash/' . $old . '/0',
            'old',
        );
        $storage->write(
            'sudo-stash/' . $new . '/0',
            'new',
        );

        touch(
            $this->root . '/sudo-stash/' . $old . '/0',
            time() - 172800,
        );

        self::assertSame(
            1,
            $stash->prune(new DateTimeImmutable('-1 day')),
        );

        self::assertFalse($storage->exists('sudo-stash/' . $old . '/0'));
        self::assertTrue($storage->exists('sudo-stash/' . $new . '/0'));
    }

    /**
     * Anything that is not the shape a stash writes is left where it is: a sweep that acted on the root alone would
     * throw away every stash at once for a file that sits directly under it.
     */
    public function testOnlyTheLayoutAStashWritesIsSwept(): void
    {
        $storage = $this->storage();
        $stash = $this->stash($storage);

        $storage->write(
            'sudo-stash/not-a-stash',
            'not ours',
        );
        $storage->write(
            'sudo-stash/' . str_repeat(
                'c',
                32,
            ) . '/not-an-index',
            'not ours either',
        );
        $storage->write(
            'photos/albums/1/a-photo.jpg',
            'a photo',
        );

        touch(
            $this->root . '/sudo-stash/not-a-stash',
            time() - 172800,
        );
        touch(
            $this->root . '/sudo-stash/' . str_repeat(
                'c',
                32,
            ) . '/not-an-index',
            time() - 172800,
        );
        touch(
            $this->root . '/photos/albums/1/a-photo.jpg',
            time() - 172800,
        );

        self::assertSame(
            0,
            $stash->prune(new DateTimeImmutable()),
        );

        self::assertTrue($storage->exists('sudo-stash/not-a-stash'));
        self::assertTrue($storage->exists('sudo-stash/' . str_repeat('c', 32) . '/not-an-index'));
        self::assertTrue($storage->exists('photos/albums/1/a-photo.jpg'));
    }

    public function testAWriteFromThisSiteIsKept(): void
    {
        $request = $this->write();
        $request->headers->remove('Sec-Fetch-Site');
        $request->headers->set(
            'Origin',
            'https://gewis.nl',
        );

        self::assertNotNull($this->stash()->stash($request));
    }

    public function testAWriteFromAnotherHostIsNotKept(): void
    {
        $request = $this->write();
        $request->headers->remove('Sec-Fetch-Site');
        $request->headers->set(
            'Origin',
            'https://attacker.example',
        );

        self::assertNull($this->stash()->stash($request));
    }

    public function testAWriteFromAnotherSchemeIsNotKept(): void
    {
        $request = $this->write();
        $request->headers->remove('Sec-Fetch-Site');
        $request->headers->set(
            'Origin',
            'http://gewis.nl',
        );

        self::assertNull($this->stash()->stash($request));
    }

    public function testAWriteFromAnotherPortIsNotKept(): void
    {
        $request = Request::create(
            'https://gewis.nl:8443/en/admin/pages/create',
            'POST',
            ['title' => 'x'],
        );
        $request->headers->remove('Sec-Fetch-Site');
        $request->headers->set(
            'Origin',
            'https://gewis.nl:9999',
        );

        self::assertNull($this->stash()->stash($request));
    }

    public function testAWriteFromTheSamePortIsKept(): void
    {
        $request = Request::create(
            'https://gewis.nl:8443/en/admin/pages/create',
            'POST',
            ['title' => 'x'],
        );
        $request->headers->remove('Sec-Fetch-Site');
        $request->headers->set(
            'Origin',
            'https://gewis.nl:8443',
        );

        self::assertNotNull($this->stash()->stash($request));
    }

    /**
     * A referer is the fallback where a browser states no origin.
     */
    public function testARefererFromThisSiteIsAccepted(): void
    {
        $request = $this->write();
        $request->headers->remove('Sec-Fetch-Site');
        $request->headers->set(
            'Referer',
            'https://gewis.nl/en/admin/pages',
        );

        self::assertNotNull($this->stash()->stash($request));
    }

    /**
     * A body larger than anything a form sends is not kept, so a refused write cannot be used to fill the store.
     */
    public function testAnOversizedBodyIsNotKept(): void
    {
        $request = $this->write(['body' => str_repeat('x', 300 * 1024)]);

        self::assertNull($this->stash()->stash($request));
    }

    public function testAWriteWithoutAnAccountIsNotKept(): void
    {
        $request = $this->write();

        self::assertNull($this->stash(
            null,
            new TokenStorage(),
        )->stash($request));
    }

    private function stash(
        ?FileStorage $storage = null,
        ?TokenStorage $tokenStorage = null,
    ): SudoStash {
        return $this->sudoStash(
            $this->session(),
            $tokenStorage ?? $this->tokenStorage('first@gewis.nl'),
            $storage ?? $this->storage(),
        );
    }

    private function storage(): FileStorage
    {
        return new FileStorage(new Filesystem(new LocalFilesystemAdapter($this->root)));
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function write(array $parameters = ['title' => 'Half a page']): Request
    {
        $request = Request::create(
            'https://gewis.nl/en/admin/pages/create',
            'POST',
            $parameters,
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

    private function remove(string $path): void
    {
        if (is_file($path)) {
            unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        if (false === $entries) {
            return;
        }

        foreach ($entries as $entry) {
            if (
                '.' === $entry
                || '..' === $entry
            ) {
                continue;
            }

            $this->remove($path . '/' . $entry);
        }

        rmdir($path);
    }
}
