<?php

declare(strict_types=1);

namespace App\Command\Activity;

use App\Command\HoldsRunLockTrait;
use App\Entity\Activity\Activity;
use App\Message\Activity\RenderActivityShareImageMessage;
use App\Repository\Activity\ActivityRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

use function assert;
use function sprintf;

/**
 * Queues the share cards whose sign-up state has changed since they were drawn: a card shows when the sign-ups open
 * or close, and the handler records the next of those moments on the activity.
 */
#[AsCommand(
    name: 'app:activity:redraw-stale-share-images',
    description: 'Queue the share cards that a sign-up list opening or closing has made stale.',
)]
#[AsCronTask(
    expression: '*/15 * * * *',
    transports: 'cron',
)]
final class RedrawStaleShareImagesCommand extends Command
{
    use HoldsRunLockTrait;

    public function __construct(
        private readonly ActivityRepository $activityRepository,
        private readonly MessageBusInterface $messageBus,
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private readonly EntityManagerInterface $entityManager,
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
            fn (): int => $this->redraw(new SymfonyStyle(
                $input,
                $output,
            )),
        );
    }

    private function redraw(SymfonyStyle $io): int
    {
        $activities = $this->activityRepository->createQueryBuilder('a')
            ->select(
                'a',
                'r',
            )
            ->join(
                'a.liveRevision',
                'r',
            )
            ->andWhere('a.unpublishedAt IS NULL')
            ->andWhere('a.shareImageStaleAt <= :now')
            ->setParameter(
                'now',
                new DateTimeImmutable(),
            )
            ->getQuery()
            ->getResult();

        $queued = 0;
        foreach ($activities as $activity) {
            assert($activity instanceof Activity);
            $id = $activity->getLiveRevision()?->id;
            if (null === $id) {
                continue;
            }

            // Cleared here rather than by the handler, so the next run does not queue the same card again before the
            // handler has redrawn it.
            $activity->shareImageStaleAt = null;
            $this->messageBus->dispatch(new RenderActivityShareImageMessage($id));
            $queued++;
        }

        $this->entityManager->flush();
        $io->success(sprintf(
            '%d cards queued.',
            $queued,
        ));

        return Command::SUCCESS;
    }
}
