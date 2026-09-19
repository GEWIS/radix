<?php

declare(strict_types=1);

namespace App\Command\Activity;

use App\Command\HoldsRunLockTrait;
use App\Entity\Activity\Activity;
use App\Message\Activity\RenderActivityShareImageMessage;
use App\Repository\Activity\ActivityRepository;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

use function assert;
use function sprintf;

/**
 * Queues the share cards of the published activities onto the `images` transport, for the activities approved
 * before the cards existed and for a change to the card itself. The drawing happens in the workers, as it does on
 * approval.
 */
#[AsCommand(
    name: 'app:activity:render-share-images',
    description: 'Queue the share cards of the published activities onto the images transport.',
)]
final class RenderShareImagesCommand extends Command
{
    use HoldsRunLockTrait;

    public function __construct(
        private readonly ActivityRepository $activityRepository,
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addOption(
            'force',
            null,
            InputOption::VALUE_NONE,
            'Redraw every card instead of only the missing ones, for a changed design.',
        );
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Only count what would be queued, without dispatching anything.',
        );
    }

    #[Override]
    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        return $this->runExclusively(
            $output,
            fn (): int => $this->render(
                new SymfonyStyle(
                    $input,
                    $output,
                ),
                (bool) $input->getOption('force'),
                (bool) $input->getOption('dry-run'),
            ),
        );
    }

    private function render(
        SymfonyStyle $io,
        bool $force,
        bool $dryRun,
    ): int {
        $queryBuilder = $this->activityRepository->createQueryBuilder('a')
            ->select(
                'a',
                'r',
            )
            ->join(
                'a.liveRevision',
                'r',
            )
            ->andWhere('a.unpublishedAt IS NULL');
        if (!$force) {
            $queryBuilder->andWhere('a.shareImagePaths IS NULL');
        }

        $queued = 0;
        foreach ($queryBuilder->getQuery()->toIterable() as $activity) {
            assert($activity instanceof Activity);
            $revision = $activity->getLiveRevision();
            $id = $revision?->id;
            if (null === $id) {
                continue;
            }

            if (!$dryRun) {
                $this->messageBus->dispatch(new RenderActivityShareImageMessage($id));
            }

            $queued++;
        }

        $io->success(sprintf(
            $dryRun ? '%d cards would be queued.' : '%d cards queued.',
            $queued,
        ));

        return Command::SUCCESS;
    }
}
