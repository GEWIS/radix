<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Database\Decision;
use App\Entity\Database\Enums\BoardFunctions;
use App\Entity\Database\Enums\InstallationFunctions;
use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Database\Enums\MembershipTypes;
use App\Entity\Database\Enums\OrganTypes;
use App\Entity\Database\Meeting;
use App\Entity\Database\Member;
use App\Entity\Database\Membership;
use App\Entity\Database\SubDecision\Abrogation;
use App\Entity\Database\SubDecision\Annulment;
use App\Entity\Database\SubDecision\Board\Installation as BoardInstallation;
use App\Entity\Database\SubDecision\Board\Release as BoardRelease;
use App\Entity\Database\SubDecision\Discharge;
use App\Entity\Database\SubDecision\Financial\Budget;
use App\Entity\Database\SubDecision\Foundation;
use App\Entity\Database\SubDecision\Installation;
use App\Entity\Database\SubDecision\Key\Granting;
use App\Entity\Database\SubDecision\Key\Withdrawal;
use App\Entity\Database\SubDecision\OrganRegulation;
use App\Entity\Database\SubDecision\Other;
use App\Entity\Database\SubDecision\Reappointment;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

use function spl_object_id;
use function sprintf;

/**
 * Writes the meeting → decision → subdecision graphs an integration test needs into the ledger.
 *
 * The seed is there to be read, not to be built on: a test that leans on which member happens to be in the fixtures
 * is a test that changes meaning when the fixtures do. So everything a test asserts about is made here, in meetings
 * of its own numbered well past the seed's, and every builder flushes — the projection listeners run on flush, which
 * is what makes the projected side observable at all.
 *
 * Each decision gets its own point within its meeting, since the two together are half of a decision's identity.
 * Meetings are board meetings unless a test says otherwise, because that is the meeting type that may found an
 * ordinary committee — data built here should not break the regulations by accident.
 */
final class LedgerBuilder
{
    /** @var int<0, max> Well past what the fixtures use, so nothing built here can collide with the seed. */
    private int $meetingNumber = 9000;

    private int $memberCounter = 0;

    /** @var array<int, int> The next free decision point per meeting. */
    private array $points = [];

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function meeting(
        MeetingTypes $type = MeetingTypes::BV,
        string $date = '2026-08-20',
    ): Meeting {
        $meeting = new Meeting();
        $meeting->type = $type;
        $meeting->setNumber(++$this->meetingNumber);
        $meeting->date = new DateTimeImmutable($date);

        $this->entityManager->persist($meeting);
        $this->entityManager->flush();

        return $meeting;
    }

    /**
     * A member with one ordinary membership, current unless another period is asked for.
     */
    public function member(
        MembershipTypes $type = MembershipTypes::Ordinary,
        string $membershipStart = '-1 month',
        ?string $membershipEnd = '+11 months',
    ): Member {
        $number = ++$this->memberCounter;

        $member = new Member();
        $member->initials = 'T.';
        $member->firstName = 'Test';
        $member->middleName = '';
        $member->lastName = sprintf(
            'Testlid %d',
            $number,
        );
        $member->setEmail(sprintf('testlid-%d@example.org', $number));
        $member->setBirth(new DateTimeImmutable('2000-01-01'));
        $member->changedOn = new DateTimeImmutable();

        $member->addMembership(
            new Membership(
                $member,
                $type,
                new DateTimeImmutable($membershipStart),
                null === $membershipEnd ? null : new DateTimeImmutable($membershipEnd),
            ),
        );

        $this->entityManager->persist($member);
        $this->entityManager->flush();

        return $member;
    }

    public function foundOrgan(
        Meeting $meeting,
        string $abbreviation = 'TC',
        string $name = 'Taartcommissie',
        OrganTypes $type = OrganTypes::Committee,
    ): Foundation {
        $foundation = new Foundation();
        $foundation->abbr = $abbreviation;
        $foundation->name = $name;
        $foundation->organType = $type;
        $foundation->sequence = 1;
        $foundation->setDecision($this->decision($meeting));

        return $this->persist($foundation);
    }

    /**
     * Install someone in an organ. Several functions land in one decision, as they do in a real one.
     *
     * @return Installation the installation for the first function given
     */
    public function install(
        Meeting $meeting,
        Foundation $foundation,
        Member $member,
        InstallationFunctions ...$functions,
    ): Installation {
        $decision = $this->decision($meeting);
        $installations = [];
        $sequence = 1;

        foreach ($functions as $function) {
            $installation = new Installation();
            $installation->foundation = $foundation;
            $installation->setMember($member);
            $installation->function = $function;
            $installation->sequence = $sequence++;
            $installation->setDecision($decision);

            $this->entityManager->persist($installation);

            $installations[] = $installation;
        }

        $this->entityManager->flush();

        return $installations[0];
    }

