<?php

declare(strict_types=1);

namespace App\Command\Frontpage;

use App\Command\HoldsRunLockTrait;
use App\Service\Frontpage\PageImageStore;
use DateTimeImmutable;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

use function sprintf;

/**
 * Every arrival at the create form gets a run of its own, so what was uploaded to an abandoned one would pile up
 * for good. A day is longer than a run lives, which is as long as the form is open.
 */
#[AsCommand(
    name: 'app:page:prune-images',
    description: 'Throw away the images uploaded for pages that were never finished.',
)]
#[AsCronTask(
    expression: '15 4 * * *',
    jitter: 900,
    transports: 'maintenance',
)]
final class PruneUnclaimedPageImagesCommand extends Command
{
    use HoldsRunLockTrait;

    public function __construct(
        private readonly PageImageStore $pageImageStore,
        private readonly LoggerInterface $logger,
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

        $pruned = $this->pageImageStore->prune(new DateTimeImmutable('-1 day'));

        $message = sprintf(
            'Threw away %d image(s) uploaded for pages that were never written.',
            $pruned,
        );

        $this->logger->info($message);
        $io->success($message);

        return Command::SUCCESS;
    }
}
