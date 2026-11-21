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
 * Seeds the databases in the order they depend on one another, with the projection rebuilt in between: the
 * ledger first, then `app:decision:generate` replays it into the projection the website reads, then the
 * application fixtures hang off what that produced. Nothing fixtures the projection directly: a body exists
 * because a decision founded it, so the only way to seed one is to seed the decision and replay it. The two
 * halves are purged differently, which is why this command exists rather than two invocations of
 * `doctrine:fixtures:load`.
 */
#[AsCommand(
    name: 'app:fixtures:load',
    description: 'Seed the ledger and the application database with their data fixtures.',
)]
final class LoadFixturesCommand extends Command
{
    private const string LEDGER_GROUP = 'ledger';
    private const string APPLICATION_GROUP = 'application';

    public function __construct(
        #[Autowire(service: 'doctrine.fixtures.loader')]
        private readonly FixturesProvider $fixturesProvider,
        #[Autowire(service: 'doctrine.orm.database_entity_manager')]
        private readonly EntityManagerInterface $ledgerManager,
        #[Autowire(service: 'doctrine.orm.application_entity_manager')]
        private readonly EntityManagerInterface $applicationManager,
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
                    $this->applicationManager->getConnection()->getDatabase() ?? '',
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

        $this->purgeApplication();

        return $this->rebuildProjection(
            $ui,
            $output,
        ) ?? $this->loadApplication($ui) ?? Command::SUCCESS;
    }

    /**
     * Replays the ledger into the projection, before the fixtures that hang off it are loaded. Run rather than
     * called in turn, so the API pause and the reported progress are not a second copy that can drift.
     *
     * @return int|null the failure to report, or null to carry on
     */
    private function rebuildProjection(
        SymfonyStyle $ui,
        OutputInterface $output,
    ): ?int {
        $generate = $this->getApplication()?->find('app:decision:generate');

        if (null === $generate) {
            $ui->error('The projection cannot be rebuilt: app:decision:generate is not registered.');

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

        // The ledger's constraints resolve in dependency order, so purge and load share one transaction.
        new ORMExecutor(
            $this->ledgerManager,
            new ORMPurger($this->ledgerManager),
        )->execute($fixtures);

        return null;
    }

    /**
     * Emptied before the replay: a purge after it would take the organs, board and keyholders the replay
     * derived straight back out.
     *
     * Foreign key checks are lifted because this schema lacks explicit CASCADEs and the dependency-ordered purge
     * cannot resolve the remaining constraints over existing data. Development and tests only, where the loaders
     * run as the schema's owner.
     */
    private function purgeApplication(): void
    {
        $connection = $this->applicationManager->getConnection();
        $purger = new ORMPurger($this->applicationManager);
        $purger->setPurgeMode(ORMPurger::PURGE_MODE_TRUNCATE);

        $connection->executeStatement($this->disableForeignKeyChecks());

        try {
            $purger->purge();
        } finally {
            $connection->executeStatement($this->enableForeignKeyChecks());
        }
    }

    private function loadApplication(SymfonyStyle $ui): ?int
    {
        $fixtures = $this->fixtures(
            $ui,
            self::APPLICATION_GROUP,
        );

        if (null === $fixtures) {
            return Command::FAILURE;
        }

        $connection = $this->applicationManager->getConnection();

        $ui->text('Loading fixtures into the application database...');

        $connection->executeStatement($this->disableForeignKeyChecks());

        try {
            // Append mode: the schema was emptied before the replay, and these fixtures hang off what it wrote.
            new ORMExecutor($this->applicationManager)->execute(
                $fixtures,
                true,
            );
        } finally {
            $connection->executeStatement($this->enableForeignKeyChecks());
        }

        $ui->success('Loaded fixtures!');

        return null;
    }

    private function disableForeignKeyChecks(): string
    {
        return 'SET session_replication_role = replica';
    }

    private function enableForeignKeyChecks(): string
    {
        return 'SET session_replication_role = origin';
    }

    /**
     * @return FixtureInterface[]|null the fixtures in the group, or null if the group contains none
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
