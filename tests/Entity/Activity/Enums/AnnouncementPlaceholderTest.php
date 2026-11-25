<?php

declare(strict_types=1);

namespace App\Tests\Entity\Activity\Enums;

use App\Entity\Activity\Enums\AnnouncementPlaceholder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_map;
use function array_unique;
use function count;

#[CoversClass(AnnouncementPlaceholder::class)]
final class AnnouncementPlaceholderTest extends TestCase
{
    /**
     * The values are the reserved vocabulary a message may use; a duplicate would make one placeholder unreachable.
     */
    public function testEveryPlaceholderHasAUniqueName(): void
    {
        $values = array_map(
            static fn (AnnouncementPlaceholder $placeholder): string => $placeholder->value,
            AnnouncementPlaceholder::cases(),
        );

        self::assertCount(
            count($values),
            array_unique($values),
        );
        self::assertSame(
            'ACTIVITY_NAME',
            AnnouncementPlaceholder::ActivityName->value,
        );
    }

    /**
     * Only the list name needs to know which list the message is for; the rest are properties of the activity.
     */
    public function testOnlyTheListNameNeedsASignupList(): void
    {
        self::assertTrue(AnnouncementPlaceholder::SignupListName->needsSignupList());

        foreach (AnnouncementPlaceholder::cases() as $placeholder) {
            if (AnnouncementPlaceholder::SignupListName === $placeholder) {
                continue;
            }

            self::assertFalse(
                $placeholder->needsSignupList(),
                $placeholder->value,
            );
        }
    }

    public function testEveryPlaceholderHasItsOwnLabel(): void
    {
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $labels = [];
        foreach (AnnouncementPlaceholder::cases() as $placeholder) {
            $labels[$placeholder->trans($translator)] = true;
        }

        self::assertCount(
            count(AnnouncementPlaceholder::cases()),
            $labels,
        );
    }
}
