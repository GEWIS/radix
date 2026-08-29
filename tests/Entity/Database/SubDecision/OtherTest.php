<?php

declare(strict_types=1);

namespace App\Tests\Entity\Database\SubDecision;

use App\Entity\Application\Enums\AppLanguages;
use App\Entity\Database\SubDecision\Other;
use App\Tests\Support\BuildsDecisions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Other::class)]
class OtherTest extends TestCase
{
    use BuildsDecisions;

    public function testReadsInTheLanguageItWasWrittenIn(): void
    {
        $other = $this->other(
            $this->decision(),
            'Er wordt een taart gekocht.',
            1,
            'A cake is bought.',
        );

        self::assertSame(
            'Er wordt een taart gekocht.',
            $other->getTranslatedContent(
                $this->translator(),
                AppLanguages::Dutch,
            ),
        );
        self::assertSame(
            'A cake is bought.',
            $other->getTranslatedContent(
                $this->translator(),
                AppLanguages::English,
            ),
        );
    }

    public function testApologisesUntilItHasBeenTranslated(): void
    {
        $other = $this->other(
            $this->decision(),
            'Er wordt een taart gekocht.',
        );

        self::assertNull($other->getContentEN());
        self::assertSame(
            'Er wordt een taart gekocht.',
            $other->getTranslatedContent(
                $this->translator(),
                AppLanguages::Dutch,
            ),
        );
        self::assertSame(
            'If you are reading this, the secretary has not done their job.',
            $other->getTranslatedContent(
                $this->translator(),
                AppLanguages::English,
            ),
        );
    }

    public function testKeepsWhatWasDecidedWhenItIsTranslated(): void
    {
        $other = $this->other(
            $this->decision(),
            'Er wordt een taart gekocht.',
        );
        $other->setContentEN('A cake is bought.');

        self::assertSame(
            'Er wordt een taart gekocht.',
            $other->getContentNL(),
        );
        self::assertSame(
            'A cake is bought.',
            $other->getTranslatedContent(
                $this->translator(),
                AppLanguages::English,
            ),
        );
    }
}
