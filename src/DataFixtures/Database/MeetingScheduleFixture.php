<?php

declare(strict_types=1);

namespace App\DataFixtures\Database;

use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Database\Meeting;
use DateTime;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use Override;

use function assert;
use function count;
use function in_array;
use function range;
use function sort;
use function sprintf;

/**
 * The association's meeting calendar, in the ledger where meetings belong.
 *
 * Board meetings every week, general members' meetings every month outside the summer, chairs' meetings once a
 * quarter, and two virtual meetings. Most carry no decision of their own: they are the diary that the agenda, the
 * minutes and the documents hang off, and the replay copies them into the projection with everything else.
 *
 * Besides naming each meeting, this names the ones the rest of the seed writes decisions at, by the part they play
 * relative to today rather than by their number -- `ledger-meeting-gmm-complete` and the rest. The projection
 * republishes the same names without the prefix once the replay has been through; see
 * {@see \App\DataFixtures\Decision\ProjectionReferenceFixture}.
 */
final class MeetingScheduleFixture extends Fixture implements FixtureGroupInterface
{
    public const int FIRST_BM_NUMBER = 1800;

    /**
     * Where the calendar's own general members' and chairs' meetings are numbered from.
     *
     * Above the meetings that a board's or a body's history was written at, which reach much further back. What counts
     * as "the last meeting held" is a question about the calendar, so the two series have to be tellable apart.
     */
    public const int FIRST_GMM_NUMBER = 205;
    public const int FIRST_CM_NUMBER = 45;

    #[Override]
    public function load(ObjectManager $manager): void
    {
        $today = new DateTime('today');

        $number = self::FIRST_BM_NUMBER;
        $tuesday = new DateTime('tuesday this week');
        foreach (
            range(
                -12,
                3,
            ) as $week
        ) {
            $this->createMeeting(
                $manager,
                MeetingTypes::BV,
                $number,
                (clone $tuesday)->modify(sprintf(
                    '%+d weeks',
                    $week,
                )),
            );
            $number++;
        }

        $gmmDates = [];
        foreach (
            range(
                -12,
                5,
            ) as $month
        ) {
            $candidate = new DateTime((clone $today)->modify(sprintf(
                'first day of %+d months',
                $month,
            ))->format('Y-m-20'));

            if (
                in_array(
                    (int) $candidate->format('n'),
                    [
                        7,
                        8,
                        9,
                    ],
                    true,
                )
            ) {
                continue;
            }

            $gmmDates[] = $candidate;
        }

        $number = self::FIRST_GMM_NUMBER;
        $pastGmms = [];
        $upcomingGmms = [];
        foreach ($gmmDates as $date) {
            $meeting = $this->createMeeting(
                $manager,
                MeetingTypes::ALV,
                $number,
                $date,
            );
            $number++;

            if ($date < $today) {
                $pastGmms[] = $meeting;
            } else {
                $upcomingGmms[] = $meeting;
            }
        }

        assert(count($pastGmms) >= 2);
        assert(count($upcomingGmms) >= 2);
        $this->addReference(
            'ledger-meeting-gmm-processing',
            $pastGmms[count($pastGmms) - 1],
        );
        $this->addReference(
            'ledger-meeting-gmm-complete',
            $pastGmms[count($pastGmms) - 2],
        );
        $this->addReference(
            'ledger-meeting-gmm-upcoming',
            $upcomingGmms[0],
        );
        $this->addReference(
            'ledger-meeting-gmm-upcoming-2',
            $upcomingGmms[1],
        );

        // One per quarter of the association year: September-November, November-January, February-April, April-June.
        $cmDates = [];
        $year = (int) $today->format('Y');
        foreach (
            range(
                $year - 2,
                $year + 1,
            ) as $anchorYear
        ) {
            foreach (['03-10', '05-20', '10-20', '12-10'] as $anchor) {
                $candidate = new DateTime(sprintf(
                    '%d-%s',
                    $anchorYear,
                    $anchor,
                ));

                if (
                    $candidate < (clone $today)->modify('-13 months')
                    || $candidate > (clone $today)->modify('+6 months')
                ) {
                    continue;
                }

                $cmDates[] = $candidate;
            }
        }

        sort($cmDates);

        $number = self::FIRST_CM_NUMBER;
        $pastCms = [];
        $upcomingCms = [];
        foreach ($cmDates as $date) {
            $meeting = $this->createMeeting(
                $manager,
                MeetingTypes::VV,
                $number,
                $date,
            );
            $number++;

            if ($date < $today) {
                $pastCms[] = $meeting;
            } else {
                $upcomingCms[] = $meeting;
            }
        }

        assert(count($pastCms) >= 1);
        assert(count($upcomingCms) >= 1);
        $this->addReference(
            'ledger-meeting-cm-past',
            $pastCms[count($pastCms) - 1],
        );
        $this->addReference(
            'ledger-meeting-cm-upcoming',
            $upcomingCms[0],
        );

        $this->createMeeting(
            $manager,
            MeetingTypes::VIRT,
            1,
            (clone $today)->modify('-4 months'),
        );
        $this->createMeeting(
            $manager,
            MeetingTypes::VIRT,
            2,
            (clone $today)->modify('-6 weeks'),
        );

        $manager->flush();
    }

    /**
     * What the ledger fixtures attaching a decision to a meeting call it.
     */
    public static function reference(
        MeetingTypes $type,
        int $number,
    ): string {
        return sprintf(
            'ledger-meeting-%s-%d',
            $type->value,
            $number,
        );
    }

    private function createMeeting(
        ObjectManager $manager,
        MeetingTypes $type,
        int $number,
        DateTime $date,
    ): Meeting {
        // Narrowed for the setter, which takes a meeting number rather than any integer.
        assert($number > 0);

        $meeting = new Meeting();
        $meeting->setType($type);
        $meeting->setNumber($number);
        $meeting->setDate($date);

        $manager->persist($meeting);
        $this->addReference(
            self::reference(
                $type,
                $number,
            ),
            $meeting,
        );

        return $meeting;
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
