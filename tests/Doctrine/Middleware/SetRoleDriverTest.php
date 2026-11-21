<?php

declare(strict_types=1);

namespace App\Tests\Doctrine\Middleware;

use App\Doctrine\Middleware\SetRoleDriver;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Every connection is opened under the least-privileged role its own DSN names, and a DSN without one is left alone.
 */
final class SetRoleDriverTest extends TestCase
{
    public function testAConnectionIsDroppedToTheRoleItsDsnNames(): void
    {
        $executed = [];

        $connection = self::createStub(Connection::class);
        $connection->method('quote')->willReturnCallback(
            static fn (string $value): string => "'" . $value . "'",
        );
        $connection->method('exec')->willReturnCallback(
            static function (string $sql) use (&$executed): int {
                $executed[] = $sql;

                return 0;
            },
        );

        $driver = self::createStub(Driver::class);
        $driver->method('connect')->willReturn($connection);

        $opened = new SetRoleDriver($driver)->connect([
            'host' => 'database',
            'role' => 'app_user',
        ]);

        self::assertSame(
            $connection,
            $opened,
        );
        self::assertSame(
            ["SET ROLE 'app_user'"],
            $executed,
        );
    }

    public function testADsnWithoutARoleIsLeftAlone(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('exec');

        $driver = self::createStub(Driver::class);
        $driver->method('connect')->willReturn($connection);

        new SetRoleDriver($driver)->connect(['host' => 'database']);
    }

    public function testARoleThatIsNotAStringIsLeftAlone(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('exec');

        $driver = self::createStub(Driver::class);
        $driver->method('connect')->willReturn($connection);

        new SetRoleDriver($driver)->connect(['role' => ['not', 'a', 'string']]);
    }
}
