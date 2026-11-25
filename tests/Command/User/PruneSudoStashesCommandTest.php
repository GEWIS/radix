<?php

declare(strict_types=1);

namespace App\Tests\Command\User;

use App\Command\User\PruneSudoStashesCommand;
use App\Entity\Application\Enums\StorageNamespace;
use App\Service\Application\FileStorage;
use App\Tests\Support\BuildsSudoMode;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;

use function mkdir;
use function strtotime;
use function sys_get_temp_dir;
use function tempnam;
use function touch;
use function unlink;

/**
 * The record of a refused write expires in Valkey, but its uploads do not. A user who closes the tab leaves them
 * behind, so only a stash older than twice the record's lifetime is removed.
 */
#[CoversClass(PruneSudoStashesCommand::class)]
final class PruneSudoStashesCommandTest extends TestCase
{
    use BuildsSudoMode;

    /** Twice the grant's hour, as `config/packages/session.yaml` sets it. */
    private const int TTL_SECONDS = 3600;

    private const string ABANDONED = '0123456789abcdef0123456789abcdef';
    private const string RECENT = 'fedcba9876543210fedcba9876543210';

    private ?string $directory = null;

    #[Override]
    protected function tearDown(): void
    {
        if (null === $this->directory) {
            return;
        }

        new SymfonyFilesystem()->remove($this->directory);
        $this->directory = null;
    }

    public function testAStashWhoseRecordHasLongExpiredIsThrownAway(): void
    {
        $storage = $this->storage();

        $abandoned = StorageNamespace::SudoStash->directory(self::ABANDONED) . '/0';
        $recent = StorageNamespace::SudoStash->directory(self::RECENT) . '/0';
        $storage->write(
            $abandoned,
            'an upload',
        );
        $storage->write(
            $recent,
            'an upload',
        );
        $this->age(
            $abandoned,
            strtotime('-3 hours'),
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with('Threw away the uploads of 1 refused write(s) that were never re-run.');

        $tester = new CommandTester(new PruneSudoStashesCommand(
            $this->sudoStash(
                $this->session(),
                $this->tokenStorage('8000'),
                $storage,
            ),
            $logger,
            self::TTL_SECONDS,
        ));

        self::assertSame(
            Command::SUCCESS,
            $tester->execute([]),
        );

        self::assertFalse(
            $storage->exists($abandoned),
            'A stash whose record has expired can never be re-run, so its uploads go.',
        );
        self::assertTrue(
            $storage->exists($recent),
            'A stash within twice the record\'s lifetime may still be confirmed.',
        );
        self::assertStringContainsString(
            'Threw away the uploads of 1 refused write(s) that were never re-run.',
            $tester->getDisplay(),
        );
    }

    public function testNothingIsThrownAwayWhileEveryStashIsRecent(): void
    {
        $storage = $this->storage();
        $recent = StorageNamespace::SudoStash->directory(self::RECENT) . '/0';
        $storage->write(
            $recent,
            'an upload',
        );

        $tester = new CommandTester(new PruneSudoStashesCommand(
            $this->sudoStash(
                $this->session(),
                $this->tokenStorage('8000'),
                $storage,
            ),
            self::createStub(LoggerInterface::class),
            self::TTL_SECONDS,
        ));

        self::assertSame(
            Command::SUCCESS,
            $tester->execute([]),
        );

        self::assertTrue($storage->exists($recent));
        self::assertStringContainsString(
            'the uploads of 0 refused write(s)',
            $tester->getDisplay(),
        );
    }

    private function storage(): FileStorage
    {
        $directory = (string) tempnam(
            sys_get_temp_dir(),
            'radix-sudo-stashes-',
        );
        unlink($directory);
        mkdir($directory);
        $this->directory = $directory;

        return new FileStorage(new Filesystem(new LocalFilesystemAdapter($directory)));
    }

    private function age(
        string $path,
        int $modifiedAt,
    ): void {
        self::assertNotNull($this->directory);
        touch(
            $this->directory . '/' . $path,
            $modifiedAt,
        );
    }
}
