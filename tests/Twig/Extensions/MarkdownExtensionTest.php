<?php

declare(strict_types=1);

namespace App\Tests\Twig\Extensions;

use App\Twig\Extensions\MarkdownExtension;
use PHPUnit\Framework\TestCase;

/**
 * A comment is written with four buttons, three of which Markdown has syntax for. The fourth writes a `<u>` tag into
 * text that is otherwise escaped from end to end, so these pin what does and does not become an underline again.
 */
final class MarkdownExtensionTest extends TestCase
{
    private function comment(string $text): string
    {
        return new MarkdownExtension()->markdownComment($text);
    }

    public function testUnderlinesWhatTheEditorWrote(): void
    {
        self::assertSame(
            "<p>Really <u>not</u> done</p>\n",
            $this->comment('Really <u>not</u> done'),
        );
    }

    /**
     * A tag written inside code is shown rather than used, so it stays text.
     */
    public function testLeavesTheTagAloneInsideCode(): void
    {
        self::assertSame(
            "<p>Write <code>&lt;u&gt;this&lt;/u&gt;</code> for it</p>\n",
            $this->comment('Write `<u>this</u>` for it'),
        );
    }

    /**
     * An opening tag without a closing tag would otherwise underline everything after the comment.
     */
    public function testLeavesAnUnpairedTagAsText(): void
    {
        self::assertSame(
            "<p>Half a tag &lt;u&gt; and no more</p>\n",
            $this->comment('Half a tag <u> and no more'),
        );
    }

    /**
     * The excerpt is what a share card and a list row show, so it is one line of text however the source was laid out.
     */
    public function testAnExcerptIsPlainTextOnOneLine(): void
    {
        $extension = new MarkdownExtension();

        self::assertSame(
            'Tom & Jerry meet at the bar. Bring a friend.',
            $extension->htmlExcerpt(
                "<h1>Tom &amp; Jerry</h1>\n<p>meet at   the <em>bar</em>.</p>\n\n<p>Bring a\nfriend.</p>",
                200,
            ),
        );
        self::assertSame(
            'Tom & Jerry meet at the bar. Bring a friend.',
            $extension->markdownExcerpt(
                "# Tom & Jerry\n\nmeet at   the *bar*.\n\nBring a\nfriend.",
                200,
            ),
        );
        self::assertSame(
            'Tom & Jerry…',
            $extension->htmlExcerpt(
                '<p>Tom &amp; Jerry meet at the bar.</p>',
                12,
            ),
        );
    }

    public function testAdmitsNoAttributesAndNoOtherTags(): void
    {
        self::assertSame(
            "<p>&lt;u onclick=\"x\"&gt;no&lt;/u&gt;</p>\n",
            $this->comment('<u onclick="x">no</u>'),
        );
        self::assertSame(
            "&lt;script&gt;alert()&lt;/script&gt;\n",
            $this->comment('<script>alert()</script>'),
        );
    }
}
