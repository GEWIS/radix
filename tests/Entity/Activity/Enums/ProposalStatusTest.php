<?php

declare(strict_types=1);

namespace App\Tests\Entity\Activity\Enums;

use App\Entity\Activity\Enums\ProposalStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

use function count;

#[CoversClass(ProposalStatus::class)]
final class ProposalStatusTest extends TestCase
{
    /**
     * A proposal reserves a day once the board approves it; a pending proposal has no day, and a declined or
     * withdrawn one releases its days.
     */
    #[DataProvider('states')]
    public function testADateIsHeldOnlyOnceItIsReserved(
        ProposalStatus $status,
        bool $holdsADate,
        bool $countsTowardsAllowance,
        bool $editable,
        string $badge,
    ): void {
        self::assertSame(
            $holdsADate,
            $status->holdsADate(),
            $status->value,
        );
        self::assertSame(
            $countsTowardsAllowance,
            $status->countsTowardsAllowance(),
            $status->value,
        );
        self::assertSame(
            $editable,
            $status->isEditableByAuthor(),
            $status->value,
        );
        self::assertSame(
            $badge,
            $status->badgeClass(),
            $status->value,
        );
    }

    /**
     * @return iterable<string, array{ProposalStatus, bool, bool, bool, string}>
     */
    public static function states(): iterable
    {
        yield 'submitted' => [
            ProposalStatus::Submitted,
            false,
            true,
            true,
            'badge-info',
        ];

        yield 'scheduled' => [
            ProposalStatus::Scheduled,
            true,
            true,
            false,
            'badge-primary',
        ];

        yield 'cleared' => [
            ProposalStatus::Cleared,
            true,
            true,
            false,
            'badge-success',
        ];

        yield 'declined' => [
            ProposalStatus::Declined,
            false,
            false,
            false,
            'badge-danger',
        ];

        yield 'withdrawn' => [
            ProposalStatus::Withdrawn,
            false,
            false,
            false,
            'badge-secondary',
        ];

        yield 'lapsed' => [
            ProposalStatus::Lapsed,
            false,
            false,
            false,
            'badge-secondary',
        ];
    }

    /**
     * The DQL helpers repeat the decisions above, so a mismatch would make the queue and the policy disagree.
     */
    public function testTheQueryHelpersMatchTheDecisions(): void
    {
        $holding = [];
        $counting = [];
        foreach (ProposalStatus::cases() as $status) {
            if ($status->holdsADate()) {
                $holding[] = $status;
            }

            if (!$status->countsTowardsAllowance()) {
                continue;
            }

            $counting[] = $status;
        }

        self::assertSame(
            $holding,
            ProposalStatus::holdingADate(),
        );
        self::assertSame(
            $counting,
            ProposalStatus::countingTowardsAllowance(),
        );
        self::assertSame(
            [
                ProposalStatus::Submitted,
                ProposalStatus::Scheduled,
                ProposalStatus::Cleared,
            ],
            ProposalStatus::decidableCases(),
        );
    }

    public function testEveryStatusHasItsOwnLabel(): void
    {
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $labels = [];
        foreach (ProposalStatus::cases() as $status) {
            $label = $status->trans($translator);
            self::assertNotSame(
                '',
                $label,
            );
            $labels[$label] = true;
        }

        self::assertCount(
            count(ProposalStatus::cases()),
            $labels,
        );
    }
}
