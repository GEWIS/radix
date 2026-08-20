<?php

declare(strict_types=1);

namespace App\DataFixtures\Database;

use App\DataFixtures\Member\MemberPopulationFixture;
use App\Entity\Database\Decision;
use App\Entity\Database\Enums\InstallationFunctions;
use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Database\Enums\OrganTypes;
use App\Entity\Database\Meeting;
use App\Entity\Database\Member;
use App\Entity\Database\SubDecision\Abrogation;
use App\Entity\Database\SubDecision\Annulment;
use App\Entity\Database\SubDecision\Discharge;
use App\Entity\Database\SubDecision\Foundation;
use App\Entity\Database\SubDecision\Installation;
use App\Entity\Database\SubDecision\Reappointment;
use DateTime;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Override;

use function assert;
use function sprintf;

/**
 * The bodies the association is organised into, across every kind it recognises and in every state one can be in.
 *
 * There are committees and fraternities, and one of each of the bodies a general members' meeting appoints; some are
 * still standing and some have been abrogated, so a page listing bodies has both to show. Their members are installed
 * the way the regulations require -- the board founds a body, and members are installed into it afterwards.
 *
 * Two decisions are annulled rather than merely superseded, because an annulment is not an undo written later: it says
 * the decision never took effect at all. One annuls an installation, so somebody who looks installed is not in the body
 * at all; the other annuls a discharge, so somebody who looks discharged never left it.
 *
 * A body membership can be prolonged only in an Advisory Board (RvA), which is the one body whose members serve a term
 * that can be extended rather than being installed afresh, so it is the only one here with a reappointment.
 *
 * @phpstan-type BodySpec array{
 *     abbr: string,
 *     name: string,
 *     type: OrganTypes,
 *     foundedYearsAgo: int,
 *     abrogatedYearsAgo: int|null,
 *     members: int,
 *     seats: int,
 *     externals?: int,
 *     graduates?: int,
 * }
 */
