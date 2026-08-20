<?php

declare(strict_types=1);

namespace App\DataFixtures\Database;

use App\DataFixtures\Member\MemberPopulationFixture;
use App\Entity\Database\Decision;
use App\Entity\Database\Enums\BoardFunctions;
use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Database\Meeting;
use App\Entity\Database\Member;
use App\Entity\Database\SubDecision\Board\Discharge;
use App\Entity\Database\SubDecision\Board\Installation;
use App\Entity\Database\SubDecision\Board\Release;
use DateTime;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Override;

use function assert;
use function count;
use function sprintf;

/**
 * Six years of boards, each one following the association year the way a real one does.
 *
 * A board is decided on well before it starts. The general members' meeting that installs it sits in May, and the
 * installation names 1 July as the day it takes effect, so the meeting that decides and the term that follows do not
 * have to line up. A year later the board is released, again on 1 July, and it is only discharged once its annual
 * report has been dealt with -- which is a separate meeting, months after it stopped serving.
 *
 * That leaves three states worth seeding, and all three are here: boards that were installed, released and discharged;
 * one that was released but whose report is still outstanding, so it has no discharge; and the board that is serving
 * now, which has neither.
 *
 * Every board holds between three and nine members, which is what the statutes allow. The smallest is the bare chair,
 * secretary and treasurer; the largest fills every commissioner's seat besides.
 */
