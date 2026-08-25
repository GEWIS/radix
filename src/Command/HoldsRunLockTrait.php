<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Holds a lock for the length of a scheduled command's run, so two runs of it never overlap.
 *
 * Until the schedules became dispatchers this was implicit: one worker consumed one schedule, so its jobs ran one
 * after another. The work now goes onto transports with several consumers, where two runs of the same command can
 * overlap (an hourly mailing list sync that takes over an hour would push membership diffs at Mailman twice over),
 * so the exclusion has to be stated rather than inherited from the worker topology.
 *
 * A run that finds the lock taken reports success. Every command here recomputes what the previous run would have
 * done, so the running one also covers this due time, and failing would add a message to `failed` for a schedule
 * that is working correctly.
 */
trait HoldsRunLockTrait
{
    use LockableTrait;

    /**
     * {@see LockableTrait} builds its own factory over a `flock`, which cannot see the other container this lock
     * exists to exclude. Injected by setter so a command keeps its own constructor.
     */
    #[Required]
    public function setRunLockFactory(LockFactory $lockFactory): void
    {
        $this->lockFactory = $lockFactory;
    }

    /**
     * @param callable(): int $run
     */
    private function runExclusively(
        OutputInterface $output,
        callable $run,
    ): int {
        if (!$this->lock()) {
            $output->writeln('Another run of this command is still in flight, standing down.');

            return Command::SUCCESS;
        }

        try {
            return $run();
        } finally {
            $this->release();
        }
    }
}
