<?php

declare(strict_types=1);

namespace App\Tests\Command\Database;

use App\Command\Database\InstallUnaccentExtensionCommand;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Migrations install the unaccent extension, and a schema built from the mapping does not run them. Test
 * preparation calls this command, so it must do nothing on a platform without the extension.
 */
#[CoversClass(InstallUnaccentExtensionCommand::class)]
final class InstallUnaccentExtensionCommandTest extends TestCase
{
    public function testInstallsTheExtensionOnPostgreSQL(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->expects(self::once())
            ->method('executeStatement')
            ->with('CREATE EXTENSION IF NOT EXISTS unaccent');

        $tester = new CommandTester(new InstallUnaccentExtensionCommand($this->entityManagerWith($connection)));

        self::assertSame(
            Command::SUCCESS,
            $tester->execute([]),
        );
        self::assertStringContainsString(
            'Installed the unaccent extension.',
            $tester->getDisplay(),
        );
    }

    public function testDoesNothingWithoutPostgreSQL(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MariaDBPlatform());
        $connection->expects(self::never())->method('executeStatement');

        $tester = new CommandTester(new InstallUnaccentExtensionCommand($this->entityManagerWith($connection)));

        self::assertSame(
            Command::SUCCESS,
            $tester->execute([]),
        );
        self::assertStringContainsString(
            'The ledger does not run on PostgreSQL; nothing to install.',
            $tester->getDisplay(),
        );
    }

    private function entityManagerWith(Connection $connection): EntityManagerInterface
    {
        $entityManager = self::createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        return $entityManager;
    }
}
