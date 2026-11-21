<?php

declare(strict_types=1);

namespace App\Tests\Entity\Database\SubDecision;

use App\Entity\Application\Enums\AppLanguages;
use App\Entity\Database\Enums\BoardFunctions;
use App\Entity\Database\Member;
use App\Entity\Database\SubDecision\Board\Candidacy;
use App\Entity\Database\SubDecision\Board\Candidate;
use App\Entity\Database\SubDecision\Board\Discharge;
use App\Entity\Database\SubDecision\Board\Installation;
use App\Entity\Database\SubDecision\Board\Release;
use App\Tests\Support\BuildsDecisions;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Candidacy::class)]
#[CoversClass(Candidate::class)]
#[CoversClass(Discharge::class)]
#[CoversClass(Installation::class)]
#[CoversClass(Release::class)]
final class BoardActionsTest extends TestCase
{
    use BuildsDecisions;

    /**
     * The year is written once, as the association year it covers, and the candidates follow it as names only.
     */
    public function testACandidacyNamesTheAssociationYearAndTheCandidatesItOpens(): void
    {
        $candidacy = new Candidacy();
        $candidacy->sequence = 1;
        $candidacy->boardYear = 2026;
        $candidacy->setDecision($this->decision());

        self::assertSame(
            'Het bestuur stelt, op constitutievolgorde, de volgende kandidaten voor het bestuur 2026 - 2027:',
            $candidacy->getTranslatedContent(
                $this->translator(),
                AppLanguages::Dutch,
            ),
        );

        $candidate = new Candidate();
        $candidate->sequence = 2;
        $candidate->setMember($this->member('Test', 'Lid'));
        $candidate->setDecision($this->decision());

        self::assertSame(
            'Test Lid.',
            $candidate->getTranslatedContent(
                $this->translator(),
                AppLanguages::Dutch,
            ),
        );
    }

    /**
     * A board installation and release are dated on their own: a board change decided at a meeting still takes effect
     * on the constitutional date, not on the day of the meeting.
     */
    public function testABoardInstallationAndReleaseAreDated(): void
    {
        $installation = $this->installation();
        $installation->date = new DateTimeImmutable('2026-09-01');

        self::assertSame(
            'Test Lid wordt per 1 september 2026 geïnstalleerd als Voorzitter der s.v. GEWIS.',
            $installation->getTranslatedContent(
                $this->translator(),
                AppLanguages::Dutch,
            ),
        );

        $release = new Release();
        $release->sequence = 1;
        $release->installation = $installation;
        $release->date = new DateTimeImmutable('2027-09-01');
        $release->setDecision($this->decision());

        self::assertSame(
            'Test Lid wordt per 1 september 2027 ontheven uit de functie van Voorzitter der s.v. GEWIS.',
            $release->getTranslatedContent(
                $this->translator(),
                AppLanguages::Dutch,
            ),
        );
    }

    public function testABoardDischargeReleasesTheInstallationWithoutADate(): void
    {
        $discharge = new Discharge();
        $discharge->sequence = 1;
        $discharge->installation = $this->installation();
        $discharge->setDecision($this->decision());

        self::assertSame(
            'Test Lid wordt gedechargeerd als Voorzitter der s.v. GEWIS.',
            $discharge->getTranslatedContent(
                $this->translator(),
                AppLanguages::Dutch,
            ),
        );
    }

    public function testABoardInstallationIsReadInTheLanguageAsked(): void
    {
        $installation = $this->installation();
        $installation->date = new DateTimeImmutable('2026-09-01');

        self::assertSame(
            'Test Lid wordt per 1 September 2026 geïnstalleerd als Voorzitter der s.v. GEWIS.',
            $installation->getTranslatedContent(
                $this->translator(),
                AppLanguages::English,
            ),
        );
    }

    private function installation(): Installation
    {
        $installation = new Installation();
        $installation->sequence = 1;
        $installation->setMember($this->member('Test', 'Lid'));
        $installation->function = BoardFunctions::Chair;
        $installation->date = new DateTimeImmutable('2026-09-01');
        $installation->setDecision($this->decision());

        return $installation;
    }

    private function member(
        string $firstName,
        string $lastName,
    ): Member {
        $member = new Member();
        $member->lidnr = 8100;
        $member->initials = 'T.';
        $member->firstName = $firstName;
        $member->middleName = '';
        $member->lastName = $lastName;
        $member->changedOn = new DateTimeImmutable('2026-01-01');

        return $member;
    }
}
