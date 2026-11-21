<?php

declare(strict_types=1);

namespace App\Tests\Integration\LiveComponent\Activity\Admin;

use App\Entity\Decision\Organ;
use App\Entity\User\User;
use App\Repository\Decision\OrganRepository;
use App\Tests\Integration\DatabaseTestCase;
use App\Twig\Components\Activity\Admin\OptionCalendar;
use DateTimeImmutable;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function sprintf;

/**
 * The month grid of the agenda beside the days bodies request. The month and body come from the address and are
 * re-read rather than trusted; changing month is an action, and a live action bypasses the page's gate.
 */
final class OptionCalendarTest extends DatabaseTestCase
{
    public function testTheMonthInTheAddressIsTheOneShown(): void
    {
        $component = $this->calendar('2026-03');

        $grid = $component->getGrid();

        self::assertSame(
            '2026-03-01',
            $grid->firstDay->format('Y-m-d'),
        );
        // Always six weeks, so the grid does not jump about as the months change.
        self::assertCount(
            6,
            $grid->weeks,
        );
    }

    public function testSomethingThatIsNotAMonthShowsThisOne(): void
    {
        foreach (['', 'nonsense', '2026-3', '2026-13-01'] as $month) {
            $component = $this->calendar($month);

            self::assertSame(
                new DateTimeImmutable('first day of this month')->format('Y-m-d'),
                $component->getGrid()->firstDay->format('Y-m-d'),
                sprintf(
                    '"%s" is not a month, so the grid falls back to this one.',
                    $month,
                ),
            );
        }
    }

    public function testMovingThroughTheMonthsCrossesTheYearBoundary(): void
    {
        $component = $this->calendar('2026-01');
        $component->previousMonth();
        self::assertSame(
            '2025-12',
            $component->month,
        );

        $component = $this->calendar('2026-12');
        $component->nextMonth();
        self::assertSame(
            '2027-01',
            $component->month,
        );

        $component->today();
        self::assertSame(
            new DateTimeImmutable('today')->format('Y-m'),
            $component->month,
        );
    }

    public function testTheGridCanBeNarrowedToOneBody(): void
    {
        $organs = self::getContainer()->get(OrganRepository::class)->findActive();
        self::assertNotEmpty($organs);
        $organ = $organs[0];
        self::assertInstanceOf(
            Organ::class,
            $organ,
        );

        $component = $this->calendar(
            new DateTimeImmutable('today')->format('Y-m'),
            $organ->id,
        );

        self::assertSame(
            $organ,
            $component->getSelectedOrgan(),
        );
        self::assertContains(
            $organ,
            $component->getOrgans(),
        );
    }

    public function testABodyThatDoesNotExistNarrowsNothing(): void
    {
        $component = $this->calendar(
            new DateTimeImmutable('today')->format('Y-m'),
            999999,
        );

        self::assertNull($component->getSelectedOrgan());
    }

    public function testSomebodyWhoIsNeitherAMemberNorOnTheBoardCannotMoveTheCalendar(): void
    {
        $component = $this->calendar('2026-03');
        self::getContainer()->get('security.token_storage')->setToken(null);

        $this->expectException(AccessDeniedException::class);
        $component->nextMonth();
    }

    private function calendar(
        string $month,
        ?int $organ = null,
    ): OptionCalendar {
        $user = $this->entityManager->getRepository(User::class)->find(8030);
        self::assertInstanceOf(
            User::class,
            $user,
        );
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken(
            $user,
            'main',
            ['ROLE_ACTIVE_MEMBER'],
        ));

        $component = self::getContainer()->get(OptionCalendar::class);
        $component->month = $month;
        $component->organ = $organ;

        return $component;
    }
}
