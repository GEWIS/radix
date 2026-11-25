<?php

declare(strict_types=1);

namespace App\Tests\Service\Database;

use App\Service\Database\ProspectiveMemberRemoval;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function sprintf;

/**
 * Every outcome of removing a prospective member has to be told apart in the interface: four cases, two of which say
 * that the removal did not happen, and the other two say why it could not.
 */
#[CoversClass(ProspectiveMemberRemoval::class)]
class ProspectiveMemberRemovalTest extends TestCase
{
    #[DataProvider('cases')]
    public function testEveryOutcomeHasAMessageAndADescription(ProspectiveMemberRemoval $removal): void
    {
        $name = $removal->getName();
        $description = $removal->getDescription();

        self::assertNotSame(
            '',
            $name->getMessage(),
        );
        self::assertNotSame(
            '',
            $description->getMessage(),
        );
    }

    /**
     * @return array<string, array{ProspectiveMemberRemoval}>
     */
    public static function cases(): array
    {
        $cases = [];
        foreach (ProspectiveMemberRemoval::cases() as $case) {
            $cases[$case->value] = [$case];
        }

        return $cases;
    }

    /**
     * The two failures to find or refund need action, so they must not look like the two answers that the fee does
     * not allow removal.
     */
    public function testTheRefundProblemsAreDistinctFromTheStateThatBlocksRemoval(): void
    {
        $removed = ProspectiveMemberRemoval::Removed;
        $notRemovable = ProspectiveMemberRemoval::NotRemovable;
        $statusUnknown = ProspectiveMemberRemoval::RefundStatusUnknown;
        $refundFailed = ProspectiveMemberRemoval::RefundFailed;

        self::assertSame(
            $removed->getName()->getMessage(),
            $notRemovable->getName()->getMessage(),
            'Removed and NotRemovable answer the same question in the same words.',
        );

        self::assertNotSame(
            $statusUnknown->getName()->getMessage(),
            $refundFailed->getName()->getMessage(),
        );
        self::assertNotSame(
            $statusUnknown->getDescription()->getMessage(),
            $refundFailed->getDescription()->getMessage(),
        );

        foreach ([$statusUnknown, $refundFailed] as $refundProblem) {
            self::assertNotSame(
                $removed->getName()->getMessage(),
                $refundProblem->getName()->getMessage(),
                sprintf(
                    '%s reads as a plain removal.',
                    $refundProblem->value,
                ),
            );
            self::assertNotSame(
                $removed->getDescription()->getMessage(),
                $refundProblem->getDescription()->getMessage(),
                sprintf(
                    '%s reads as the state-blocked removal.',
                    $refundProblem->value,
                ),
            );
        }
    }

    /**
     * The description tells the reader what to do next, so the refusals that can be retried mention it and the plain
     * state refusal does not.
     */
    public function testTheRefundProblemsSayToTryAgainLater(): void
    {
        self::assertStringContainsString(
            'try again later',
            ProspectiveMemberRemoval::RefundStatusUnknown->getDescription()->getMessage(),
        );
        self::assertStringContainsString(
            'try again later',
            ProspectiveMemberRemoval::RefundFailed->getDescription()->getMessage(),
        );
    }
}
