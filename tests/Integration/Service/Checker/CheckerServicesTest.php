<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Checker;

use App\Entity\Database\Meeting as MeetingModel;
use App\Service\Checker\Installation;
use App\Service\Checker\Meeting;
use App\Service\Checker\Organ;
use App\Tests\Integration\DatabaseTestCase;

use function array_keys;
use function array_values;

/**
 * The three ledger views the checker reads: the meetings themselves, the organs that exist after one, and who is
 * installed in them. The organ and installation sets span the whole ledger up to a meeting, so each is memoised for
 * the meeting it was computed for and dropped again when the checker moves on.
 */
final class CheckerServicesTest extends DatabaseTestCase
{
    public function testTheMeetingsCanBeListedAndTheLastOneFound(): void
    {
        $meetings = self::getContainer()->get(Meeting::class);

        $all = $meetings->getAllMeetings();
        self::assertNotEmpty($all);
        self::assertContainsOnlyInstancesOf(
            MeetingModel::class,
            $all,
        );

        $last = $meetings->getLastMeeting();
        self::assertInstanceOf(
            MeetingModel::class,
            $last,
        );
    }

    public function testTheOrgansOfAMeetingAreComputedOnceAndCanBeForgotten(): void
    {
        $organs = self::getContainer()->get(Organ::class);
        $meeting = $this->lastMeeting();

        $foundations = $organs->getAllOrganFoundations($meeting);
        // A memo for the same meeting answers without querying again.
        self::assertSame(
            $foundations,
            $organs->getAllOrganFoundations($meeting),
        );

        self::assertSame(
            array_keys($foundations),
            $organs->getAllOrgans($meeting),
        );
        self::assertIsArray($organs->getOrgansCreatedAtMeeting($meeting));

        $organs->reset();
        self::assertSame(
            $foundations,
            $organs->getAllOrganFoundations($meeting),
        );
    }

    public function testTheInstallationsOfAMeetingAreComputedOnceAndCanBeForgotten(): void
    {
        $installations = self::getContainer()->get(Installation::class);
        $meeting = $this->lastMeeting();

        $installed = $installations->getAllInstallations($meeting);
        self::assertSame(
            $installed,
            $installations->getAllInstallations($meeting),
        );

        $roles = $installations->getCurrentRolesPerOrgan($meeting);
        self::assertNotEmpty($roles);
        foreach ($roles as $perMember) {
            self::assertNotEmpty($perMember);
        }

        // Every installation counted once, under its organ, member and function.
        self::assertSame(
            array_values($installed),
            $this->flatten($roles),
        );

        $installations->reset();
        self::assertSame(
            $installed,
            $installations->getAllInstallations($meeting),
        );
    }

    /**
     * @param array<string, array<int, array<string, object>>> $roles
     *
     * @return list<object>
     */
    private function flatten(array $roles): array
    {
        $all = [];
        foreach ($roles as $members) {
            foreach ($members as $byFunction) {
                foreach ($byFunction as $installation) {
                    $all[] = $installation;
                }
            }
        }

        return $all;
    }

    private function lastMeeting(): MeetingModel
    {
        $meeting = self::getContainer()->get(Meeting::class)->getLastMeeting();
        self::assertInstanceOf(
            MeetingModel::class,
            $meeting,
            'The seed is expected to contain a meeting.',
        );

        return $meeting;
    }
}