    public function discharge(
        Meeting $meeting,
        Installation $installation,
    ): Discharge {
        $discharge = new Discharge();
        $discharge->installation = $installation;
        $discharge->sequence = 1;
        $discharge->setDecision($this->decision($meeting));

        return $this->persist($discharge);
    }

    public function reappoint(
        Meeting $meeting,
        Installation $installation,
    ): Reappointment {
        $reappointment = new Reappointment();
        $reappointment->installation = $installation;
        $reappointment->sequence = 1;
        $reappointment->setDecision($this->decision($meeting));

        return $this->persist($reappointment);
    }

    public function abrogate(
        Meeting $meeting,
        Foundation $foundation,
    ): Abrogation {
        $abrogation = new Abrogation();
        $abrogation->foundation = $foundation;
        $abrogation->sequence = 1;
        $abrogation->setDecision($this->decision($meeting));

        return $this->persist($abrogation);
    }

    public function grantKey(
        Meeting $meeting,
        Member $member,
        string $until = '+2 months',
    ): Granting {
        $granting = new Granting();
        $granting->setMember($member);
        $granting->until = new DateTimeImmutable($until);
        $granting->sequence = 1;
        $granting->setDecision($this->decision($meeting));

        return $this->persist($granting);
    }

    public function withdrawKey(
        Meeting $meeting,
        Granting $granting,
        string $withdrawnOn = '+1 month',
    ): Withdrawal {
        $withdrawal = new Withdrawal();
        $withdrawal->granting = $granting;
        $withdrawal->withdrawnOn = new DateTimeImmutable($withdrawnOn);
        $withdrawal->sequence = 1;
        $withdrawal->setDecision($this->decision($meeting));

        return $this->persist($withdrawal);
    }

    public function installBoard(
        Meeting $meeting,
        Member $member,
        BoardFunctions $function = BoardFunctions::Chair,
        string $date = '2026-09-01',
    ): BoardInstallation {
        $installation = new BoardInstallation();
        $installation->setMember($member);
        $installation->function = $function;
        $installation->date = new DateTimeImmutable($date);
        $installation->sequence = 1;
        $installation->setDecision($this->decision($meeting));

        return $this->persist($installation);
    }

    public function releaseBoard(
        Meeting $meeting,
        BoardInstallation $installation,
        string $date = '2027-09-01',
    ): BoardRelease {
        $release = new BoardRelease();
        $release->installation = $installation;
        $release->date = new DateTimeImmutable($date);
        $release->sequence = 1;
        $release->setDecision($this->decision($meeting));

        return $this->persist($release);
    }

    public function annul(
        Meeting $meeting,
        Decision $target,
    ): Annulment {
        $annulment = new Annulment();
        $annulment->target = $target;
        $annulment->sequence = 1;
        $annulment->setDecision($this->decision($meeting));

        return $this->persist($annulment);
    }

    /**
     * A budget approved by a meeting. `$date` is the date the version itself carries, which is not the meeting's.
     */
    public function approveBudget(
        Meeting $meeting,
        string $date,
        string $name = 'Begroting',
    ): Budget {
        $budget = new Budget();
        $budget->name = $name;
        $budget->version = '1.0';
        $budget->date = new DateTimeImmutable($date);
        $budget->approval = true;
        $budget->changes = false;
        $budget->sequence = 1;
        $budget->setDecision($this->decision($meeting));

        return $this->persist($budget);
    }

    /**
     * A body regulation approved by a meeting, with the same distinction between its own date and the meeting's.
     */
    public function approveOrganRegulation(
        Meeting $meeting,
        string $date,
        string $abbreviation = 'TC',
    ): OrganRegulation {
        $regulation = new OrganRegulation();
        $regulation->abbr = $abbreviation;
        $regulation->organType = OrganTypes::Committee;
        $regulation->version = '1.0';
        $regulation->date = new DateTimeImmutable($date);
        $regulation->approval = true;
        $regulation->changes = false;
        $regulation->setMember($this->member());
        $regulation->sequence = 1;
        $regulation->setDecision($this->decision($meeting));

        return $this->persist($regulation);
    }

    public function decideFreely(
        Meeting $meeting,
        string $contentNL,
        ?string $contentEN = null,
    ): Other {
        $other = new Other();
        $other->contentNL = $contentNL;
        $other->contentEN = $contentEN;
        $other->sequence = 1;
        $other->setDecision($this->decision($meeting));

        return $this->persist($other);
    }

    public function decision(Meeting $meeting): Decision
    {
        $key = spl_object_id($meeting);
        $point = $this->points[$key] ?? 1;
        $this->points[$key] = $point + 1;

        $decision = new Decision();
        $decision->setMeeting($meeting);
        $decision->point = $point;
        $decision->number = 1;

        $this->entityManager->persist($decision);

        return $decision;
    }

    /**
     * @template T of object
     *
     * @param T $subDecision
     *
     * @return T
     */
    private function persist(object $subDecision): object
    {
        $this->entityManager->persist($subDecision);
        $this->entityManager->flush();

        return $subDecision;
    }
}
