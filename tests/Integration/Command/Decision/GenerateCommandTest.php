<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\Decision;

use App\Command\Decision\GenerateCommand;
use App\Entity\Database\Enums\InstallationFunctions;
use App\Entity\Decision\Address;
use App\Entity\Decision\BoardMember;
use App\Entity\Decision\Decision;
use App\Entity\Decision\Keyholder;
use App\Entity\Decision\MailingList;
use App\Entity\Decision\MailingListMember;
use App\Entity\Decision\Meeting;
use App\Entity\Decision\Member;
use App\Entity\Decision\Organ;
use App\Entity\Decision\OrganMember;
use App\Entity\Decision\SubDecision;
use App\Tests\Support\LedgerBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

use function array_keys;
use function sprintf;

/**
 * `app:decision:generate` rebuilds the projection by replaying the ledger, and is what repairs it when it has
 * drifted. Two things have to hold for that to be a repair rather than a second kind of drift: replaying must not
 * change a projection that is already correct, and a projection rebuilt from nothing must come out the same as the
 * one the listeners wrote as the ledger was written.
 */
#[CoversClass(GenerateCommand::class)]
class GenerateCommandTest extends KernelTestCase
{
    /**
     * What the replay owns. The web connection carries the whole site besides, and the decision entities that are
     * not derived from the ledger — meeting documents, organ information, the activity log — are among them, so the
     * projection has to be named rather than taken to be everything the connection holds.
     */
    private const array PROJECTED_ENTITIES = [
        Address::class,
        BoardMember::class,
        Decision::class,
        Keyholder::class,
        MailingList::class,
        MailingListMember::class,
        Meeting::class,
        Member::class,
        Organ::class,
        OrganMember::class,
        SubDecision::class,
    ];

    private EntityManagerInterface $report;
    private LedgerBuilder $build;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $ledger = self::getContainer()->get(EntityManagerInterface::class);
        $report = self::getContainer()->get('doctrine')->getManager('web');
        self::assertInstanceOf(
            EntityManagerInterface::class,
            $report,
        );

        $this->report = $report;
        $this->build = new LedgerBuilder($ledger);

        $this->aLedgerWorthProjecting();
    }

    public function testReplayingAProjectionThatIsAlreadyCorrectChangesNothing(): void
    {
        $before = $this->projectedRowCounts();

        $this->replay();

        self::assertSame(
            $before,
            $this->projectedRowCounts(),
        );
    }

    /**
     * The listeners and the replay are two implementations of the same projection, and this is the only thing that
     * says they agree.
     */
    public function testAProjectionRebuiltFromNothingMatchesTheOneTheListenersWrote(): void
    {
        $listenersWrote = $this->projectedRowCounts();

        $this->emptyTheProjection();

        self::assertNotSame(
            $listenersWrote,
            $this->projectedRowCounts(),
            'the projection was not emptied',
        );

        $this->replay();

        self::assertSame(
            $listenersWrote,
            $this->projectedRowCounts(),
        );
    }

    /**
     * Enough of a ledger that the replay has every derived table to fill: an organ, someone in it, and a key.
     */
    private function aLedgerWorthProjecting(): void
    {
        $meeting = $this->build->meeting();
        $foundation = $this->build->foundOrgan(
            $meeting,
            'RPL',
            'Replaycommissie',
        );
        $member = $this->build->member();

        $this->build->install(
            $meeting,
            $foundation,
            $member,
            InstallationFunctions::Member,
        );
        $this->build->grantKey(
            $this->build->meeting(),
            $member,
        );
    }

    private function replay(): void
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        $tester = new CommandTester(new Application($kernel)->find('app:decision:generate'));

        self::assertSame(
            0,
            $tester->execute(
                [],
                ['verbosity' => OutputInterface::VERBOSITY_QUIET],
            ),
        );

        // The replay rewrote every projected row, so what this manager still holds describes a projection that has
        // since been thrown away and built again.
        $this->report->clear();
    }

    /**
     * @return array<class-string, int>
     */
    private function projectedRowCounts(): array
    {
        $counts = [];

        foreach (self::PROJECTED_ENTITIES as $entity) {
            $counts[$entity] = $this->report->getRepository($entity)->count([]);
        }

        return $counts;
    }

    private function emptyTheProjection(): void
    {
        $connection = $this->report->getConnection();

        // DELETE rather than TRUNCATE: TRUNCATE commits in MariaDB, which would take the transaction the test is
        // wrapped in with it and leave the projection empty for everything that runs after this. The keys are lifted
        // for the duration instead of deleting in dependency order, because the projection is a graph and no order
        // over it satisfies every constraint.
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach ($this->projectedTables() as $table) {
                $connection->executeStatement(
                    sprintf(
                        'DELETE FROM %s',
                        $connection->quoteSingleIdentifier($table),
                    ),
                );
            }
        } finally {
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }

        $this->report->clear();
    }

    /**
     * The tables behind {@see self::PROJECTED_ENTITIES}, taken from the mapping so that a renamed table or an added
     * subclass does not quietly leave rows behind.
     *
     * @return string[]
     */
    private function projectedTables(): array
    {
        $tables = [];

        foreach (self::PROJECTED_ENTITIES as $entity) {
            $metadata = $this->report->getClassMetadata($entity);
            $tables[$metadata->getTableName()] = true;

            foreach ($metadata->associationMappings as $association) {
                if (!$association instanceof ManyToManyOwningSideMapping) {
                    continue;
                }

                $tables[$association->joinTable->name] = true;
            }
        }

        return array_keys($tables);
    }
}
