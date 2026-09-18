<?php

declare(strict_types=1);

namespace App\Command\User;

use App\Command\HoldsRunLockTrait;
use App\Security\User\SudoStash;
use DateTimeImmutable;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

use function sprintf;

/**
 * Throws away the uploads of a write that was refused for want of a sudo grant and never re-run.
 *
 * The record of the write expires in Valkey on its own, and the uploads do not: they are in storage, where nothing
 * expires. A user who is refused, decides against confirming and closes the tab leaves them behind, and so does one
 * whose action is not re-run at all.
 *
 * The cutoff is twice the lifetime of the record, so a stash is only ever thrown away well after the record that
 * points at it has gone.
 */
#[AsCommand(
    name: 'app:user:prune-sudo-stashes',
    description: 'Throw away the uploads of refused writes that were never re-run.',
)]
#[AsCronTask(
    expression: '25 * * * *',
    transports: 'maintenance',
)]
final class PruneSudoStashesCommand extends Command
{
    use HoldsRunLockTrait;

    public function __construct(
        private readonly SudoStash $stash,
        private readonly LoggerInterface $logger,
        #[Autowire(param: 'app.sudo_stash_ttl')]
        private readonly int $ttlSeconds,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        return $this->runExclusively(
            $output,
            fn (): int => $this->executeExclusively(
                $input,
                $output,
            ),
        );
    }

    private function executeExclusively(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $io = new SymfonyStyle(
            $input,
            $output,
        );

        $pruned = $this->stash->prune(new DateTimeImmutable(sprintf(
            '-%d seconds',
            2 * $this->ttlSeconds,
        )));

        $message = sprintf(
            'Threw away the uploads of %d refused write(s) that were never re-run.',
            $pruned,
        );

        $this->logger->info($message);
        $io->success($message);

        return Command::SUCCESS;
    }
}
