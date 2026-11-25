<?php

declare(strict_types=1);

namespace App\Tests\Entity\Frontpage\Enums;

use App\Entity\Frontpage\Enums\PollCommentReactionType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

use function count;

#[CoversClass(PollCommentReactionType::class)]
final class PollCommentReactionTypeTest extends TestCase
{
    /**
     * Each reaction is a different sentiment, so no two may share an icon or a word; two buttons would look the same.
     */
    public function testEveryReactionHasItsOwnIconAndLabel(): void
    {
        $icons = [];
        $labels = [];

        foreach (PollCommentReactionType::cases() as $reaction) {
            $icons[$reaction->icon()] = true;
            $labels[$reaction->trans($this->translator())] = true;
        }

        self::assertCount(
            count(PollCommentReactionType::cases()),
            $icons,
        );
        self::assertCount(
            count(PollCommentReactionType::cases()),
            $labels,
        );
    }

    public function testTheReactionsUseTheFontAwesomeIconsTheTemplateExpects(): void
    {
        self::assertSame(
            'fa-thumbs-up',
            PollCommentReactionType::Like->icon(),
        );
        self::assertSame(
            'fa-heart',
            PollCommentReactionType::Love->icon(),
        );
        self::assertSame(
            'fa-lightbulb',
            PollCommentReactionType::Insightful->icon(),
        );
        self::assertSame(
            'fa-face-grin-tears',
            PollCommentReactionType::Funny->icon(),
        );
    }

    public function testTheLabelIsTranslatedInTheGivenLocale(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with(
                'Funny',
                [],
                null,
                null,
            )
            ->willReturn('Grappig');

        self::assertSame(
            'Grappig',
            PollCommentReactionType::Funny->trans($translator),
        );
    }

    private function translator(): TranslatorInterface
    {
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return $translator;
    }
}
