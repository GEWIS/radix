<?php

declare(strict_types=1);

namespace App\Command\Application;

use Doctrine\Bundle\FixturesBundle\Loader\FixturesProvider;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function sprintf;

/**
 * Seeds both databases in the order they depend on one another, with the projection rebuilt in between:
 *
 *   1. the ledger, which is where the members, the meetings and the decisions about them are written;
 *   2. `report:generate:full`, which replays that into the decision projection the website reads -- the members,
 *      their addresses and list memberships, and the organs, organ members, board members and keyholders the
 *      decisions imply;
 *   3. the web database, whose fixtures hang activities, photos, accounts and the rest off what step 2 produced.
 *
 * Nothing fixtures the projection directly. A body exists because a decision founded it, and somebody is in it
 * because a decision installed them, so the only way to seed one is to seed the decision and replay it.
 *
 * Every fixture names the group it belongs to, and the bundle's loader takes one entity manager at a time, so the two
 * halves are executed separately. They are purged differently as well, which is the other reason this exists rather
 * than two invocations of `doctrine:fixtures:load`.
 */
#[AsCommand(
    name: 'app:fixtures:load',
    description: 'Seed the ledger and the web database with their data fixtures.',
)]
final class LoadFixturesCommand extends Command
{
    private const string LEDGER_GROUP = 'ledger';
    private const string WEB_GROUP = 'web';

    public function __construct(
        #[Autowire(service: 'doctrine.fixtures.loader')]
        private readonly FixturesProvider $fixturesProvider,
        #[Autowire(service: 'doctrine.orm.default_entity_manager')]
        private readonly EntityManagerInterface $ledgerManager,
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private readonly EntityManagerInterface $webManager,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $ui = new SymfonyStyle(
            $input,
            $output,
        );

        if (
            !$ui->confirm(
                sprintf(
                    'Careful, databases "%s" and "%s" will be purged. Do you want to continue?',
                    $this->ledgerManager->getConnection()->getDatabase() ?? '',
                    $this->webManager->getConnection()->getDatabase() ?? '',
                ),
                !$input->isInteractive(),
            )
        ) {
            return Command::SUCCESS;
        }

        $status = $this->loadLedger($ui);

        if (null !== $status) {
            return $status;
        }

        $this->purgeWeb();

        return $this->rebuildProjection(
            $ui,
            $output,
        ) ?? $this->loadWeb($ui) ?? Command::SUCCESS;
    }

    /**
     * Replay the ledger into the projection, before the web fixtures that hang off it are loaded.
     *
     * The command is run rather than its services called in turn, so that the pause it puts on the API for the length
     * of the rebuild, and the progress it reports, are not a second copy that can drift from the first.
     *
     * @return int|null the failure to report, or null to carry on
     */
    private function rebuildProjection(
        SymfonyStyle $ui,
        OutputInterface $output,
    ): ?int {
        $generate = $this->getApplication()?->find('report:generate:full');

        if (null === $generate) {
            $ui->error('The projection cannot be rebuilt: report:generate:full is not registered.');

            return Command::FAILURE;
        }

        $ui->text('Replaying the ledger into the projection...');

        $status = $generate->run(
            new ArrayInput([]),
            $output,
        );

        return Command::SUCCESS === $status
            ? null
            : $status;
    }

    /**
     * @return int|null the failure to report, or null to carry on
     */
    private function loadLedger(SymfonyStyle $ui): ?int
    {
        $fixtures = $this->fixtures(
            $ui,
            self::LEDGER_GROUP,
        );

        if (null === $fixtures) {
            return Command::FAILURE;
        }

        $ui->text('Loading fixtures into the ledger...');

        // Nothing special: the ledger's constraints resolve in dependency order, so the executor may purge and load
        // inside one transaction the way `doctrine:fixtures:load` does.
        new ORMExecutor(
            $this->ledgerManager,
            new ORMPurger($this->ledgerManager),
        )->execute($fixtures);

        return null;
    }

    /**
     * Empty the web database, before the projection is replayed into it rather than after.
     *
     * The replay is what fills the decision half of this schema, so a purge that ran after it would take the organs,
     * the board and the keyholders it had just derived straight back out again.
     *
     * Foreign key checks are lifted around it: large parts of this schema lack explicit CASCADEs, so that projecting
     * the ledger cannot cascade data away, and the dependency-ordered purge cannot resolve the self-referential and
     * cross-table constraints that leaves (SubDecision among them) over existing data.
     */
    private function purgeWeb(): void
    {
        $connection = $this->webManager->getConnection();
        $purger = new ORMPurger($this->webManager);
        $purger->setPurgeMode(ORMPurger::PURGE_MODE_TRUNCATE);

        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');

        try {
            $purger->purge();
        } finally {
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private function loadWeb(SymfonyStyle $ui): ?int
    {
        $fixtures = $this->fixtures(
            $ui,
            self::WEB_GROUP,
        );

        if (null === $fixtures) {
            return Command::FAILURE;
        }

        $connection = $this->webManager->getConnection();

        $ui->text('Loading fixtures into the web database...');

        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');

        try {
            // Loaded with append: the schema was emptied before the replay, and what the replay wrote is what these
            // fixtures hang off, so there is nothing left to purge here.
            new ORMExecutor($this->webManager)->execute(
                $fixtures,
                true,
            );
        } finally {
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }

        $ui->success('Loaded fixtures!');

        return null;
    }

    /**
     * @return FixtureInterface[]|null the fixtures in the group, or null if it holds none
     */
    private function fixtures(
        SymfonyStyle $ui,
        string $group,
    ): ?array {
        $fixtures = $this->fixturesProvider->getFixtures([$group]);

        if ([] === $fixtures) {
            $ui->error(sprintf(
                'Could not find any fixture services in the "%s" group to load.',
                $group,
            ));

            return null;
        }

        return $fixtures;
    }
}
