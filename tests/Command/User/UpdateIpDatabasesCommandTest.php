<?php

declare(strict_types=1);

namespace App\Tests\Command\User;

use App\Command\User\UpdateIpDatabasesCommand;
use Phar;
use PharData;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function file_get_contents;
use function mkdir;
use function scandir;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * The databases feed the security notices, so a truncated or wrong download must not replace the copy in use. The
 * test runs the provider's failure modes against a temporary directory with a mocked response.
 */
#[CoversClass(UpdateIpDatabasesCommand::class)]
final class UpdateIpDatabasesCommandTest extends TestCase
{
    /** @var list<string> */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            new SymfonyFilesystem()->remove($directory);
        }

        $this->directories = [];
    }

    public function testAFailureToCreateTheDirectoryIsReported(): void
    {
        // Somewhere under a regular file: no directory can be made there.
        $blocker = (string) tempnam(
            sys_get_temp_dir(),
            'radix-geoip-blocker-',
        );

        $tester = new CommandTester(new UpdateIpDatabasesCommand(
            new MockHttpClient([]),
            $blocker . '/geoip',
            '',
            '',
        ));

        self::assertSame(
            Command::FAILURE,
            $tester->execute([]),
        );
        self::assertStringContainsString(
            'Could not create',
            $tester->getDisplay(),
        );

        unlink($blocker);
    }

    /**
     * IPLocate serves its files as they are, so a response that is not a database fails the same check as a truncated
     * one: an address every edition must resolve.
     */
    public function testADownloadThatIsNotADatabaseIsRefusedAndNothingIsLeftBehind(): void
    {
        $directory = $this->newDirectory();

        $tester = new CommandTester(new UpdateIpDatabasesCommand(
            new MockHttpClient([
                new MockResponse('this is not a MaxMind database'),
                new MockResponse('this is not a MaxMind database'),
            ]),
            $directory,
            '',
            '',
        ));

        self::assertSame(
            Command::FAILURE,
            $tester->execute([]),
        );

        $display = $tester->getDisplay();
        self::assertStringContainsString(
            'Could not update ip-to-asn.mmdb',
            $display,
        );
        self::assertStringContainsString(
            'Could not update ip-to-location.mmdb',
            $display,
        );

        self::assertSame(
            [
                '.',
                '..',
            ],
            scandir($directory),
            'A download that failed the proof, and its temporary copy, must not be left where a run could pick it up.',
        );
    }

    /**
     * A MaxMind archive contains a dated directory; one without a database is a changed layout, not an installation.
     */
    public function testAMaxMindArchiveWithoutADatabaseIsRefused(): void
    {
        $directory = $this->newDirectory();

        $tester = new CommandTester(new UpdateIpDatabasesCommand(
            new MockHttpClient([
                $this->archiveWithoutDatabase(),
                $this->archiveWithoutDatabase(),
            ]),
            $directory,
            'an-account-id',
            'a-license-key',
        ));

        self::assertSame(
            Command::FAILURE,
            $tester->execute([]),
        );

        $display = $tester->getDisplay();
        self::assertStringContainsString(
            'The GeoLite2-ASN archive holds no',
            $display,
        );
        self::assertStringContainsString(
            'The GeoLite2-City archive holds',
            $display,
        );
        self::assertSame(
            [
                '.',
                '..',
            ],
            scandir($directory),
        );
    }

    private function newDirectory(): string
    {
        $directory = (string) tempnam(
            sys_get_temp_dir(),
            'radix-geoip-',
        );
        unlink($directory);
        mkdir($directory);
        $this->directories[] = $directory;

        return $directory;
    }

    /**
     * A well-formed tar.gz of the shape MaxMind sends, containing a dated directory and no `.mmdb` in it.
     */
    private function archiveWithoutDatabase(): MockResponse
    {
        $path = $this->newDirectory() . '/download.tar';
        $archive = new PharData($path);
        $archive->addFromString(
            'GeoLite2-City_20260101/README.txt',
            'this edition is not in this archive',
        );
        $archive->compress(Phar::GZ);

        $contents = file_get_contents($path . '.gz');
        self::assertIsString($contents);

        return new MockResponse($contents);
    }
}
