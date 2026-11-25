<?php

declare(strict_types=1);

namespace App\Tests\ViewModel\Checker;

use App\Entity\Database\Decision;
use App\Entity\Database\Enums\InstallationFunctions;
use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Database\Enums\OrganTypes;
use App\Entity\Database\Meeting;
use App\Entity\Database\Member;
use App\Entity\Database\SubDecision\Annulment;
use App\Entity\Database\SubDecision\Financial\Budget;
use App\Entity\Database\SubDecision\Financial\Statement;
use App\Entity\Database\SubDecision\Foundation;
use App\Entity\Database\SubDecision\Installation;
use App\Entity\Database\SubDecision\Key\Granting;
use App\Entity\Database\SubDecision\Key\Withdrawal;
use App\Entity\Database\SubDecision\OrganRegulation;
use App\Tests\Support\BuildsDecisions;
use App\ViewModel\Checker\Error;
use App\ViewModel\Checker\Error\AnnulmentOfAnnulment;
use App\ViewModel\Checker\Error\AnnulmentOfLaterDecision;
use App\ViewModel\Checker\Error\DecisionAnnulledMoreThanOnce;
use App\ViewModel\Checker\Error\DocumentDatedAfterMeeting;
use App\ViewModel\Checker\Error\KeyGrantedInThePast;
use App\ViewModel\Checker\Error\KeyGrantedLongerThanOneYear;
use App\ViewModel\Checker\Error\KeyGrantedPastBoundary;
use App\ViewModel\Checker\Error\KeyWithdrawnPastOriginalGranting;
use App\ViewModel\Checker\Error\MemberActiveAndInactiveInOrgan;
use App\ViewModel\Checker\Error\MemberActiveWithRoleAndInactiveInOrgan;
use App\ViewModel\Checker\Error\MemberExpiredButStillInOrgan;
use App\ViewModel\Checker\Error\MemberHasRoleButNotInOrgan;
use App\ViewModel\Checker\Error\MemberInactiveInOrganButHasOtherRole;
use App\ViewModel\Checker\Error\MemberInactiveInOrganWithoutInactiveMembers;
use App\ViewModel\Checker\Error\MemberInNonExistingOrgan;
use App\ViewModel\Checker\Error\OrganMeetingType;
use App\ViewModel\Checker\Error\OrganTooSmall;
use App\ViewModel\Checker\Error\OrganWithoutChair;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Error::class)]
#[CoversClass(AnnulmentOfAnnulment::class)]
#[CoversClass(AnnulmentOfLaterDecision::class)]
#[CoversClass(DecisionAnnulledMoreThanOnce::class)]
#[CoversClass(DocumentDatedAfterMeeting::class)]
#[CoversClass(KeyGrantedInThePast::class)]
#[CoversClass(KeyGrantedLongerThanOneYear::class)]
#[CoversClass(KeyGrantedPastBoundary::class)]
#[CoversClass(KeyWithdrawnPastOriginalGranting::class)]
#[CoversClass(MemberActiveAndInactiveInOrgan::class)]
#[CoversClass(MemberActiveWithRoleAndInactiveInOrgan::class)]
#[CoversClass(MemberExpiredButStillInOrgan::class)]
#[CoversClass(MemberHasRoleButNotInOrgan::class)]
#[CoversClass(MemberInNonExistingOrgan::class)]
#[CoversClass(MemberInactiveInOrganButHasOtherRole::class)]
#[CoversClass(MemberInactiveInOrganWithoutInactiveMembers::class)]
#[CoversClass(OrganMeetingType::class)]
#[CoversClass(OrganTooSmall::class)]
#[CoversClass(OrganWithoutChair::class)]
final class ErrorTest extends TestCase
{
    use BuildsDecisions;

    /**
     * Every error names both the decision that annuls and the decision it takes back, because that is the pair a
     * secretary has to look at.
     */
    public function testAnnulmentErrorsNameBothDecisions(): void
    {
        $meeting = $this->meeting(
            MeetingTypes::BV,
            18,
        );
        $annulment = $this->annulment(
            $meeting,
            $this->decision(
                $meeting,
                4,
                2,
            ),
        );

        self::assertSame(
            'Decision BV 18.10.1 annuls BV 18.4.2, which annuls another decision; an annulment cannot be annulled.',
            new AnnulmentOfAnnulment(
                $meeting,
                $annulment,
            )->asText(),
        );
        self::assertSame(
            'Decision BV 18.10.1 annuls BV 18.4.2, which was only taken afterwards.',
            new AnnulmentOfLaterDecision(
                $meeting,
                $annulment,
            )->asText(),
        );
        self::assertSame(
            'Decision BV 18.10.1 annuls BV 18.4.2, which was already annulled by another decision.',
            new DecisionAnnulledMoreThanOnce(
                $meeting,
                $annulment,
            )->asText(),
        );

        $error = new AnnulmentOfAnnulment(
            $meeting,
            $annulment,
        );
        self::assertSame(
            $meeting,
            $error->getMeeting(),
        );
        self::assertSame(
            $annulment,
            $error->getSubDecision(),
        );
        self::assertSame(
            $annulment->target,
            $error->getTarget(),
        );
    }

