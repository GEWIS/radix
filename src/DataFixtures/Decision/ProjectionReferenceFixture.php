<?php

declare(strict_types=1);

namespace App\DataFixtures\Decision;

use App\DataFixtures\Database\MeetingScheduleFixture;
use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Decision\Meeting;
use App\Entity\Decision\Member;
use App\Entity\Decision\Organ;
use DateTime;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Override;

use function array_filter;
use function count;
use function sprintf;
use function usort;

/**
 * Hands the rest of the web fixtures the members, meetings and bodies that were replayed out of the ledger.
 *
 * Nothing here writes anything. Members are not seeded into the projection, they are derived from the ledger:
 * `app:fixtures:load` replays it before this group is loaded, so by the time this runs every member already exists.
 * What they do not have is fixture references, because a reference lives only for as long as the executor that made
 * it, and the replay is not one.
 *
 * So this looks them up and names them `member-<lidnr>`, which is what the fixtures hanging off them have always
 * called them. Their numbers are fixed by {@see \App\DataFixtures\Member\MemberPopulationFixture} for this reason.
 */
final class ProjectionReferenceFixture extends Fixture implements FixtureGroupInterface
{
    /**
     * The bodies the web fixtures ask for, by the abbreviation their founding decision gave them.
     *
     * A mapping rather than a definition: rename one in {@see \App\DataFixtures\Database\OrganDecisionFixture} and
     * this is where it has to be followed.
     */
    private const array ORGANS = [
        'organ-getest' => 'GETÉST',
        'organ-keur' => 'KEUR',
    ];

    #[Override]
    public function load(ObjectManager $manager): void
    {
        $this->members($manager);
        $this->organs($manager);
        $this->meetings($manager);
    }

    private function members(ObjectManager $manager): void
    {
        foreach ($manager->getRepository(Member::class)->findAll() as $member) {
            $this->addReference(
                sprintf(
                    'member-%d',
                    $member->getLidnr(),
                ),
                $member,
            );
        }
    }

    /**
     * The bodies, and the ones that have been abrogated.
     *
     * Two may share an abbreviation, because one was founded after the other had been abrogated. That is exactly what
     * the fixtures asking for a "former" body want, so they are told apart by whether the replay gave them an
     * abrogation date.
     */
    private function organs(ObjectManager $manager): void
    {
        $repository = $manager->getRepository(Organ::class);

        foreach (self::ORGANS as $reference => $abbreviation) {
            $standing = null;
            $abrogated = null;

            foreach ($repository->findBy(['abbr' => $abbreviation]) as $organ) {
                if (null === $organ->getAbrogationDate()) {
                    $standing = $organ;
                } else {
                    $abrogated = $organ;
                }
            }

            if (null === $standing) {
                throw new LogicException(sprintf(
                    'The replay produced no body abbreviated "%s" that is still standing, which the web fixtures call'
                    . ' "%s". A body comes from the decision that founded it, so it is the ledger that is missing it.',
                    $abbreviation,
                    $reference,
                ));
            }

            $this->addReference(
                $reference,
                $standing,
            );

            if (null === $abrogated) {
                continue;
            }

            $this->addReference(
                $reference . '-former',
                $abrogated,
            );
        }
    }

    /**
     * The meetings, each by its number and the ones that matter by the part they play relative to today.
     */
    private function meetings(ObjectManager $manager): void
    {
        $repository = $manager->getRepository(Meeting::class);

        foreach ($repository->findAll() as $meeting) {
            $this->addReference(
                sprintf(
                    'meeting-%s-%d',
                    $meeting->getType()->value,
                    $meeting->getNumber(),
                ),
                $meeting,
            );
        }

        $today = new DateTime('today');

        foreach (
            [
                [
                    MeetingTypes::ALV,
                    'gmm',
                    MeetingScheduleFixture::FIRST_GMM_NUMBER,
                ],
                [
                    MeetingTypes::VV,
                    'cm',
                    MeetingScheduleFixture::FIRST_CM_NUMBER,
                ],
            ] as [$type, $name, $firstOfCalendar]
        ) {
            // Only the calendar's own meetings answer to these names. A board's or a body's history was written at
            // meetings that reach much further back and are numbered below the calendar, and "the last meeting held"
            // means the last one on the calendar rather than the last thing decided anywhere.
            [
                $past, $upcoming
            ] = $this->split(
                array_filter(
                    $repository->findBy(['type' => $type]),
                    static fn (Meeting $meeting): bool => $meeting->getNumber() >= $firstOfCalendar,
                ),
                $today,
            );

            $this->name(
                sprintf(
                    'meeting-%s-processing',
                    $name,
                ),
                $past,
                -1,
            );
            $this->name(
                sprintf(
                    'meeting-%s-complete',
                    $name,
                ),
                $past,
                -2,
            );
            $this->name(
                sprintf(
                    'meeting-%s-past',
                    $name,
                ),
                $past,
                -1,
            );
            $this->name(
                sprintf(
                    'meeting-%s-upcoming',
                    $name,
                ),
                $upcoming,
                0,
            );
            $this->name(
                sprintf(
                    'meeting-%s-upcoming-2',
                    $name,
                ),
                $upcoming,
                1,
            );
        }
    }

    /**
     * Meetings of one kind, oldest first, split into those held and those still to come.
     *
     * @param Meeting[] $meetings
     *
     * @return array{0: Meeting[], 1: Meeting[]}
     */
    private function split(
        array $meetings,
        DateTime $today,
    ): array {
        usort(
            $meetings,
            static fn (Meeting $a, Meeting $b): int => $a->getDate() <=> $b->getDate(),
        );

        $past = [];
        $upcoming = [];

        foreach ($meetings as $meeting) {
            if ($meeting->getDate() < $today) {
                $past[] = $meeting;
            } else {
                $upcoming[] = $meeting;
            }
        }

        return [
            $past,
            $upcoming,
        ];
    }

    /**
     * Name one meeting out of a run of them, counted from the end when the offset is negative.
     *
     * @param Meeting[] $meetings
     */
    private function name(
        string $reference,
        array $meetings,
        int $offset,
    ): void {
        $index = $offset < 0
            ? count($meetings) + $offset
            : $offset;

        if (!isset($meetings[$index])) {
            return;
        }

        $this->addReference(
            $reference,
            $meetings[$index],
        );
    }

    /**
     * @return string[]
     */
    #[Override]
    public static function getGroups(): array
    {
        return ['web'];
    }
}
