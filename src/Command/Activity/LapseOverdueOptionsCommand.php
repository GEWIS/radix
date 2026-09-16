<?php

declare(strict_types=1);

namespace App\Command\Activity;

use App\Command\HoldsRunLockTrait;
use App\Entity\Activity\ActivityProposal;
use App\Entity\Application\Enums\AlertTypes;
use App\Entity\Application\Enums\NotificationType;
use App\Repository\Activity\ActivityProposalRepository;
use App\Repository\User\UserRepository;
use App\Service\Activity\OptionBudgetSchedule;
use App\Service\Application\Email;
use App\Service\Application\NotificationPublisher;
use App\Service\Application\OfficeMailboxes;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Scheduler\Attribute\AsCronTask;
use Symfony\Component\Workflow\WorkflowInterface;

use function count;
use function sprintf;
use function strval;

/**
 * Releases a day whose owner never settled the financial side, so the next body in line can have it.
 *
 * The rule of the paper calendar: a body that has reserved a day and does not submit its budget loses the day, rather
 * than keeping it until it is too late for anybody else. The old site chased this with an email to the web committee
 * and left the releasing to a human, which is why the calendar filled up with claims nobody was going to use.
 *
 * A proposal the board has settled either way is never touched, and that includes one settled by the board saying
 * there is no budget to approve, because an activity that costs nothing has no budget to submit.
 */
#[AsCommand(
    name: 'app:activity:lapse-overdue-options',
    description: 'Release reserved days whose budget was never settled.',
)]
#[AsCronTask(
    expression: '35 8 * * *',
    transports: 'cron',
)]
final class LapseOverdueOptionsCommand extends Command
{
    use HoldsRunLockTrait;

    public function __construct(
        private readonly Email $email,
        private readonly OfficeMailboxes $mailboxes,
        private readonly ActivityProposalRepository $activityProposalRepository,
        private readonly UserRepository $userRepository,
        private readonly NotificationPublisher $publisher,
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly OptionBudgetSchedule $schedule,
        #[Target('activityProposalStateMachine')]
        private readonly WorkflowInterface $activityProposalStateMachine,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Report which days would be released without releasing them.',
        );
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
        $ui = new SymfonyStyle(
            $input,
            $output,
        );
        $dryRun = true === $input->getOption('dry-run');

        $proposals = $this->activityProposalRepository->findDueToLapse($this->schedule->lapseBefore());

        if ([] === $proposals) {
            $ui->success('No reserved day has run out of road.');

            return Command::SUCCESS;
        }

        $released = 0;
        foreach ($proposals as $proposal) {
            $ui->text(sprintf(
                '%s (%s) on %s',
                $proposal->name,
                $proposal->organ->abbr ?? 'the board',
                $proposal->chosenOption?->beginsAt->format('Y-m-d') ?? '?',
            ));

            if ($dryRun) {
                continue;
            }

            // Nothing should refuse it at this point, but a domain guard added later might, and a sweep that stops
            // on one row would leave the rest of the calendar unreleased. Skip it and log it.
            if (
                !$this->activityProposalStateMachine->can(
                    $proposal,
                    'lapse',
                )
            ) {
                $this->logger->warning(
                    'A reserved day could not be released.',
                    ['proposal' => $proposal->id],
                );

                continue;
            }

            $this->activityProposalStateMachine->apply(
                $proposal,
                'lapse',
            );
            $this->tell($proposal);
            ++$released;
        }

        if ($dryRun) {
            $ui->success(sprintf(
                '%d reserved day(s) would be released.',
                count($proposals),
            ));

            return Command::SUCCESS;
        }

        $this->entityManager->flush();
        $this->tellInternalAffairs($proposals);

        $ui->success(sprintf(
            'Released %d reserved day(s).',
            $released,
        ));

        return Command::SUCCESS;
    }

    /**
     * The Internal Affairs Officer keeps the calendar, so they are notified once about the whole sweep rather than
     * once per body. Each body's own member has already been notified about theirs.
     *
     * @param ActivityProposal[] $proposals
     */
    private function tellInternalAffairs(array $proposals): void
    {
        if ([] === $proposals) {
            return;
        }

        $this->email->send(
            $this->mailboxes->internalAffairs(),
            'Reserved days released from the option calendar',
            'emails/activity/options-lapsed.html.twig',
            ['proposals' => $proposals],
        );
    }

    private function tell(ActivityProposal $proposal): void
    {
        $proposalId = $proposal->id;

        if (null === $proposalId) {
            return;
        }

        $creator = $proposal->getCreatedBy();
        $user = null === $creator
            ? null
            : $this->userRepository->find($creator->lidnr);

        if (null === $user) {
            return;
        }

        $this->publisher->publishFor(
            $user,
            NotificationType::ActivityProposalLapsed,
            [
                'proposal' => strval($proposalId),
                'proposalName' => $proposal->name,
            ],
            AlertTypes::Warning,
        );
    }
}