    /**
     * The kind, the name and both dates are what make the error actionable: a document dated after its own meeting
     * describes something that did not exist when it was approved.
     */
    public function testADocumentDatedAfterItsMeetingNamesTheDocumentKind(): void
    {
        $meeting = $this->meeting(
            MeetingTypes::BV,
            18,
            '2026-08-20',
        );

        $budget = new Budget();
        $budget->name = 'Begroting 2027';
        $budget->version = '1.0';
        $budget->date = new DateTimeImmutable('2026-09-01');
        $budget->setDecision($this->decision($meeting, 2));

        self::assertSame(
            'Budget Begroting 2027, version 1.0, is dated 2026-09-01, this is after 2026-08-20.',
            new DocumentDatedAfterMeeting($budget)->asText(),
        );

        $statement = new Statement();
        $statement->name = 'Jaarrekening';
        $statement->version = '2.0';
        $statement->date = new DateTimeImmutable('2026-10-01');
        $statement->setDecision($this->decision($meeting, 3));

        self::assertSame(
            'Financial statement Jaarrekening, version 2.0, is dated 2026-10-01, this is after 2026-08-20.',
            new DocumentDatedAfterMeeting($statement)->asText(),
        );

        $regulation = new OrganRegulation();
        $regulation->abbr = 'TC';
        $regulation->version = '3.0';
        $regulation->date = new DateTimeImmutable('2026-11-01');
        $regulation->setDecision($this->decision($meeting, 4));

        self::assertSame(
            'Body regulation TC, version 3.0, is dated 2026-11-01, this is after 2026-08-20.',
            new DocumentDatedAfterMeeting($regulation)->asText(),
        );
    }

    /**
     * A key may not be granted for a period that has already ended, for longer than a year, or past the association
     * year's boundary. The messages differ only in the reason, so the date and member are what they share.
     */
    public function testKeyGrantErrorsNameTheMemberAndTheExpiry(): void
    {
        $meeting = $this->meeting(
            MeetingTypes::BV,
            18,
            '2026-08-20',
        );
        $granting = $this->granting(
            $meeting,
            '2026-07-01',
        );

        self::assertSame(
            'Key code granted to Test Lid has an expiration of 2026-07-01, this is before 2026-08-20.',
            new KeyGrantedInThePast($granting)->asText(),
        );
        self::assertSame(
            'Key code granted to Test Lid has an expiration of 2026-07-01, this is longer than the 1 year.',
            new KeyGrantedLongerThanOneYear($granting)->asText(),
        );
        self::assertSame(
            'Key code granted to Test Lid has an expiration of 2026-07-01, this is after September 1st of the '
            . 'next association year.',
            new KeyGrantedPastBoundary($granting)->asText(),
        );
    }

    public function testWithdrawingAfterTheGrantExpiredNamesBothDates(): void
    {
        $meeting = $this->meeting(
            MeetingTypes::BV,
            18,
            '2026-08-20',
        );
        $granting = $this->granting(
            $meeting,
            '2026-12-01',
        );

        $withdrawal = new Withdrawal();
        $withdrawal->granting = $granting;
        $withdrawal->withdrawnOn = new DateTimeImmutable('2027-01-01');
        $withdrawal->setDecision($this->decision($meeting, 4));

        self::assertSame(
            'Key code of Test Lid withdrawn per 2027-01-01, this is after the original expiration 2026-12-01.',
            new KeyWithdrawnPastOriginalGranting($withdrawal)->asText(),
        );
    }