final class BodyFixture extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    /**
     * The bodies, and how each of them stands.
     *
     * `$abrogatedYearsAgo` is null for a body that is still standing. `$members` is where its members are drawn from
     * in the population; they are consecutive from there.
     *
     * `externals` fills that many of the seats from the external block instead: an external member is a member, and
     * bodies are not closed to them. `graduates` does the same from the graduates, and is only meaningful on a
     * fraternity -- see {@see self::body()}.
     */
    private const array BODIES = [
        [
            'abbr' => 'SUD',
            'name' => 'Studiereis Commissie',
            'type' => OrganTypes::Committee,
            'foundedYearsAgo' => 6,
            'abrogatedYearsAgo' => 2,
            'members' => 8060,
            'seats' => 4,
            'externals' => 1,
        ],
        [
            'abbr' => 'BAC',
            'name' => 'Bacchus Commissie',
            'type' => OrganTypes::Committee,
            'foundedYearsAgo' => 4,
            'abrogatedYearsAgo' => null,
            'members' => 8064,
            'seats' => 6,
            'externals' => 2,
        ],
        [
            'abbr' => 'VIER',
            'name' => 'Dispuut Vierkant',
            'type' => OrganTypes::Fraternity,
            'foundedYearsAgo' => 5,
            'abrogatedYearsAgo' => 1,
            'members' => 8070,
            'seats' => 3,
            'externals' => 1,
        ],
        [
            'abbr' => 'KELD',
            'name' => 'Dispuut Kelder',
            'type' => OrganTypes::Fraternity,
            'foundedYearsAgo' => 4,
            'abrogatedYearsAgo' => null,
            'members' => 8092,
            'seats' => 4,
            'externals' => 1,
            'graduates' => 1,
        ],
        [
            'abbr' => 'RVA',
            'name' => 'Raad van Advies',
            'type' => OrganTypes::RvA,
            'foundedYearsAgo' => 5,
            'abrogatedYearsAgo' => null,
            'members' => 8073,
            'seats' => 3,
            'externals' => 1,
        ],
        [
            'abbr' => 'KCC',
            'name' => 'Kascontrolecommissie',
            'type' => OrganTypes::KCC,
            'foundedYearsAgo' => 3,
            'abrogatedYearsAgo' => null,
            'members' => 8076,
            'seats' => 3,
        ],
        [
            'abbr' => 'AVC',
            'name' => 'ALV Commissie',
            'type' => OrganTypes::AVC,
            'foundedYearsAgo' => 4,
            'abrogatedYearsAgo' => 2,
            'members' => 8079,
            'seats' => 3,
        ],
        [
            'abbr' => 'AVW',
            'name' => 'ALV Werkgroep',
            'type' => OrganTypes::AVW,
            'foundedYearsAgo' => 2,
            'abrogatedYearsAgo' => null,
            'members' => 8082,
            'seats' => 4,
            'externals' => 1,
        ],
        // Founded and then abrogated, and staffed by members who have since been removed: the decisions still name
        // them, which is the case that proves a removed member does not take the association's records with them.
        [
            'abbr' => 'HIST',
            'name' => 'Historische Commissie',
            'type' => OrganTypes::Committee,
            'foundedYearsAgo' => 5,
            'abrogatedYearsAgo' => 3,
            'members' => MemberPopulationFixture::DELETED,
            'seats' => 3,
        ],
        [
            'abbr' => 'STEM',
            'name' => 'Stemcommissie',
            'type' => OrganTypes::SC,
            'foundedYearsAgo' => 3,
            'abrogatedYearsAgo' => null,
            'members' => 8086,
            'seats' => 3,
        ],
    ];

    /**
     * Where this fixture's meetings are numbered from, clear of the board's series and of the rest of the seed.
     */
    private const int FIRST_MEETING_NUMBER = 140;

    private int $meetingNumber = self::FIRST_MEETING_NUMBER;

    /**
     * How many external seats have been filled, so no two bodies seat the same external member.
     */
    private int $externalSeat = 0;

    /**
     * The same, for the graduates who stayed on in a fraternity.
     */
    private int $graduateSeat = 0;

    #[Override]
    public function load(ObjectManager $manager): void
    {
        foreach (self::BODIES as $body) {
            $this->body(
                $manager,
                $body,
            );
        }

        $this->annulments($manager);

        $manager->flush();
    }

    /**
     * @param BodySpec $body
     */
    private function body(
        ObjectManager $manager,
        array $body,
    ): void {
        $founding = $this->meeting(
            $manager,
            $this->yearsAgo($body['foundedYearsAgo']),
            // A fraternity may only be founded at a general members' meeting; everything else is the board's to found.
            OrganTypes::Fraternity === $body['type'] ? MeetingTypes::ALV : MeetingTypes::BV,
        );

        $foundation = $this->found(
            $manager,
            $founding,
            $body['abbr'],
            $body['name'],
            $body['type'],
        );

        $installations = [];
        $externals = $body['externals'] ?? 0;
        $ordinary = $body['seats'] - $externals;

        for ($seat = 0; $seat < $body['seats']; $seat++) {
            // The last seats go to external members, drawn from their own block. Being external says how somebody is
            // a member of the association, not whether they may sit in one of its bodies.
            $member = $seat < $ordinary
                ? $this->member($body['members'] + $seat)
                : $this->member(MemberPopulationFixture::EXTERNAL + $this->externalSeat++);

            $installations[] = $this->install(
                $manager,
                $founding,
                $foundation,
                $member,
                // The first seat chairs the body; a chair is a member of it as well, which is why both are recorded.
                0 === $seat
                    ? [
                        InstallationFunctions::Member,
                        InstallationFunctions::Chair,
                    ]
                    : [InstallationFunctions::Member],
            );
        }

        // A graduate may not sit in a committee or in a body the general members' meeting appoints, but may stay on in
        // a fraternity as an inactive member. They were an ordinary member when they joined it, so the ledger shows
        // them installed as a member first and only made inactive once they had graduated: discharged from the one
        // and installed into the other, which is what the two decisions below are.
        foreach ($this->graduateSeats($body) as $lidnr) {
            $joined = $this->install(
                $manager,
                $founding,
                $foundation,
                $this->member($lidnr),
                [InstallationFunctions::Member],
            );

            $sinceGraduating = $this->meeting(
                $manager,
                $this->yearsAgo(1),
                MeetingTypes::ALV,
            );

            $this->discharge(
                $manager,
                $sinceGraduating,
                $joined,
            );
            $installations[] = $this->install(
                $manager,
                $sinceGraduating,
                $foundation,
                $this->member($lidnr),
                [InstallationFunctions::InactiveMember],
            );
        }

        // Only an Advisory Board's members serve a term that can be extended, so it is the only body whose membership
        // is prolonged rather than granted again from scratch.
        if (OrganTypes::RvA === $body['type']) {
            $this->reappoint(
                $manager,
                $this->meeting(
                    $manager,
                    $this->yearsAgo($body['foundedYearsAgo'] - 1),
                    MeetingTypes::ALV,
                ),
                $installations[0],
            );
        }

        if (null === $body['abrogatedYearsAgo']) {
            return;
        }

        // A body that is abrogated has its members discharged with it: nobody stays a member of something that no
        // longer exists.
        $closing = $this->meeting(
            $manager,
            $this->yearsAgo($body['abrogatedYearsAgo']),
            OrganTypes::Fraternity === $body['type'] ? MeetingTypes::ALV : MeetingTypes::BV,
        );

        foreach ($installations as $installation) {
            $this->discharge(
                $manager,
                $closing,
                $installation,
            );
        }

        $this->abrogate(
            $manager,
            $closing,
            $foundation,
        );
    }

    /**
     * The members who stayed on in this body after graduating, which only a fraternity may have.
     *
     * @param BodySpec $body
     *
     * @return int[]
     */
    private function graduateSeats(array $body): array
    {
        $wanted = $body['graduates'] ?? 0;

        if (
            0 === $wanted
            || OrganTypes::Fraternity !== $body['type']
        ) {
            return [];
        }

        $seats = [];

        for ($seat = 0; $seat < $wanted; $seat++) {
            // Offset past the graduates the photo fixture builds its album cases on, which must stay in no body at
            // all for those cases to say what they mean.
            $seats[] = MemberPopulationFixture::GRADUATE + 3 + $this->graduateSeat++;
        }

        return $seats;
    }

    /**
     * The two decisions that never took effect.
     *
     * Both are made and then annulled at a later meeting, which is what the ledger looks like when a meeting decides
     * something it was not entitled to decide, or decides it about the wrong person.
     */
    private function annulments(ObjectManager $manager): void
    {
        $founding = $this->meeting(
            $manager,
            $this->yearsAgo(3),
            MeetingTypes::BV,
        );
        $foundation = $this->found(
            $manager,
            $founding,
            'NUL',
            'Commissie Nietigheid',
            OrganTypes::Committee,
        );

        // An installation that was annulled: this member reads as installed until the annulment is replayed, and is
        // then not in the body at all.
        $installed = $this->install(
            $manager,
            $founding,
            $foundation,
            $this->member(8090),
            [InstallationFunctions::Member],
        );

        // A discharge that was annulled: this member was installed, discharged, and the discharge then annulled, so
        // they never left.
        $staying = $this->install(
            $manager,
            $founding,
            $foundation,
            $this->member(8091),
            [InstallationFunctions::Member],
        );
        $dischargeMeeting = $this->meeting(
            $manager,
            $this->yearsAgo(2),
            MeetingTypes::BV,
        );
        $discharge = $this->discharge(
            $manager,
            $dischargeMeeting,
            $staying,
        );

        $annulling = $this->meeting(
            $manager,
            $this->yearsAgo(1),
            MeetingTypes::BV,
        );

        $this->annul(
            $manager,
            $annulling,
            $installed->getDecision(),
        );
        $this->annul(
            $manager,
            $annulling,
            $discharge->getDecision(),
        );
    }

    private function found(
        ObjectManager $manager,
        Meeting $meeting,
        string $abbreviation,
        string $name,
        OrganTypes $type,
    ): Foundation {
        $decision = $this->decision(
            $manager,
            $meeting,
        );

        $foundation = new Foundation();
        $foundation->setAbbr($abbreviation);
        $foundation->setName($name);
        $foundation->setOrganType($type);
        $foundation->setSequence(1);
        $foundation->setDecision($decision);
        $decision->addSubdecision($foundation);

        $manager->persist($foundation);

        return $foundation;
    }

    /**
     * @param InstallationFunctions[] $functions
     *
     * @return Installation the installation for the first of the given functions
     */
    private function install(
        ObjectManager $manager,
        Meeting $meeting,
        Foundation $foundation,
        Member $member,
        array $functions,
    ): Installation {
        $decision = $this->decision(
            $manager,
            $meeting,
        );

        $installations = [];
        $sequence = 1;

        foreach ($functions as $function) {
            $installation = new Installation();
            $installation->setFoundation($foundation);
            $installation->setMember($member);
            $installation->setFunction($function);
            $installation->setSequence($sequence++);
            $installation->setDecision($decision);
            $decision->addSubdecision($installation);

            $manager->persist($installation);

            $installations[] = $installation;
        }

        return $installations[0];
    }

    private function discharge(
        ObjectManager $manager,
        Meeting $meeting,
        Installation $installation,
    ): Discharge {
        $decision = $this->decision(
            $manager,
            $meeting,
        );

        $discharge = new Discharge();
        $discharge->setInstallation($installation);
        $discharge->setSequence(1);
        $discharge->setDecision($decision);
        $decision->addSubdecision($discharge);

        $manager->persist($discharge);

        return $discharge;
    }

    private function reappoint(
        ObjectManager $manager,
        Meeting $meeting,
        Installation $installation,
    ): void {
        $decision = $this->decision(
            $manager,
            $meeting,
        );

        $reappointment = new Reappointment();
        $reappointment->setInstallation($installation);
        $reappointment->setSequence(1);
        $reappointment->setDecision($decision);
        $decision->addSubdecision($reappointment);

        $manager->persist($reappointment);
    }

    private function abrogate(
        ObjectManager $manager,
        Meeting $meeting,
        Foundation $foundation,
    ): void {
        $decision = $this->decision(
            $manager,
            $meeting,
        );

        $abrogation = new Abrogation();
        $abrogation->setFoundation($foundation);
        $abrogation->setSequence(1);
        $abrogation->setDecision($decision);
        $decision->addSubdecision($abrogation);

        $manager->persist($abrogation);
    }

    private function annul(
        ObjectManager $manager,
        Meeting $meeting,
        Decision $target,
    ): void {
        $decision = $this->decision(
            $manager,
            $meeting,
        );

        $annulment = new Annulment();
        $annulment->setTarget($target);
        $annulment->setSequence(1);
        $annulment->setDecision($decision);
        $decision->addSubdecision($annulment);

        $manager->persist($annulment);
    }

    private function meeting(
        ObjectManager $manager,
        DateTime $on,
        MeetingTypes $type,
    ): Meeting {
        // Narrowed for the setter, which takes a meeting number rather than any integer. The series starts above zero
        // and only climbs.
        $number = $this->meetingNumber++;
        assert($number >= self::FIRST_MEETING_NUMBER);

        $meeting = new Meeting();
        $meeting->setType($type);
        $meeting->setNumber($number);
        $meeting->setDate($on);

        $manager->persist($meeting);

        return $meeting;
    }

    /**
     * Decisions are numbered within their meeting, so each one gets a point of its own.
     */
    private function decision(
        ObjectManager $manager,
        Meeting $meeting,
    ): Decision {
        $decision = new Decision();
        $decision->setMeeting($meeting);
        $decision->setPoint($meeting->getDecisions()->count() + 1);
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
     * A date that many years back, pushed a few days further for every meeting already made.
     *
     * No two meetings may fall on the same day: several bodies here were founded the same number of years ago, and
     * meetings that share a date leave "the oldest meeting" with no single answer.
     */
    private function yearsAgo(int $years): DateTime
    {
        // Three months further back than the year asks for, and a few days further for every meeting already made.
        // Two general members' meetings on one day would leave "the oldest meeting" with no single answer; meetings of
        // different kinds may share a date, and do.
        return new DateTime()->modify(sprintf(
            '-%d years -3 months +%d days',
            $years,
            $this->meetingNumber - self::FIRST_MEETING_NUMBER,
        ));
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
