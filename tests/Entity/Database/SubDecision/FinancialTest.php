<?php

declare(strict_types=1);

namespace App\Tests\Entity\Database\SubDecision;

use App\Entity\Application\Enums\AppLanguages;
use App\Entity\Database\Member;
use App\Entity\Database\SubDecision\Financial\Budget;
use App\Entity\Database\SubDecision\Financial\Statement;
use App\Tests\Support\BuildsDecisions;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Budget::class)]
#[CoversClass(Statement::class)]
final class FinancialTest extends TestCase
{
    use BuildsDecisions;

    /**
     * A budget and a statement differ in the word used, and in nothing else: both are versioned documents of a date
     * that the meeting approved or rejected.
     */
    public function testABudgetAndAStatementUseTheirOwnWords(): void
    {
        self::assertSame(
            'De begroting Begroting 2027 van Test Lid, versie 1.2 van 1 maart 2026 wordt goedgekeurd met genoemde '
            . 'wijzigingen.',
            $this->document(new Budget())->getTranslatedContent(
                $this->translator(),
                AppLanguages::Dutch,
            ),
        );

        self::assertSame(
            'De afrekening Jaarrekening 2026 van Test Lid, versie 1.2 van 1 maart 2026 wordt goedgekeurd met '
            . 'genoemde wijzigingen.',
            $this->document(
                new Statement(),
                'Jaarrekening 2026',
            )->getTranslatedContent(
                $this->translator(),
                AppLanguages::Dutch,
            ),
        );
    }

    /**
     * Old documents have no author on record. They are named unknown so the sentence stays readable.
     */
    public function testADocumentWithoutAnAuthorSaysTheAuthorIsUnknown(): void
    {
        $budget = $this->document(
            new Budget(),
            withAuthor: false,
        );

        self::assertSame(
            'De begroting Begroting 2027 van onbekend, versie 1.2 van 1 maart 2026 wordt goedgekeurd met genoemde '
            . 'wijzigingen.',
            $budget->getTranslatedContent(
                $this->translator(),
                AppLanguages::Dutch,
            ),
        );
    }

    /**
     * A rejected version has no changes in force, even if the field was left ticked; the approval is what makes a
     * version the one the changes are against.
     */
    public function testARejectedDocumentDoesNotMentionChanges(): void
    {
        $budget = $this->document(new Budget());
        $budget->approval = false;

        self::assertSame(
            'De begroting Begroting 2027 van Test Lid, versie 1.2 van 1 maart 2026 wordt afgekeurd.',
            $budget->getTranslatedContent(
                $this->translator(),
                AppLanguages::Dutch,
            ),
        );
    }

    public function testTheDocumentDateIsReadInTheLanguage(): void
    {
        self::assertSame(
            'De begroting Begroting 2027 van Test Lid, versie 1.2 van 1 March 2026 wordt goedgekeurd met genoemde '
            . 'wijzigingen.',
            $this->document(new Budget())->getTranslatedContent(
                $this->translator(),
                AppLanguages::English,
            ),
        );
    }

    private function document(
        Budget $document,
        string $name = 'Begroting 2027',
        bool $withAuthor = true,
    ): Budget {
        $document->name = $name;
        $document->version = '1.2';
        $document->date = new DateTimeImmutable('2026-03-01');
        $document->approval = true;
        $document->changes = true;
        $document->sequence = 1;
        $document->setDecision($this->decision());

        if ($withAuthor) {
            $document->setMember($this->member());
        }

        return $document;
    }

    private function member(): Member
    {
        $member = new Member();
        $member->lidnr = 8100;
        $member->initials = 'T.';
        $member->firstName = 'Test';
        $member->middleName = '';
        $member->lastName = 'Lid';
        $member->changedOn = new DateTimeImmutable('2026-01-01');

        return $member;
    }
}
