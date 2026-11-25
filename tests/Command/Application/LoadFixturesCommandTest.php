<?php

declare(strict_types=1);

namespace App\Tests\Command\Application;

use App\Command\Application\LoadFixturesCommand;
use Doctrine\Bundle\FixturesBundle\Loader\FixturesProvider;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The command purges both databases, so a refused confirmation or an empty group must leave both unchanged.
 */
#[CoversClass(LoadFixturesCommand::class)]
final class LoadFixturesCommandTest extends TestCase
{
    public function testDecliningTheConfirmationLoadsNothing(): void
    {
        $fixtures = $this->createMock(FixturesProvider::class);
        $fixtures->expects(self::never())->method('getFixtures');

        $tester = new CommandTester(new LoadFixturesCommand(
            $fixtures,
            $this->managerFor('radixdev_db'),
            $this->managerFor('radixdev_app'),
        ));
        $tester->setInputs(['no']);

        self::assertSame(
            Command::SUCCESS,
            $tester->execute(
                [],
                ['interactive' => true],
            ),
        );

        $display = $tester->getDisplay();
        self::assertStringContainsString(
            'Careful, databases "radixdev_db" and "radixdev_app" will be purged.',
            $display,
        );
        self::assertStringNotContainsString(
            'Loading fixtures into the ledger',
            $display,
        );
    }

    /**
     * A group that no fixture is tagged with is a deployment mistake, not a reason to purge one database and leave
     * the other empty.
     */
    public function testAnEmptyLedgerGroupStopsTheRunBeforeAnythingIsPurged(): void
    {
        $fixtures = self::createStub(FixturesProvider::class);
        $fixtures->method('getFixtures')->willReturn([]);

        $application = $this->createMock(EntityManagerInterface::class);
        $application->expects(self::never())->method('flush');

        $tester = new CommandTester(new LoadFixturesCommand(
            $fixtures,
            $this->managerFor('radixdev_db'),
            $application,
        ));
        $tester->setInputs(['yes']);

        self::assertSame(
            Command::FAILURE,
            $tester->execute([]),
        );
        self::assertStringContainsString(
            'Could not find any fixture services in the "ledger"',
            $tester->getDisplay(),
        );
    }

    private function managerFor(string $database): EntityManagerInterface
    {
        $connection = self::createStub(Connection::class);
        $connection->method('getDatabase')->willReturn($database);

        $manager = self::createStub(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);

        return $manager;
    }
}