    /**
     * A member's organ errors identify the member by number as well as name, since names change but the number does
     * not, and the organ by the name it is known under.
     */
    public function testMemberErrorsNameTheMemberAndTheOrgan(): void
    {
        $meeting = $this->meeting(
            MeetingTypes::BV,
            18,
        );
        $installation = $this->installation($meeting);

        self::assertSame(
            'Member Test Lid (8100) is marked as "Inactief Lid" of Dispuut Zeven but is still a "Lid".',
            new MemberActiveAndInactiveInOrgan(
                $meeting,
                $installation,
            )->asText(),
        );
        self::assertSame(
            'Member Test Lid (8100) is installed as "Lid" in Dispuut Zeven with special roles but is also installed '
            . 'as "Inactief Lid".',
            new MemberActiveWithRoleAndInactiveInOrgan(
                $meeting,
                $installation,
            )->asText(),
        );
        self::assertSame(
            'Member Test Lid (8100) is installed in Dispuut Zeven, however, their GEWIS membership has expired.',
            new MemberExpiredButStillInOrgan(
                $meeting,
                $installation,
            )->asText(),
        );
        self::assertSame(
            'Member Test Lid (8100) is installed as "Voorzitter" in Dispuut Zeven, which does not exist.',
            new MemberInNonExistingOrgan(
                $meeting,
                $installation,
            )->asText(),
        );

        $withRole = new MemberHasRoleButNotInOrgan(
            $meeting,
            $installation,
            'Voorzitter',
        );
        self::assertSame(
            'Member Test Lid (8100) has a special role "Voorzitter" in Dispuut Zeven but is not installed as "Lid".',
            $withRole->asText(),
        );
        self::assertSame(
            'Voorzitter',
            $withRole->getRole(),
        );

        $inactiveWithRole = new MemberInactiveInOrganButHasOtherRole(
            $meeting,
            $installation,
            'Penningmeester',
        );
        self::assertSame(
            'Member Test Lid (8100) is installed as "Inactief Lid" of Dispuut Zeven but has a special role '
            . '"Penningmeester".',
            $inactiveWithRole->asText(),
        );
        self::assertSame(
            'Penningmeester',
            $inactiveWithRole->getRole(),
        );
    }

    /**
     * An inactive member is only allowed in kinds of organ that keep them; the message names the kind so a secretary
     * knows which rule applies.
     */
    public function testAnInactiveMemberOfAnOrganThatHasNoneNamesTheOrganKind(): void
    {
        $meeting = $this->meeting(
            MeetingTypes::BV,
            18,
        );
        $installation = $this->installation(
            $meeting,
            OrganTypes::Committee,
        );

        self::assertSame(
            'Member Test Lid (8100) is an inactive member of DZ, which is a Commissie and does not have those.',
            new MemberInactiveInOrganWithoutInactiveMembers(
                $meeting,
                $installation,
            )->asText(),
        );
    }

    /**
     * Founding an organ in the wrong kind of meeting, with too few members, or without a chair are three separate
     * constitutional errors; the messages say what the organ was and what it should have been.
     */
    public function testOrganErrorsExplainWhichRuleTheOrganBreaks(): void
    {
        $meeting = $this->meeting(
            MeetingTypes::ALV,
            18,
        );
        $foundation = $this->foundation(
            $meeting,
            OrganTypes::Fraternity,
        );

        $meetingType = new OrganMeetingType($foundation);
        self::assertSame(
            'Organ Dispuut Zeven of type fraternity cannot be founded during a meeting of type ALV.',
            $meetingType->asText(),
        );
        self::assertSame(
            OrganTypes::Fraternity,
            $meetingType->getOrganType(),
        );
        self::assertSame(
            MeetingTypes::ALV,
            $meetingType->getMeetingType(),
        );
        self::assertSame(
            $foundation,
            $meetingType->getOrgan(),
        );

        $tooSmall = new OrganTooSmall(
            $meeting,
            $foundation,
            2,
        );
        self::assertSame(
            'DZ has 2 active member(s), while a Dispuut needs at least 3.',
            $tooSmall->asText(),
        );
        self::assertSame(
            2,
            $tooSmall->getMembers(),
        );

        self::assertSame(
            'DZ has no chair.',
            new OrganWithoutChair(
                $meeting,
                $foundation,
            )->asText(),
        );
    }

    private function annulment(
        Meeting $meeting,
        Decision $target,
    ): Annulment {
        $annulment = new Annulment();
        $annulment->sequence = 1;
        $annulment->target = $target;
        $annulment->setDecision($this->decision($meeting, 10));

        return $annulment;
    }

    private function granting(
        Meeting $meeting,
        string $until,
    ): Granting {
        $granting = new Granting();
        $granting->sequence = 1;
        $granting->until = new DateTimeImmutable($until);
        $granting->setMember($this->member());
        $granting->setDecision($this->decision($meeting, 2));

        return $granting;
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

    private function installation(
        Meeting $meeting,
        OrganTypes $type = OrganTypes::Committee,
    ): Installation {
        $foundation = $this->foundation(
            $meeting,
            $type,
        );

        $installation = new Installation();
        $installation->foundation = $foundation;
        $installation->sequence = 1;
        $installation->function = InstallationFunctions::Chair;
        $installation->setMember($this->member());
        $installation->setDecision($this->decision($meeting, 9));

        return $installation;
    }

    private function foundation(
        Meeting $meeting,
        OrganTypes $type,
    ): Foundation {
        $foundation = new Foundation();
        $foundation->abbr = 'DZ';
        $foundation->name = 'Dispuut Zeven';
        $foundation->organType = $type;
        $foundation->sequence = 1;
        $foundation->setDecision($this->decision($meeting, 1));

        return $foundation;
    }
}
