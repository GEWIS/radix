<?php

declare(strict_types=1);

namespace App\Tests\Twig\Extensions;

use App\Twig\Extensions\HighlightSearchExtension;
use PHPUnit\Framework\TestCase;

final class HighlightSearchExtensionTest extends TestCase
{
    /**
     * @param list<string> $terms
     */
    private function highlight(
        string $content,
        array $terms,
    ): string {
        return new HighlightSearchExtension()->highlight(
            $content,
            $terms,
        );
    }

    public function testMarksEveryCaseInsensitiveOccurrence(): void
    {
        self::assertSame(
            'De <mark>borrel</mark> na de <mark>Borrel</mark>',
            $this->highlight(
                'De borrel na de Borrel',
                ['borrel'],
            ),
        );
    }

    public function testMatchesAcrossAccents(): void
    {
        self::assertSame(
            "Het <mark>besl\u{00fa}it</mark> staat",
            $this->highlight(
                "Het besl\u{00fa}it staat",
                ['besluit'],
            ),
        );
    }

    public function testEscapesContentAndMatchesEscapedTerms(): void
    {
        self::assertSame(
            'Stemmen over &lt;b&gt; en <mark>R&amp;D</mark>',
            $this->highlight(
                'Stemmen over <b> en R&D',
                ['R&D'],
            ),
        );
    }

    public function testOverlappingTermsMergeIntoOneMark(): void
    {
        self::assertSame(
            'De <mark>kerstboomborrel</mark> komt',
            $this->highlight(
                'De kerstboomborrel komt',
                [
                    'kerstboom',
                    'boomborrel',
                ],
            ),
        );
    }

    public function testNoTermsReturnsTheEscapedContent(): void
    {
        self::assertSame(
            'a &amp; b',
            $this->highlight(
                'a & b',
                [],
            ),
        );
    }

    /**
     * A match can start at the first character; the range state must treat that as an open range, not as “no range
     * yet”.
     */
    public function testAMatchAtTheStartIsMarked(): void
    {
        self::assertSame(
            '<mark>borrel</mark> na de vergadering',
            $this->highlight(
                'borrel na de vergadering',
                ['borrel'],
            ),
        );
    }

    /**
     * Two terms that touch produce one continuous mark; splitting them would put `</mark><mark>` in the middle of a
     * word.
     */
    public function testAdjacentTermsAreMergedIntoOneMark(): void
    {
        self::assertSame(
            '<mark>abc</mark>',
            $this->highlight(
                'abc',
                [
                    'ab',
                    'bc',
                ],
            ),
        );
    }

    public function testThreeOverlappingTermsMergeIntoOneMark(): void
    {
        self::assertSame(
            '<mark>abcdef</mark>',
            $this->highlight(
                'abcdef',
                [
                    'abcd',
                    'cdef',
                    'bcde',
                ],
            ),
        );
    }

    /**
     * The terms arrive in whatever order the search box produced them; the output follows the text.
     */
    public function testTermsOutOfTextOrderAreEmittedInTextOrder(): void
    {
        self::assertSame(
            '<mark>foo</mark> <mark>bar</mark>',
            $this->highlight(
                'foo bar',
                [
                    'bar',
                    'foo',
                ],
            ),
        );
    }

    /**
     * A needle and its text can both contain multibyte characters; both the match offset and the length must be
     * counted in characters, or the mark lands on the wrong bytes.
     */
    public function testAMultibyteNeedleAfterMultibyteTextIsMarkedAtTheRightOffset(): void
    {
        self::assertSame(
            "é <mark>besl\u{00fa}it</mark> x",
            $this->highlight(
                "é besl\u{00fa}it x",
                ["besl\u{00fa}it"],
            ),
        );
    }

    /**
     * The filter returns HTML, so a quote in the source text is escaped as an entity rather than emitted raw.
     */
    public function testASingleQuoteIsEscaped(): void
    {
        self::assertSame(
            'It&#039;s',
            $this->highlight(
                "It's",
                [],
            ),
        );
    }

    /**
     * User text is not guaranteed to be UTF-8. Invalid bytes are replaced, not silently dropped.
     */
    public function testInvalidUtf8IsReplacedRatherThanDropped(): void
    {
        self::assertSame(
            "\u{FFFD}",
            $this->highlight(
                "\xFF",
                [],
            ),
        );
    }
}
