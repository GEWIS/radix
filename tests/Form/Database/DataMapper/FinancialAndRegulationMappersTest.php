<?php

declare(strict_types=1);

namespace App\Tests\Form\Database\DataMapper;

use App\Entity\Database\Enums\OrganTypes;
use App\Entity\Database\SubDecision\Financial\Budget;
use App\Entity\Database\SubDecision\Financial\Statement;
use App\Entity\Database\SubDecision\OrganRegulation;
use App\Form\Database\DataMapper\BudgetMapper;
use App\Form\Database\DataMapper\OrganRegulationMapper;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function json_encode;

#[CoversClass(BudgetMapper::class)]
#[CoversClass(OrganRegulationMapper::class)]
final class FinancialAndRegulationMappersTest extends TestCase
{
    use MapsDecisions;

    /**
     * A budget and a statement are the same form; the type decides which version of the document is recorded.
     */
    public function testTheTypeDecidesWhetherABudgetOrAStatementIsRecorded(): void
    {
        foreach (
            [
                'budget' => Budget::class,
                'statement' => Statement::class,
            ] as $type => $class
        ) {
            $author = $this->member(8400);
            $date = new DateTimeImmutable('2026-03-01');

            $decision = $this->mapDecision(
                new BudgetMapper(),
                [
                    'type' => $type,
                    'name' => 'Begroting 2026',
                    'date' => $date,
                    'author' => $author,
                    'version' => '1.2',
                    'approve' => 1,
                    'changes' => 1,
                ],
            );

            $subDecisions = $this->subDecisions($decision);
            self::assertCount(
                1,
                $subDecisions,
            );
            self::assertInstanceOf(
                $class,
                $subDecisions[0],
            );
            self::assertSame(
                1,
                $subDecisions[0]->sequence,
            );
            self::assertSame(
                'Begroting 2026',
                $subDecisions[0]->name,
            );
            self::assertSame(
                $date,
                $subDecisions[0]->date,
            );
            self::assertSame(
                '1.2',
                $subDecisions[0]->version,
            );
            self::assertSame(
                $author,
                $subDecisions[0]->getMember(),
            );
            self::assertTrue($subDecisions[0]->approval);
            self::assertTrue($subDecisions[0]->changes);
        }
    }

    /**
     * Approval is a yes only when the form says so; an unchecked or missing answer is a no.
     */
    public function testAnythingButAnExplicitYesIsADisapproval(): void
    {
        $decision = $this->mapDecision(
            new BudgetMapper(),
            [
                'type' => 'statement',
                'name' => 'Jaarrekening',
                'date' => new DateTimeImmutable('2026-03-01'),
                'author' => $this->member(8401),
                'version' => '1.0',
                'approve' => null,
                'changes' => 0,
            ],
        );

        $subDecision = $this->subDecisions($decision)[0];
        self::assertInstanceOf(
            Budget::class,
            $subDecision,
        );
        self::assertFalse($subDecision->approval);
        self::assertFalse($subDecision->changes);
    }

    public function testAnIncompleteFinancialDocumentBuildsNothing(): void
    {
        foreach (
            [
                ['author' => null],
                ['date' => null],
                ['name' => null],
                ['version' => null],
            ] as $overrides
        ) {
            $decision = $this->mapDecision(
                new BudgetMapper(),
                $overrides + [
                    'type' => 'budget',
                    'name' => 'Begroting',
                    'date' => new DateTimeImmutable('2026-03-01'),
                    'author' => $this->member(8402),
                    'version' => '1.0',
                ],
            );

            self::assertCount(
                0,
                $decision->getSubdecisions(),
                (string) json_encode($overrides),
            );
        }
    }

    /**
     * Only committees, fraternities and financial audit committees have a regulation the association approves. The
     * form refuses the other kinds, and the mapper leaves that message as the only error.
     */
    public function testACommitteeRegulationRecordsTheVersionTheBoardApproved(): void
    {
        $author = $this->member(8403);
        $date = new DateTimeImmutable('2026-05-01');

        $decision = $this->mapDecision(
            new OrganRegulationMapper(),
            [
                'type' => OrganTypes::Committee,
                'abbr' => 'TC',
                'date' => $date,
                'author' => $author,
                'version' => '2.0',
                'approve' => 1,
                'changes' => 1,
            ],
        );

        $subDecision = $this->subDecisions($decision)[0];
        self::assertInstanceOf(
            OrganRegulation::class,
            $subDecision,
        );
        self::assertSame(
            1,
            $subDecision->sequence,
        );
        self::assertSame(
            OrganTypes::Committee,
            $subDecision->organType,
        );
        self::assertSame(
            'TC',
            $subDecision->abbr,
        );
        self::assertSame(
            $date,
            $subDecision->date,
        );
        self::assertSame(
            '2.0',
            $subDecision->version,
        );
        self::assertSame(
            $author,
            $subDecision->getMember(),
        );
        self::assertTrue($subDecision->approval);
        self::assertTrue($subDecision->changes);
    }

    public function testAnOrganKindWithoutRegulationsGetsNone(): void
    {
        $decision = $this->mapDecision(
            new OrganRegulationMapper(),
            [
                'type' => OrganTypes::SC,
                'abbr' => 'SC',
                'date' => new DateTimeImmutable('2026-05-01'),
                'author' => $this->member(8404),
                'version' => '1.0',
            ],
        );

        self::assertCount(
            0,
            $decision->getSubdecisions(),
        );
    }

    public function testAnIncompleteRegulationBuildsNothing(): void
    {
        foreach (
            [
                ['type' => null],
                ['abbr' => null],
                ['date' => null],
                ['author' => null],
                ['version' => null],
            ] as $overrides
        ) {
            $decision = $this->mapDecision(
                new OrganRegulationMapper(),
                $overrides + [
                    'type' => OrganTypes::Committee,
                    'abbr' => 'TC',
                    'date' => new DateTimeImmutable('2026-05-01'),
                    'author' => $this->member(8405),
                    'version' => '1.0',
                ],
            );

            self::assertCount(
                0,
                $decision->getSubdecisions(),
                (string) json_encode($overrides),
            );
        }
    }
}
