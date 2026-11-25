<?php

declare(strict_types=1);

namespace App\Tests\Entity\Database\SubDecision;

use App\Entity\Application\Enums\AppLanguages;
use App\Entity\Database\Enums\OrganTypes;
use App\Entity\Database\Member;
use App\Entity\Database\SubDecision\OrganRegulation;
use App\Tests\Support\BuildsDecisions;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ValueError;

#[CoversClass(OrganRegulation::class)]
final class OrganRegulationTest extends TestCase
{
    use BuildsDecisions;

    /**
     * The document is a regulation of a committee, a fraternity or the financial audit committee; those are the kinds
     * the association can adopt one for.
     */
    public function testTheDocumentKindFollowsTheOrganKind(): void
    {
        self::assertSame(
            'Het commissiereglement van TC door Test Lid, versie 2.0 van 1 maart 2026 wordt goedgekeurd.',
            $this->regulation(OrganTypes::Committee)->getTranslatedContent(
                $this->translator(),
                AppLanguages::Dutch,
            ),
        );
        self::assertSame(
            'Het commissiereglement van KCC door Test Lid, versie 2.0 van 1 maart 2026 wordt goedgekeurd.',
            $this->regulation(OrganTypes::KCC)->getTranslatedContent(
                $this->translator(),
                AppLanguages::Dutch,
            ),
        );
        self::assertSame(
            'Het dispuutsreglement van DZ door Test Lid, versie 2.0 van 1 maart 2026 wordt goedgekeurd.',
            $this->regulation(OrganTypes::Fraternity)->getTranslatedContent(
                $this->translator(),
                AppLanguages::Dutch,
            ),
        );
    }

    /**
     * A body that cannot have a regulation has no wording for one; refusing loudly is better than recording a
     * regulation under a name that does not exist.
     */
    public function testAnOrganKindWithoutRegulationsIsRefused(): void
    {
        $this->expectException(ValueError::class);

        $this->regulation(OrganTypes::SC)->getTranslatedContent(
            $this->translator(),
            AppLanguages::Dutch,
        );
    }

    public function testADisapprovedRegulationDoesNotMentionChanges(): void
    {
        $regulation = $this->regulation(OrganTypes::Committee);
        $regulation->approval = false;
        $regulation->changes = true;

        self::assertSame(
            'Het commissiereglement van TC door Test Lid, versie 2.0 van 1 maart 2026 wordt afgekeurd.',
            $regulation->getTranslatedContent(
                $this->translator(),
                AppLanguages::Dutch,
            ),
        );
    }

    public function testAnApprovedChangedRegulationSaysSo(): void
    {
        $regulation = $this->regulation(OrganTypes::Committee);
        $regulation->changes = true;

        self::assertSame(
            'Het commissiereglement van TC door Test Lid, versie 2.0 van 1 maart 2026 wordt goedgekeurd met '
            . 'genoemde wijzigingen.',
            $regulation->getTranslatedContent(
                $this->translator(),
                AppLanguages::Dutch,
            ),
        );
    }

    /**
     * The date follows the language the decision is read in, not the language it was entered in.
     */
    public function testTheDateFollowsTheLanguage(): void
    {
        self::assertSame(
            'Het commissiereglement van TC door Test Lid, versie 2.0 van 1 March 2026 wordt goedgekeurd.',
            $this->regulation(OrganTypes::Committee)->getTranslatedContent(
                $this->translator(),
                AppLanguages::English,
            ),
        );
    }

    private function regulation(OrganTypes $type): OrganRegulation
    {
        $regulation = new OrganRegulation();
        $regulation->abbr = match ($type) {
            OrganTypes::Fraternity => 'DZ',
            OrganTypes::KCC => 'KCC',
            default => 'TC',
        };
        $regulation->organType = $type;
        $regulation->version = '2.0';
        $regulation->date = new DateTimeImmutable('2026-03-01');
        $regulation->approval = true;
        $regulation->changes = false;
        $regulation->sequence = 1;
        $regulation->setMember($this->member());
        $regulation->setDecision($this->decision());

        return $regulation;
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
