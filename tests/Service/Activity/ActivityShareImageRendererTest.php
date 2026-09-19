<?php

declare(strict_types=1);

namespace App\Tests\Service\Activity;

use App\Entity\Activity\Activity;
use App\Entity\Activity\ActivityLocalisedText;
use App\Entity\Activity\ActivityRevision;
use App\Entity\Activity\Enums\ActivityCategories;
use App\Entity\Activity\SignupList;
use App\Entity\Application\Enums\Languages;
use App\Entity\Decision\Member;
use App\Service\Activity\ActivityShareImageRenderer;
use App\Service\Application\ImageManagerProvider;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;

use function dirname;
use function getimagesizefromstring;
use function str_repeat;

/**
 * The card has to be the size the consumers ask for, whatever the activity's text is. The pixels are not asserted:
 * they depend on the font.
 */
final class ActivityShareImageRendererTest extends TestCase
{
    public function testRendersAPngAtTheOpenGraphSize(): void
    {
        $bytes = $this->renderer()->render(
            $this->revision('Borrel'),
            Languages::English,
            new DateTimeImmutable('2026-09-19 12:00'),
        );

        $size = getimagesizefromstring($bytes);
        self::assertNotFalse($size);
        self::assertSame(
            [
                1200,
                630,
            ],
            [
                $size[0],
                $size[1],
            ],
        );
        self::assertSame(
            'image/png',
            $size['mime'],
        );
    }

    public function testALongNameAndAMissingLocationStillFit(): void
    {
        $revision = $this->revision(str_repeat(
            'Very long name ',
            20,
        ));
        $revision->location = new ActivityLocalisedText();

        $size = getimagesizefromstring($this->renderer()->render(
            $revision,
            Languages::Dutch,
            new DateTimeImmutable('2026-09-19 12:00'),
        ));

        self::assertNotFalse($size);
        self::assertSame(
            1200,
            $size[0],
        );
    }

    public function testASpanAcrossMonthsWithASignupListFits(): void
    {
        $revision = $this->revision('Summer Camp');
        $revision->beginTime = new DateTimeImmutable('2026-07-31 16:00');
        $revision->endTime = new DateTimeImmutable('2026-08-02 14:00');
        $list = new SignupList();
        $list->closeDate = new DateTimeImmutable('2026-07-20 23:59');
        $revision->addSignupList($list);

        $size = getimagesizefromstring($this->renderer()->render(
            $revision,
            Languages::English,
            new DateTimeImmutable('2026-09-19 12:00'),
        ));

        self::assertNotFalse($size);
        self::assertSame(
            630,
            $size[1],
        );
    }

    public function testACancelledActivityAndOneWhoseListHasNotOpenedAreTheirOwnCards(): void
    {
        $renderer = $this->renderer();
        $now = new DateTimeImmutable('2026-09-19 12:00');

        $plain = $renderer->render(
            $this->revision('Borrel'),
            Languages::English,
            $now,
        );

        $cancelled = $this->revision('Borrel');
        $cancelled->activity->cancel(self::createStub(Member::class));

        $upcoming = $this->revision('Borrel');
        $list = new SignupList();
        $list->openDate = new DateTimeImmutable('2026-09-25 12:00');
        $list->closeDate = new DateTimeImmutable('2026-10-01 12:00');
        $upcoming->addSignupList($list);

        $closed = $this->revision('Borrel');
        $list = new SignupList();
        $list->openDate = new DateTimeImmutable('2026-09-01 12:00');
        $list->closeDate = new DateTimeImmutable('2026-09-10 12:00');
        $closed->addSignupList($list);

        foreach ([$cancelled, $upcoming, $closed] as $revision) {
            self::assertNotSame(
                $plain,
                $renderer->render(
                    $revision,
                    Languages::English,
                    $now,
                ),
            );
        }
    }

    public function testTheTwoLanguagesAreDifferentCards(): void
    {
        $renderer = $this->renderer();
        $revision = $this->revision('Borrel');

        self::assertNotSame(
            $renderer->render(
                $revision,
                Languages::English,
                new DateTimeImmutable('2026-09-19 12:00'),
            ),
            $renderer->render(
                $revision,
                Languages::Dutch,
                new DateTimeImmutable('2026-09-19 12:00'),
            ),
        );
    }

    private function renderer(): ActivityShareImageRenderer
    {
        return new ActivityShareImageRenderer(
            new ImageManagerProvider(),
            new IdentityTranslator(),
            '/usr/share/fonts/opentype/inter',
            dirname(
                __DIR__,
                3,
            ) . '/assets/images/gewis.png',
        );
    }

    private function revision(string $name): ActivityRevision
    {
        $revision = new ActivityRevision();
        new Activity()->addRevision($revision);
        $revision->name = new ActivityLocalisedText(
            $name,
            $name,
        );
        $revision->location = new ActivityLocalisedText(
            'GEWIS room',
            'GEWIS-ruimte',
        );
        $revision->beginTime = new DateTimeImmutable('2026-10-02 16:30');
        $revision->endTime = new DateTimeImmutable('2026-10-02 20:00');
        $revision->category = ActivityCategories::SocialDrink;

        return $revision;
    }
}
