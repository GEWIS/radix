<?php

declare(strict_types=1);

namespace App\Command\User;

use App\Command\HoldsRunLockTrait;
use App\Repository\User\KnownDeviceRepository;
use App\Repository\User\SessionRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

use function sprintf;

#[AsCommand(
    name: 'app:user:purge-expired-sessions',
    description: 'Remove expired and long-idle remember-me sessions, and the devices nobody has signed in from since.',
)]
#[AsCronTask(
    expression: '30 3 * * *',
    jitter: 900,
    transports: 'gdpr',
)]
final class PurgeExpiredSessionsCommand extends Command
{
    use HoldsRunLockTrait;

    /**
     * How long a session may go unused before it is swept, whatever its expiry says. This also signs out somebody who
     * has not been near the site in a month rather than carrying them for the rest of the ninety days.
     */
    private const string SESSION_IDLE = '-30 days';

    /** Devices stop being recognised at this age regardless; this only clears the rows out behind that. */
    private const string DEVICE_IDLE = '-90 days';

    public function __construct(
        private readonly SessionRepository $repository,
        private readonly KnownDeviceRepository $knownDeviceRepository,
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private readonly EntityManagerInterface $em,
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

        $expired = $this->repository->deleteExpired();
        $idle = $this->repository->deleteIdleSince(new DateTimeImmutable(self::SESSION_IDLE));
        $devices = $this->knownDeviceRepository->deleteSeenBefore(new DateTimeImmutable(self::DEVICE_IDLE));
        $this->em->flush();

        $io->success(sprintf(
            'Purged %d expired and %d idle session%s, and forgot %d device%s.',
            $expired,
            $idle,
            1 !== $idle ? 's' : '',
            $devices,
            1 !== $devices ? 's' : '',
        ));

        return Command::SUCCESS;
    }
}
