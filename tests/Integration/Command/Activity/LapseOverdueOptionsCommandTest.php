<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\Activity;

use App\Entity\Activity\ActivityProposal;
use App\Entity\Activity\Enums\BudgetClearance;
use App\Entity\Activity\Enums\ProposalStatus;
use App\Service\Activity\OptionBudgetSchedule;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

use function sprintf;

/**
 * The nightly sweep that releases a day whose owner never settled the financial side.
 *
 * The two things worth pinning are that it runs at all with no user signed in, since the transition it applies is the
 * board's, and that a settled proposal is never touched however close its day is, including one settled by the board
 * saying there is no budget to approve. A free activity losing its day for a budget it was never going to submit is
 * exactly the wrong outcome.
 */
final class LapseOverdueOptionsCommandTest extends DatabaseTestCase
{
    public function testADayNobodySettledIsReleased(): void
    {
        $proposal = $this->aProposalHoldingADayIn(3);

        $this->sweep();
        $this->entityManager->refresh($proposal);

        self::assertSame(
            ProposalStatus::Lapsed,
            $proposal->status,
        );

        foreach ($proposal->getDateOptions() as $dateOption) {
            self::assertFalse(
                $dateOption->status->isStanding(),
                'A released day has to be out of everybody else\'s way.',
            );
        }
    }

    public function testAnApprovedBudgetKeepsTheDay(): void
    {
        $proposal = $this->aProposalHoldingADayIn(3);
        $this->settle(
            $proposal,
            BudgetClearance::Approved,
        );

        $this->sweep();
        $this->entityManager->refresh($proposal);

        self::assertSame(
            ProposalStatus::Cleared,
            $proposal->status,
        );
    }

    public function testAnActivityThatCostsNothingKeepsTheDay(): void
    {
        $proposal = $this->aProposalHoldingADayIn(3);
        $this->settle(
            $proposal,
            BudgetClearance::NotRequired,
        );

        $this->sweep();
        $this->entityManager->refresh($proposal);

        self::assertSame(
            ProposalStatus::Cleared,
            $proposal->status,
            'An activity with no budget to hand in must never lose its day over one.',
        );
    }

    public function testADayStillFarEnoughAwayIsLeftAlone(): void
    {
        $proposal = $this->aProposalHoldingADayIn(OptionBudgetSchedule::LEAD_DAYS + 10);

        $this->sweep();
        $this->entityManager->refresh($proposal);

        self::assertSame(
            ProposalStatus::Scheduled,
            $proposal->status,
        );
    }

    public function testADryRunChangesNothing(): void
    {
        $proposal = $this->aProposalHoldingADayIn(3);

        $this->sweep(['--dry-run' => true]);
        $this->entityManager->refresh($proposal);

        self::assertSame(
            ProposalStatus::Scheduled,
            $proposal->status,
        );
    }

    /**
     * @param array<string, bool|string> $input
     */
    private function sweep(array $input = []): void
    {
        // The kernel already booted by the base class, not a fresh one: rebooting would give the command a second
        // entity manager and detach everything this test has loaded.
        $command = new Application(self::getContainer()->get('kernel'))
            ->find('app:activity:lapse-overdue-options');
        $tester = new CommandTester($command);
        $tester->execute($input);
        $tester->assertCommandIsSuccessful();
    }

    private function aProposalHoldingADayIn(int $days): ActivityProposal
    {
        $proposal = $this->entityManager->getRepository(ActivityProposal::class)->findOneBy([
            'status' => ProposalStatus::Scheduled,
        ]);
        self::assertInstanceOf(
            ActivityProposal::class,
            $proposal,
        );

        $option = $proposal->chosenOption;
        self::assertNotNull($option);

        $day = new DateTimeImmutable(sprintf(
            '+%d days',
            $days,
        ));
        $option->beginsAt = $day;
        $option->endsAt = $day;
        $this->entityManager->flush();

        return $proposal;
    }

    private function settle(
        ActivityProposal $proposal,
        BudgetClearance $clearance,
    ): void {
        $proposal->status = ProposalStatus::Cleared;
        $proposal->budgetClearance = $clearance;
        $proposal->budgetClearedAt = new DateTimeImmutable();
        $this->entityManager->flush();
    }
}
