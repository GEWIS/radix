<?php

declare(strict_types=1);

namespace App\Command\Database;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Installs the unaccent extension the ledger's search relies on: the migrations install it for a real database,
 * but schema:create (the test suite's build) never runs them, so the test preparation calls this by hand.
 */
#[AsCommand(
    name: 'app:database:install-unaccent',
    description: 'Install the unaccent extension on the ledger when it runs on PostgreSQL.',
)]
final class InstallUnaccentExtensionCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    #[Override]
    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $connection = $this->entityManager->getConnection();

        if (!$connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $output->writeln('The ledger does not run on PostgreSQL; nothing to install.');

            return Command::SUCCESS;
        }

        $connection->executeStatement('CREATE EXTENSION IF NOT EXISTS unaccent');
        $output->writeln('Installed the unaccent extension.');

        return Command::SUCCESS;
    }
}
