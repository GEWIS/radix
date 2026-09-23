<?php

declare(strict_types=1);

namespace App\Tests\Service\Application;

use App\Entity\Application\Enums\Languages;
use App\Entity\Application\Enums\NotificationType;
use App\Service\Application\DeviceDescription;
use App\Service\Application\NotificationContextResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(NotificationContextResolver::class)]
final class NotificationContextResolverTest extends TestCase
{
    public function testAClosingListIsNamedAfterItsActivity(): void
    {
        self::assertSame(
            'JetBrains Lunch Lecture (Lunch)',
            $this->resolve([
                'activityName' => 'JetBrains Lunch Lecture',
                'list' => 'Lunch',
            ]),
        );
    }

    public function testAListWithoutANameShowsOnlyTheActivity(): void
    {
        self::assertSame(
            'JetBrains Lunch Lecture',
            $this->resolve([
                'activityName' => 'JetBrains Lunch Lecture',
                'list' => '',
            ]),
        );
    }

    /**
     * Reminders made before the activity was kept in the context are still in the notification centre for a month.
     */
    public function testAContextWithoutAnActivityNameShowsTheList(): void
    {
        self::assertSame(
            'Lunch',
            $this->resolve([
                'list' => 'Lunch',
            ]),
        );
    }

    /**
     * @param array<string, string> $context
     */
    private function resolve(array $context): ?string
    {
        return new NotificationContextResolver(new DeviceDescription(self::createStub(TranslatorInterface::class)))
            ->resolve(
                NotificationType::SignupClosing,
                $context,
                Languages::English,
            );
    }
}
