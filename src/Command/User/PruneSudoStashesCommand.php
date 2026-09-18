<?php

declare(strict_types=1);

namespace App\Command\User;

use App\Command\HoldsRunLockTrait;
use App\Entity\Application\Enums\StorageNamespace;
use App\Service\Application\FileStorage;
use Override;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

use function count;
use function dirname;
use function max;
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
        private readonly FileStorage $fileStorage,
        private readonly ClockInterface $clock,
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

        $cutoff = $this->clock->now()->getTimestamp() - (2 * $this->ttlSeconds);

        // The namespace is scoped per stash, so the root is the parent of what a stash writes into.
        $root = dirname(StorageNamespace::SudoStash->directory('any'));

        /** @var array<string, int> $newest */
        $newest = [];
        foreach (
            $this->fileStorage->listFiles(
                $root,
                true,
            ) as $path
        ) {
            $directory = dirname($path);

            $newest[$directory] = max(
                $newest[$directory] ?? 0,
                $this->fileStorage->lastModified($path),
            );
        }

        $pruned = [];
        foreach ($newest as $directory => $modified) {
            if ($modified > $cutoff) {
                continue;
            }

            $this->fileStorage->deleteDirectory($directory);
            $pruned[] = $directory;
        }

        $message = sprintf(
            'Threw away the uploads of %d refused write(s) that were never re-run.',
            count($pruned),
        );

        $this->logger->info($message);
        $io->success($message);

        return Command::SUCCESS;
    }
}
