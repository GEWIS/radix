<?php

declare(strict_types=1);

namespace App\Tests\Entity\Database\SubDecision;

use App\Entity\Application\Enums\AppLanguages;
use App\Entity\Database\Enums\InstallationFunctions;
use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Database\Enums\OrganTypes;
use App\Entity\Database\Meeting;
use App\Entity\Database\Member;
use App\Entity\Database\SubDecision\Abrogation;
use App\Entity\Database\SubDecision\Continuation;
use App\Entity\Database\SubDecision\Discharge;
use App\Entity\Database\SubDecision\Foundation;
use App\Entity\Database\SubDecision\Installation;
use App\Entity\Database\SubDecision\Key\Granting;
use App\Entity\Database\SubDecision\Key\Withdrawal;
use App\Entity\Database\SubDecision\Minutes;
use App\Entity\Database\SubDecision\Reappointment;
use App\Tests\Support\BuildsDecisions;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Abrogation::class)]
#[CoversClass(Continuation::class)]
#[CoversClass(Discharge::class)]
#[CoversClass(Installation::class)]
#[CoversClass(Reappointment::class)]
#[CoversClass(Granting::class)]
#[CoversClass(Withdrawal::class)]
#[CoversClass(Minutes::class)]
final class MembershipActionsTest extends TestCase
{
    use BuildsDecisions;

    /**
     * A continuation is a statement about the body itself, and an abrogation ends it; both name the foundation rather
     * than repeating its details.
     */
    public function testAContinuationKeepsTheBodyAndAnAbrogationEndsIt(): void
    {
        $foundation = $this->foundation();

        $continuation = new Continuation();
        $continuation->sequence = 1;
        $continuation->foundation = $foundation;
        $continuation->setDecision($this->decision());

        self::assertSame(
            'Commissie TC mag blijven voortbestaan.',
            $continuation->getTranslatedContent(
                $this->translator(),
                AppLanguages::Dutch,
            ),
        );

        $abrogation = new Abrogation();
        $abrogation->sequence = 1;
        $abrogation->foundation = $foundation;
        $abrogation->setDecision($this->decision());

        self::assertSame(
            'Commissie TC wordt opgeheven.',
            $abrogation->getTranslatedContent(
                $this->translator(),
                AppLanguages::Dutch,
            ),
        );
    }

    /**
     * Reappointment, installation and discharge all refer to an installation, so the member, function and organ come
     * from it rather than from the sub-decision.
     */
    public function testAppointmentChangesReadFromTheInstallation(): void
    {
        $installation = $this->installation();

        $reappointment = new Reappointment();
        $reappointment->sequence = 1;
        $reappointment->installation = $installation;
        $reappointment->setDecision($this->decision());

        self::assertSame(
            'Test Lid wordt herbenoemd als Voorzitter van TC.',
            $reappointment->getTranslatedContent(
                $this->translator(),
                AppLanguages::Dutch,
            ),
        );

        self::assertSame(
            'Test Lid wordt geïnstalleerd als Voorzitter van TC.',
            $installation->getTranslatedContent(
                $this->translator(),
                AppLanguages::Dutch,
            ),
        );

        $discharge = new Discharge();
        $discharge->sequence = 1;
        $discharge->installation = $installation;
        $discharge->setDecision($this->decision());

        self::assertSame(
            'Test Lid wordt gedechargeerd als Voorzitter van TC.',
            $discharge->getTranslatedContent(
                $this->translator(),
                AppLanguages::Dutch,
            ),
        );
    }

    /**
     * A key is granted until a date and withdrawn from a date; the withdrawal looks the grantee up through the
     * granting, which is what keeps the two statements about the same person.
     */
    public function testAKeyGrantAndWithdrawalSayWhenTheKeyStartsAndEnds(): void
    {
        $granting = new Granting();
        $granting->sequence = 1;
        $granting->setMember($this->member());
        $granting->until = new DateTimeImmutable('2026-12-01');
        $granting->setDecision($this->decision());

        self::assertSame(
            'Test Lid krijgt een sleutelcode van GEWIS tot en met 1 december 2026.',
            $granting->getTranslatedContent(
                $this->translator(),
                AppLanguages::Dutch,
            ),
        );

        $withdrawal = new Withdrawal();
        $withdrawal->sequence = 1;
        $withdrawal->granting = $granting;
        $withdrawal->withdrawnOn = new DateTimeImmutable('2026-11-01');
        $withdrawal->setDecision($this->decision());

        self::assertSame(
            'De sleutelcode van Test Lid van GEWIS wordt ingetrokken vanaf 1 november 2026.',
            $withdrawal->getTranslatedContent(
                $this->translator(),
                AppLanguages::Dutch,
            ),
        );
    }

    /**
     * A board meeting approves its minutes with thanks to its own notulist; any other meeting names a separate author
     * instead.
     */
    public function testMinutesOfABoardMeetingThankTheNotulistAndOthersNameTheAuthor(): void
    {
        $boardMeeting = $this->meeting(
            MeetingTypes::BV,
            12,
            '2026-08-20',
        );
        $minutes = $this->minutes(
            $boardMeeting,
            MeetingTypes::BV,
            12,
        );

        self::assertSame(
            'De notulen van de 12e BV worden goedgekeurd met dank aan de notulist.',
            $minutes->getTranslatedContent(
                $this->translator(),
                AppLanguages::Dutch,
            ),
        );

        $alv = $this->meeting(
            MeetingTypes::ALV,
            42,
            '2026-06-01',
        );
        $minutes = $this->minutes(
            $alv,
            MeetingTypes::ALV,
            42,
        );
        $minutes->changes = true;

        self::assertSame(
            'De notulen van de 42e ALV door Test Lid worden goedgekeurd met genoemde wijzigingen.',
            $minutes->getTranslatedContent(
                $this->translator(),
                AppLanguages::Dutch,
            ),
        );
    }

    public function testTheOrdinalOfTheMeetingFollowsTheLanguage(): void
    {
        $alv = $this->meeting(
            MeetingTypes::ALV,
            42,
            '2026-06-01',
        );
        $minutes = $this->minutes(
            $alv,
            MeetingTypes::ALV,
            42,
        );

        self::assertSame(
            'De notulen van de 42nd ALV door Test Lid worden goedgekeurd.',
            $minutes->getTranslatedContent(
                $this->translator(),
                AppLanguages::English,
            ),
        );
    }

    private function minutes(
        Meeting $meeting,
        MeetingTypes $type,
        int $number,
    ): Minutes {
        $minutes = new Minutes();
        $minutes->sequence = 1;
        $minutes->approval = true;
        $minutes->changes = false;
        $minutes->setTarget($meeting);
        $minutes->setMember($this->member());
        $minutes->setDecision($this->decision());

        return $minutes;
    }

    private function foundation(): Foundation
    {
        $foundation = new Foundation();
        $foundation->abbr = 'TC';
        $foundation->name = 'Taartcommissie';
        $foundation->organType = OrganTypes::Committee;
        $foundation->sequence = 1;
        $foundation->setDecision($this->decision());

        return $foundation;
    }

    private function installation(): Installation
    {
        $installation = new Installation();
        $installation->foundation = $this->foundation();
        $installation->sequence = 1;
        $installation->function = InstallationFunctions::Chair;
        $installation->setMember($this->member());
        $installation->setDecision($this->decision());

        return $installation;
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
