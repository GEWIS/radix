<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\Activity;

use App\Command\Activity\RemindOptionBudgetCommand;
use App\Entity\Activity\ActivityDateOption;
use App\Entity\Activity\ActivityProposal;
use App\Entity\Activity\Enums\ProposalStatus;
use App\Entity\Application\Enums\AlertTypes;
use App\Entity\Application\Enums\NotificationType;
use App\Entity\Application\Notification;
use App\Entity\Decision\Member;
use App\Entity\User\User;
use App\Repository\Activity\ActivityProposalRepository;
use App\Repository\User\UserRepository;
use App\Service\Activity\OptionBudgetSchedule;
use App\Service\Application\NotificationChannelInterface;
use App\Service\Application\NotificationPublisher;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;

use function count;
use function intval;
use function strval;

/**
 * The reminder is the warning before a reserved day is released. It is sent once, only for a day within the policy's
 * four weeks with no budget recorded.
 */
final class RemindOptionBudgetCommandTest extends DatabaseTestCase
{
    /** @var list<Notification> */
    private array $delivered = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->delivered = [];
    }

    /**
     * The seed reserves a day forty days out with nothing recorded about its budget, which is past the four weeks the
     * policy counts back: nobody is warned about it yet.
     */
    public function testADayStillWeeksAwayIsLeftAlone(): void
    {
        $tester = $this->execute([]);

        self::assertSame(
            [],
            $this->delivered,
        );
        self::assertStringContainsString(
            'No reserved day is at risk.',
            $tester->getDisplay(),
        );
    }

    public function testADayAtRiskWarnsTheMemberWhoProposedIt(): void
    {
        $proposal = $this->aDayAtRiskInsideTheWindow();
        $userId = $proposal->getCreatedBy()?->lidnr;

        $this->execute([]);

        self::assertCount(
            1,
            $this->delivered,
        );
        $notification = $this->delivered[0];
        self::assertSame(
            NotificationType::ActivityProposalBudgetDue,
            $notification->type,
        );
        self::assertSame(
            AlertTypes::Warning,
            $notification->level,
        );
        self::assertNotNull($userId);
        self::assertSame(
            $userId,
            $notification->recipientUser?->lidnr,
            'Notifications reach an account, and the submitter is the one who can find out what happened.',
        );
        self::assertSame(
            strval($proposal->id),
            $notification->context['proposal'] ?? null,
        );
        self::assertSame(
            $proposal->name,
            $notification->context['proposalName'] ?? null,
        );
        self::assertNotNull(
            $this->reread($proposal)->budgetRemindedAt,
            'Once warned, a body is not warned again every night.',
        );
    }

    /**
     * A nightly reminder would repeat, so a body that was warned once is not warned again.
     */
    public function testTheSecondNightSaysNothingFurther(): void
    {
        $this->aDayAtRiskInsideTheWindow();

        $this->execute([]);
        $after = count($this->delivered);
        $this->execute([]);

        self::assertCount(
            $after,
            $this->delivered,
        );
    }

    public function testADryRunNamesWhatWouldBeReportedWithoutTellingAnybody(): void
    {
        $proposal = $this->aDayAtRiskInsideTheWindow();

        $tester = $this->execute(['--dry-run' => true]);

        self::assertSame(
            [],
            $this->delivered,
        );
        self::assertNull(
            $this->reread($proposal)->budgetRemindedAt,
        );
        self::assertStringContainsString(
            '1 reserved day(s) would be reported as at risk.',
            $tester->getDisplay(),
        );
    }

    /**
     * A body whose member has no account still releases its day on time; nobody is warned, and the proposal is
     * stamped rather than checked every night.
     */
    public function testAProposalWhoseSubmitterHasNoAccountIsStillStamped(): void
    {
        $proposal = $this->aDayAtRiskInsideTheWindow();
        $creator = $proposal->getCreatedBy();
        self::assertInstanceOf(
            Member::class,
            $creator,
        );
        $account = self::getContainer()->get(UserRepository::class)->find($creator->lidnr);
        self::assertInstanceOf(
            User::class,
            $account,
            'The seed is expected to give the submitter an account to take away.',
        );
        $this->entityManager->remove($account);
        $this->entityManager->flush();

        $this->execute([]);

        self::assertSame(
            [],
            $this->delivered,
        );
        self::assertNotNull(
            $this->reread($proposal)->budgetRemindedAt,
        );
    }

    /**
     * A date is in the window when it is within the policy's four weeks; the warning concerns a day still to come.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function datesAroundTheWindow(): iterable
    {
        yield 'well inside the four weeks' => [
            '+10 days',
            true,
        ];

        yield 'just inside the four weeks' => [
            '+27 days',
            true,
        ];

        yield 'just outside the four weeks' => [
            '+29 days',
            false,
        ];
    }

    #[DataProvider('datesAroundTheWindow')]
    public function testTheWindowIsThePolicyFourWeeks(
        string $offset,
        bool $warned,
    ): void {
        $proposal = $this->aDayAtRiskInsideTheWindow($offset);

        $this->execute([]);

        self::assertSame(
            $warned,
            [] !== $this->delivered,
        );
        self::assertSame(
            $warned,
            null !== $this->reread($proposal)->budgetRemindedAt,
        );
    }

    /**
     * The seed's reserved date, moved inside the window. Nothing about its budget is recorded, which is the state the
     * command exists to find.
     */
    private function aDayAtRiskInsideTheWindow(string $offset = '+10 days'): ActivityProposal
    {
        $proposal = $this->entityManager->getRepository(ActivityProposal::class)->findOneBy([
            'status' => ProposalStatus::Scheduled,
        ]);
        self::assertInstanceOf(
            ActivityProposal::class,
            $proposal,
            'The seed is expected to contain a proposal that reserved a day.',
        );

        $option = $proposal->chosenOption;
        self::assertInstanceOf(
            ActivityDateOption::class,
            $option,
        );

        $beginsAt = new DateTimeImmutable($offset);
        $option->beginsAt = $beginsAt;
        $option->endsAt = $beginsAt;
        $proposal->budgetClearance = null;
        $proposal->budgetRemindedAt = null;
        $this->entityManager->flush();

        return $proposal;
    }

    /**
     * @param array<string, bool|string> $input
     */
    private function execute(array $input): CommandTester
    {
        $command = new RemindOptionBudgetCommand(
            self::getContainer()->get(ActivityProposalRepository::class),
            self::getContainer()->get(UserRepository::class),
            $this->publisher(),
            $this->entityManager,
            new OptionBudgetSchedule(),
        );

        $tester = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }

    /**
     * The real publisher with a recording channel, so the test checks the notification raised rather than its
     * delivery.
     */
    private function publisher(): NotificationPublisher
    {
        $channel = self::createStub(NotificationChannelInterface::class);
        $channel->method('deliver')->willReturnCallback(
            function (Notification $notification): void {
                $this->delivered[] = $notification;
            },
        );

        return new NotificationPublisher(
            $this->entityManager,
            new NullLogger(),
            [$channel],
        );
    }

    private function reread(ActivityProposal $proposal): ActivityProposal
    {
        $id = intval($proposal->id);
        $this->entityManager->clear();
        $fresh = $this->entityManager->getRepository(ActivityProposal::class)->find($id);
        self::assertInstanceOf(
            ActivityProposal::class,
            $fresh,
        );

        return $fresh;
    }
}
