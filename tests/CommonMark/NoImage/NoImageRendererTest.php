<?php

declare(strict_types=1);

namespace App\Tests\CommonMark\NoImage;

use App\CommonMark\NoImage\NoImageExtension;
use App\CommonMark\NoImage\NoImageRenderer;
use InvalidArgumentException;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use PHPUnit\Framework\TestCase;

/**
 * User-authored Markdown must not embed a remote image. An image is rendered as its literal source text, with the
 * URL and title escaped rather than written as HTML.
 */
final class NoImageRendererTest extends TestCase
{
    public function testAnImageIsRenderedAsItsSourceText(): void
    {
        $html = $this->convert('![a cat](https://example.com/cat.png "the cat")');

        self::assertStringNotContainsString(
            '<img',
            $html,
        );
        self::assertStringContainsString(
            '![the cat](https://example.com/cat.png)',
            $html,
        );
    }

    public function testAnImageWithoutATitleKeepsItsSourceShape(): void
    {
        $html = $this->convert('![a cat](https://example.com/cat.png)');

        self::assertStringContainsString(
            '![](https://example.com/cat.png)',
            $html,
        );
    }

    /**
     * The view page renders the un-stripped result, so metacharacters in the URL and title are escaped.
     */
    public function testTheUrlAndTitleAreEscaped(): void
    {
        $renderer = new NoImageRenderer();
        $image = new Image(
            'https://example.com/"onmouseover="alert(1)',
            null,
            '<script>',
        );

        $rendered = $renderer->render(
            $image,
            self::createStub(ChildNodeRendererInterface::class),
        );

        self::assertStringNotContainsString(
            '<script>',
            $rendered,
        );
        self::assertStringNotContainsString(
            '"onmouseover',
            $rendered,
        );
        self::assertStringContainsString(
            '&lt;script&gt;',
            $rendered,
        );
    }

    private function convert(string $markdown): string
    {
        $environment = new Environment();
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new NoImageExtension());

        return new MarkdownConverter($environment)->convert($markdown)->getContent();
    }

    /**
     * The renderer is registered for images only; reading a title from any other node would be a type error, so it
     * refuses the node before touching it.
     */
    public function testANodeThatIsNotAnImageIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new NoImageRenderer()->render(
            new Paragraph(),
            self::createStub(ChildNodeRendererInterface::class),
        );
    }
}
