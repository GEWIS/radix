<?php

declare(strict_types=1);

namespace App\Tests\Command\User;

use App\Command\User\PruneExpiredDataExportsCommand;
use App\Service\Application\FileStorage;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
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
 * A data export is a copy of everything about a member, kept until they fetch it. After that window it is personal
 * data in storage, so the sweep removes it.
 */
#[CoversClass(PruneExpiredDataExportsCommand::class)]
final class PruneExpiredDataExportsCommandTest extends TestCase
{
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

    public function testOnlyExportsPastTheirWindowAreRemoved(): void
    {
        $storage = $this->storage();

        $expired = 'gdpr-export/8000.json';
        $fresh = 'gdpr-export/8030.json';
        $storage->write(
            $expired,
            'old',
        );
        $storage->write(
            $fresh,
            'new',
        );

        // Far past any retention window the handler could be configured with.
        $this->age(
            $expired,
            strtotime('-2 years'),
        );

        $tester = new CommandTester(new PruneExpiredDataExportsCommand($storage));

        self::assertSame(
            Command::SUCCESS,
            $tester->execute([]),
        );

        self::assertFalse(
            $storage->exists($expired),
            'An export nobody collected must not remain in storage for ever.',
        );
        self::assertTrue(
            $storage->exists($fresh),
            'A member may still be about to collect theirs.',
        );
        self::assertStringContainsString(
            'Removed 1 expired data export.',
            $tester->getDisplay(),
        );
    }

    public function testNothingIsRemovedWhenThereIsNothingToRemove(): void
    {
        $tester = new CommandTester(new PruneExpiredDataExportsCommand($this->storage()));

        self::assertSame(
            Command::SUCCESS,
            $tester->execute([]),
        );
        self::assertStringContainsString(
            'Removed 0 expired data exports.',
            $tester->getDisplay(),
        );
    }

    /**
     * A storage on the real filesystem, because an in-memory adapter stamps every file with now and nothing would be
     * old enough to sweep.
     */
    private function storage(): FileStorage
    {
        $directory = (string) tempnam(
            sys_get_temp_dir(),
            'radix-data-exports-',
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
