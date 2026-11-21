<?php

declare(strict_types=1);

namespace App\Tests\Security\User;

use App\Security\User\IpNetworkResolver;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function file_put_contents;
use function is_dir;
use function is_file;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function unlink;

/**
 * The databases are fetched locally, and a deployment without them (or with a broken copy) still has to answer: the
 * prefix is always available, and a lookup that cannot be made is logged rather than thrown.
 */
final class IpNetworkResolverDatabaseTest extends TestCase
{
    private string $directory;

    private TestHandler $log;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/gws_ip_' . bin2hex(random_bytes(4));
        mkdir(
            $this->directory,
            0o777,
            true,
        );
        $this->log = new TestHandler();
    }

    protected function tearDown(): void
    {
        $this->remove($this->directory);
    }

    public function testABrokenDatabaseLeavesThePrefixAndIsLogged(): void
    {
        $this->corrupt(IpNetworkResolver::ASN_DATABASE);

        $resolver = $this->resolver();

        self::assertSame(
            ['pfx:c00002'],
            $resolver->identify('192.0.2.10'),
        );
        self::assertNull($resolver->networkName('192.0.2.10'));
        self::assertTrue($this->log->hasRecordThatContains('could not answer', Level::Warning));
    }

    public function testABrokenLocationDatabaseNamesNoLocation(): void
    {
        $this->corrupt(IpNetworkResolver::LOCATION_DATABASE);

        self::assertNull($this->resolver()->locationName('192.0.2.10'));
        self::assertTrue($this->log->hasRecordThatContains('could not answer', Level::Warning));
    }

    /**
     * The campus name is decided before any database is consulted, so a broken one does not take it away.
     */
    public function testTheCampusNameSurvivesABrokenDatabase(): void
    {
        $this->corrupt(IpNetworkResolver::ASN_DATABASE);

        self::assertSame(
            'TU/e campus',
            $this->resolver(['192.0.2.0/24'])->networkName('192.0.2.10'),
        );
    }

    /**
     * @param list<string> $campusRanges
     */
    private function resolver(array $campusRanges = []): IpNetworkResolver
    {
        return new IpNetworkResolver(
            $this->directory,
            $campusRanges,
            new Logger(
                'security',
                [$this->log],
            ),
        );
    }

    private function corrupt(string $database): void
    {
        file_put_contents(
            $this->directory . '/' . $database,
            'not a MaxMind database',
        );
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
