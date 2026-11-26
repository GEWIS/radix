<?php

declare(strict_types=1);

namespace App\Tests\ViewModel\Application;

use App\ViewModel\Application\Notification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_replace;

/**
 * What the administration dashboard and the notification bell report about the register.
 *
 * The status array is built here rather than read from the seed: whether a mailing list has fetched recently depends
 * on how long ago the seed was loaded, so the lines a seed-driven test executes differ between runs.
 */
#[CoversClass(Notification::class)]
final class NotificationTest extends TestCase
{
    public function testNothingIsReportedWhenTheRegisterNeedsNothing(): void
    {
        self::assertSame(
            [],
            Notification::fromRegisterStatus($this->registerStatus()),
        );
    }

    public function testPaidProspectivesAreReportedForApproval(): void
    {
        $notifications = Notification::fromRegisterStatus($this->registerStatus([
            'prospectives' => ['paid' => 3],
        ]));

        self::assertCount(
            1,
            $notifications,
        );
        self::assertSame(
            'fa-user-check',
            $notifications[0]->icon,
        );
        self::assertSame(
            'warning',
            $notifications[0]->tone,
        );
        self::assertSame(
            '%number% prospective members have paid',
            $notifications[0]->title->getMessage(),
        );
        self::assertSame(
            ['%number%' => 3],
            $notifications[0]->title->getParameters(),
        );
        self::assertSame(
            'Approval',
            $notifications[0]->kind->getMessage(),
        );
        self::assertSame(
            'Review',
            $notifications[0]->action->getMessage(),
        );
        self::assertSame(
            'join_prospective_member_index',
            $notifications[0]->route,
        );
    }

    public function testAPausedSynchronisationIsReported(): void
    {
        $notifications = Notification::fromRegisterStatus($this->registerStatus([
            'sync_paused' => true,
        ]));

        self::assertCount(
            1,
            $notifications,
        );
        self::assertSame(
            'fa-pause',
            $notifications[0]->icon,
        );
        self::assertSame(
            'info',
            $notifications[0]->tone,
        );
        self::assertSame(
            'Synchronisation is paused',
            $notifications[0]->title->getMessage(),
        );
        self::assertSame(
            'Integration',
            $notifications[0]->kind->getMessage(),
        );
        self::assertSame(
            'Lists',
            $notifications[0]->action->getMessage(),
        );
        self::assertSame(
            'mailing_list_index',
            $notifications[0]->route,
        );
    }

    public function testEveryServiceThatHasNotFetchedRecentlyIsNamed(): void
    {
        $notifications = Notification::fromRegisterStatus($this->registerStatus([
            'mailman_last_fetch_overdue' => true,
            'listmonk_last_fetch_overdue' => true,
        ]));

        self::assertCount(
            2,
            $notifications,
        );
        self::assertSame(
            [
                'Mailman',
                'Listmonk',
            ],
            array_map(
                static fn (Notification $notification): string => $notification->title->getParameters()['%service%'],
                $notifications,
            ),
        );
        self::assertSame(
            'Diagnose',
            $notifications[0]->action->getMessage(),
        );
    }

    public function testAServiceThatFetchedRecentlyIsNotReported(): void
    {
        $notifications = Notification::fromRegisterStatus($this->registerStatus([
            'listmonk_last_fetch_overdue' => true,
        ]));

        self::assertCount(
            1,
            $notifications,
        );
        self::assertSame(
            'Listmonk',
            $notifications[0]->title->getParameters()['%service%'],
        );
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function registerStatus(array $overrides = []): array
    {
        return array_replace(
            [
                'prospectives' => ['paid' => 0],
                'sync_paused' => false,
                'mailman_last_fetch_overdue' => false,
                'listmonk_last_fetch_overdue' => false,
            ],
            $overrides,
        );
    }
}
