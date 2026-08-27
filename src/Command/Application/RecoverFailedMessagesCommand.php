<?php

declare(strict_types=1);

namespace App\Command\Application;

use App\Command\HoldsRunLockTrait;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Scheduler\Attribute\AsCronTask;
use Throwable;

use function sprintf;

/**
 * Moves what {@see \App\EventListener\Messenger\TolerantFailureTransportListener} held on `failed_fallback` into
 * `failed`, so a failure that happened while the website database was down still ends up where the administration's
 * queue page reads it.
 *
 * Reports success when it cannot reach `failed`: a run that failed would be recorded on the fallback it is trying
 * to drain, which is the one loop this whole arrangement exists to avoid.
 */
#[AsCommand(
    name: 'app:messenger:recover-failures',
    description: 'Move failures held on the fallback transport into the failure transport.',
)]
#[AsCronTask(
    expression: '*/10 * * * *',
    transports: 'maintenance',
)]
final class RecoverFailedMessagesCommand extends Command
{
    use HoldsRunLockTrait;

    /** Bounds one run, so a backlog is drained over several rather than holding the maintenance worker. */
    private const int PER_RUN = 1000;

    public function __construct(
        #[Autowire(service: 'messenger.transport.failed_fallback')]
        private readonly ReceiverInterface $fallback,
        #[Autowire(service: 'messenger.transport.failed')]
        private readonly SenderInterface $failed,
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

        $recovered = 0;

        while ($recovered < self::PER_RUN) {
            $received = false;

            foreach ($this->fallback->get() as $envelope) {
                $received = true;

                try {
                    // The fallback's own id would otherwise travel with the envelope into a store that assigns one.
                    $this->failed->send($envelope->withoutAll(TransportMessageIdStamp::class));
                } catch (Throwable $e) {
                    $io->warning(sprintf(
                        'The failure transport is still unreachable after %d, leaving the rest held: %s',
                        $recovered,
                        $e->getMessage(),
                    ));

                    return Command::SUCCESS;
                }

                $this->fallback->ack($envelope);
                ++$recovered;
            }

            if (!$received) {
                break;
            }
        }

        if (self::PER_RUN === $recovered) {
            $io->note('Stopped at this run\'s limit; whatever is left is recovered by the next.');
        }

        $io->success(sprintf('Recovered %d failure%s.', $recovered, 1 !== $recovered ? 's' : ''));

        return Command::SUCCESS;
    }
}