final class BoardFixture extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    /**
     * The boards, oldest first, as an offset in association years from the one running now.
     *
     * `$discharged` says whether the annual report has been dealt with. The board that stepped down this July has not
     * got there yet, which is both the ordinary state of affairs a few weeks in and what puts a released board without
     * a discharge on the books; the board serving now has neither, which is what `$termsAgo === 0` means.
     */
    private const array BOARDS = [
        [
            'termsAgo' => 5,
            'seats' => 5,
            'firstMember' => 8030,
            'discharged' => true,
        ],
        [
            'termsAgo' => 4,
            'seats' => 7,
            'firstMember' => 8035,
            'discharged' => true,
        ],
        [
            'termsAgo' => 3,
            'seats' => 3,
            'firstMember' => 8042,
            'discharged' => true,
        ],
        [
            'termsAgo' => 2,
            'seats' => 9,
            'firstMember' => 8045,
            'discharged' => true,
        ],
        [
            'termsAgo' => 1,
            'seats' => 6,
            'firstMember' => 8054,
            'discharged' => false,
        ],
        [
            'termsAgo' => 0,
            'seats' => 5,
            'firstMember' => MemberPopulationFixture::BOARD,
            'discharged' => false,
        ],
    ];

    /**
     * The seats a board fills, in the order they are filled.
     *
     * The first three are the ones a board cannot be without, which is also why the smallest board here holds exactly
     * them; the rest are added as the board grows.
     */
    private const array SEATS = [
        BoardFunctions::Chair,
        BoardFunctions::Secretary,
        BoardFunctions::Treasurer,
        BoardFunctions::ExternalAffairs,
        BoardFunctions::InternalAffairs,
        BoardFunctions::Education,
        BoardFunctions::Community,
        BoardFunctions::Innovation,
        BoardFunctions::DigitalInfrastructure,
    ];

    /**
     * Where this fixture's general members' meetings are numbered from. Below the series the rest of the seed uses, so
     * the two never land on the same meeting.
     */
    private const int FIRST_MEETING_NUMBER = 100;

    private int $meetingNumber = self::FIRST_MEETING_NUMBER;

    #[Override]
    public function load(ObjectManager $manager): void
    {
        $currentTermStart = self::associationYearStart(new DateTime());

        foreach (self::BOARDS as $board) {
            $this->seat(
                $manager,
                $board['termsAgo'],
                $board['seats'],
                $board['firstMember'],
                $board['discharged'],
                $currentTermStart,
            );
        }

        $manager->flush();
    }

    /**
     * One board, from the meeting that installed it to the one that discharged it.
     */
    private function seat(
        ObjectManager $manager,
        int $termsAgo,
        int $seats,
        int $firstMember,
        bool $discharged,
        DateTime $currentTermStart,
    ): void {
        if (
            $seats < 3
            || $seats > count(self::SEATS)
        ) {
            throw new LogicException(sprintf(
                'A board holds between 3 and %d members; one of %d was asked for.',
                count(self::SEATS),
                $seats,
            ));
        }

        $takesEffect = new DateTime($currentTermStart->format('Y-m-d'))->modify(sprintf(
            '-%d years',
            $termsAgo,
        ));
        $released = new DateTime($takesEffect->format('Y-m-d'))->modify('+1 year');

        // Decided in May, in office from 1 July. The meeting is where the decision lives; the date on the
        // installation is when it starts.
        $installations = $this->install(
            $manager,
            $this->meeting(
                $manager,
                new DateTime($takesEffect->format('Y-m-d'))->modify('-6 weeks'),
            ),
            $takesEffect,
            $seats,
            $firstMember,
        );

        // The board serving now has not been released, and is therefore not up for discharge either.
        if (0 === $termsAgo) {
            return;
        }

        $this->release(
            $manager,
            $this->meeting(
                $manager,
                new DateTime($released->format('Y-m-d'))->modify('-6 weeks'),
            ),
            $released,
            $installations,
        );

        if (!$discharged) {
            return;
        }

        // The annual report takes a few months to be written and dealt with, so the discharge is its own meeting well
        // after the board stopped serving. The projection reads the discharge date off that meeting.
        $this->discharge(
            $manager,
            $this->meeting(
                $manager,
                new DateTime($released->format('Y-m-d'))->modify('+5 months'),
            ),
            $installations,
        );
    }

    /**
     * @return Installation[] the installation per seat, in the order the seats were filled
     */
    private function install(
        ObjectManager $manager,
        Meeting $meeting,
        DateTime $takesEffect,
        int $seats,
        int $firstMember,
    ): array {
        $decision = $this->decision(
            $manager,
            $meeting,
        );

        $installations = [];
        $sequence = 1;

        for ($seat = 0; $seat < $seats; $seat++) {
            $installation = new Installation();
            $installation->setMember($this->member($firstMember + $seat));
            $installation->setFunction(self::SEATS[$seat]);
            $installation->setDate(clone $takesEffect);
            $installation->setSequence($sequence++);
            $installation->setDecision($decision);
            $decision->addSubdecision($installation);

            $manager->persist($installation);

            $installations[] = $installation;
        }

        return $installations;
    }

    /**
     * @param Installation[] $installations
     */
    private function release(
        ObjectManager $manager,
        Meeting $meeting,
        DateTime $on,
        array $installations,
    ): void {
        $decision = $this->decision(
            $manager,
            $meeting,
        );
        $sequence = 1;

        foreach ($installations as $installation) {
            $release = new Release();
            $release->setInstallation($installation);
            $release->setDate(clone $on);
            $release->setSequence($sequence++);
            $release->setDecision($decision);
            $decision->addSubdecision($release);

            $manager->persist($release);
        }
    }

    /**
     * @param Installation[] $installations
     */
    private function discharge(
        ObjectManager $manager,
        Meeting $meeting,
        array $installations,
    ): void {
        $decision = $this->decision(
            $manager,
            $meeting,
        );
        $sequence = 1;

        foreach ($installations as $installation) {
            $discharge = new Discharge();
            $discharge->setInstallation($installation);
            $discharge->setSequence($sequence++);
            $discharge->setDecision($decision);
            $decision->addSubdecision($discharge);

            $manager->persist($discharge);
        }
    }

    /**
     * A meeting on the given day, nudged a day further for every one already made.
     *
     * One board is released and the next installed six weeks before the same 1 July, which would otherwise put two
     * meetings on one day; meetings that share a date leave "the oldest meeting" with no single answer.
     */
    private function meeting(
        ObjectManager $manager,
        DateTime $on,
    ): Meeting {
        $on->modify(sprintf(
            '+%d days',
            $this->meetingNumber - self::FIRST_MEETING_NUMBER,
        ));

        $meeting = new Meeting();
        $meeting->setType(MeetingTypes::ALV);
        // Narrowed for the setter, which takes a meeting number rather than any integer. The series starts
        // above zero and only climbs, so this holds by construction.
        $number = $this->meetingNumber++;
        assert($number >= self::FIRST_MEETING_NUMBER);
        $meeting->setNumber($number);
        $meeting->setDate($on);

        $manager->persist($meeting);

        return $meeting;
    }

    private function decision(
        ObjectManager $manager,
        Meeting $meeting,
    ): Decision {
        $decision = new Decision();
        $decision->setMeeting($meeting);
        $decision->setPoint(1);
        $decision->setNumber(1);

        $manager->persist($decision);

        return $decision;
    }

    private function member(int $lidnr): Member
    {
        return $this->getReference(
            sprintf(
                'ledger-member-%d',
                $lidnr,
            ),
            Member::class,
        );
    }

    /**
     * The 1 July an association year starts on: this year's if it has already been, last year's otherwise.
     */
    private static function associationYearStart(DateTime $on): DateTime
    {
        $start = new DateTime($on->format('Y') . '-07-01 midnight');

        if ($start > $on) {
            $start->modify('-1 year');
        }

        return $start;
    }

    /**
     * @return array<class-string<FixtureInterface>>
     */
    #[Override]
    public function getDependencies(): array
    {
        return [MemberPopulationFixture::class];
    }

    /**
     * @return string[]
     */
    #[Override]
    public static function getGroups(): array
    {
        return ['ledger'];
    }
}
