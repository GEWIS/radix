<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function assert;
use function count;
use function file_get_contents;
use function implode;
use function in_array;
use function is_array;
use function preg_match_all;
use function sprintf;
use function str_ends_with;
use function str_replace;
use function strrpos;
use function substr;
use function token_get_all;

use const T_ATTRIBUTE;
use const T_COMMENT;
use const T_CONSTANT_ENCAPSED_STRING;
use const T_DOC_COMMENT;
use const T_NAME_FULLY_QUALIFIED;
use const T_NAME_QUALIFIED;
use const T_NEW;
use const T_STRING;
use const T_WHITESPACE;

/**
 * An ExpressionLanguage string is not PHP: it is not type-checked, no refactoring tool can see into it, and a method
 * it names that no longer exists fails only when the expression is evaluated. Entity state is therefore read from an
 * expression as a property and never through an accessor, so that removing one cannot leave a string calling it.
 *
 * This is not hypothetical. Forty-nine of these read an identifier through `getId()`, and when the entities lost that
 * method every page whose lock or token id was built this way failed instead of rendering.
 */
final class ExpressionAccessorTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../src';

    /**
     * Attribute arguments that contain an ExpressionLanguage string: `#[Assert\When(expression: ...)]` and the
     * `security:` of an API Platform operation. These are strings in argument position rather than `new Expression()`
     * calls, so the token walk looks for them by name.
     */
    private const array EXPRESSION_ARGUMENTS = [
        'expression',
        'security',
        'securityPostDenormalize',
    ];

    /**
     * Attributes whose `expression:` is not one. Symfony's scheduler names a cron line that.
     */
    private const array NOT_EXPRESSIONS = ['AsCronTask'];

    public function testNoExpressionCallsAnAccessor(): void
    {
        $offenders = [];
        $read = 0;

        foreach ($this->sources() as $file) {
            $code = file_get_contents($file->getPathname());
            if (false === $code) {
                continue;
            }

            foreach ($this->expressionsIn($code) as $fragments) {
                ++$read;

                foreach ($fragments as $fragment) {
                    if (
                        0 === preg_match_all(
                            '/\.((?:get|is|has)[A-Z][A-Za-z0-9_]*)\(/',
                            $fragment,
                            $accessors,
                        )
                    ) {
                        continue;
                    }

                    foreach ($accessors[1] as $accessor) {
                        $offenders[] = sprintf(
                            '%s: %s() in `%s`',
                            str_replace(
                                self::ROOT . '/',
                                '',
                                $file->getPathname(),
                            ),
                            $accessor,
                            implode(
                                ' . ',
                                $fragments,
                            ),
                        );
                    }
                }
            }
        }

        self::assertGreaterThan(
            20,
            $read,
            'Too few expressions were found, so the token walk is wrong.',
        );
        self::assertSame(
            [],
            $offenders,
            'An expression calls an accessor. Write `args["x"].id`, not `args["x"].getId()`.',
        );
    }

    /**
     * The string fragments every expression in a file is written from, whether it is constructed as
     * `new Expression()` or passed to an attribute as a named argument.
     *
     * Read from the token stream rather than matched in the source text. An expression is not always a single quoted
     * string on one line: several are concatenations around an enum value, and a pattern that stops at the first
     * closing quote matches only the fragment before the concatenation.
     *
     * The fragments are returned separately so that the end of one and the start of the next cannot combine into a
     * call that neither of them contains.
     *
     * @return iterable<list<string>>
     */
    private function expressionsIn(string $code): iterable
    {
        $tokens = token_get_all($code);
        $count = count($tokens);

        for ($index = 0; $index < $count; ++$index) {
            $token = $tokens[$index];
            if (!is_array($token)) {
                continue;
            }

            if (T_ATTRIBUTE === $token[0]) {
                $end = $this->attributeEnd(
                    $tokens,
                    $index,
                );

                yield from $this->argumentExpressionsIn(
                    $tokens,
                    $index,
                    $end,
                );

                continue;
            }

            if (T_NEW !== $token[0]) {
                continue;
            }

            $name = $this->nextMeaningful(
                $tokens,
                $index + 1,
            );
            if (null === $name) {
                continue;
            }

            $named = $tokens[$name];
            if (
                !is_array($named)
                || !in_array(
                    $named[0],
                    [
                        T_STRING,
                        T_NAME_QUALIFIED,
                        T_NAME_FULLY_QUALIFIED,
                    ],
                    true,
                )
                || (
                    'Expression' !== $named[1]
                    && !str_ends_with(
                        $named[1],
                        '\\Expression',
                    )
                )
            ) {
                continue;
            }

            $open = $this->nextMeaningful(
                $tokens,
                $name + 1,
            );
            if (
                null === $open
                || '(' !== $tokens[$open]
            ) {
                continue;
            }

            // Read up to the parenthesis that closes this call, so that a multi-line expression, or one containing
            // a nested call, is matched completely.
            $fragments = [];
            $depth = 1;
            for ($inner = $open + 1; $inner < $count && $depth > 0; ++$inner) {
                $part = $tokens[$inner];

                if (!is_array($part)) {
                    if ('(' === $part) {
                        ++$depth;
                    } elseif (')' === $part) {
                        --$depth;
                    }

                    continue;
                }

                if (T_CONSTANT_ENCAPSED_STRING !== $part[0]) {
                    continue;
                }

                $fragments[] = substr(
                    $part[1],
                    1,
                    -1,
                );
            }

            if ([] === $fragments) {
                continue;
            }

            yield $fragments;

            $index = $open;
        }
    }

    /**
     * The expressions an attribute contains as a named argument, between the `#[` at `$open` and the `]` closing it.
     *
     * The whole region is traversed rather than the attribute's own argument list, so that an operation nested in
     * `#[ApiResource(operations: [new Get(security: '...')])]` is read as readily as an argument of the attribute
     * itself.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     *
     * @return iterable<list<string>>
     */
    private function argumentExpressionsIn(
        array $tokens,
        int $open,
        int $end,
    ): iterable {
        $name = $this->nextMeaningful(
            $tokens,
            $open + 1,
        );
        if (null === $name) {
            return;
        }

        $named = $tokens[$name];
        if (
            is_array($named)
            && in_array(
                $this->shortName($named[1]),
                self::NOT_EXPRESSIONS,
                true,
            )
        ) {
            return;
        }

        for ($index = $open + 1; $index < $end; ++$index) {
            $token = $tokens[$index];
            if (
                !is_array($token)
                || T_STRING !== $token[0]
                || !in_array(
                    $token[1],
                    self::EXPRESSION_ARGUMENTS,
                    true,
                )
            ) {
                continue;
            }

            $colon = $this->nextMeaningful(
                $tokens,
                $index + 1,
            );
            if (
                null === $colon
                || ':' !== $tokens[$colon]
            ) {
                continue;
            }

            $fragments = $this->fragmentsOfArgument(
                $tokens,
                $colon + 1,
                $end,
            );
            if ([] === $fragments) {
                continue;
            }

            yield $fragments;
        }
    }

    /**
     * The string fragments of the argument beginning at `$from`, up to the comma that ends it.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     *
     * @return list<string>
     */
    private function fragmentsOfArgument(
        array $tokens,
        int $from,
        int $end,
    ): array {
        $fragments = [];
        $depth = 0;

        for ($index = $from; $index < $end; ++$index) {
            $token = $tokens[$index];

            if (!is_array($token)) {
                if (
                    '(' === $token
                    || '[' === $token
                ) {
                    ++$depth;
                } elseif (
                    ')' === $token
                    || ']' === $token
                ) {
                    if (0 === $depth) {
                        break;
                    }

                    --$depth;
                } elseif (
                    ',' === $token
                    && 0 === $depth
                ) {
                    break;
                }

                continue;
            }

            if (T_CONSTANT_ENCAPSED_STRING !== $token[0]) {
                continue;
            }

            $fragments[] = substr(
                $token[1],
                1,
                -1,
            );
        }

        return $fragments;
    }

    /**
     * The index of the bracket closing the attribute that opens at `$open`.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     */
    private function attributeEnd(
        array $tokens,
        int $open,
    ): int {
        $count = count($tokens);
        $depth = 1;

        for ($index = $open + 1; $index < $count; ++$index) {
            $token = $tokens[$index];

            if (is_array($token)) {
                if (T_ATTRIBUTE === $token[0]) {
                    ++$depth;
                }

                continue;
            }

            if ('[' === $token) {
                ++$depth;
            } elseif (']' === $token) {
                --$depth;

                if (0 === $depth) {
                    return $index;
                }
            }
        }

        return $count;
    }

    /**
     * The last segment of a name, which is what an attribute is recognised by however it is imported.
     */
    private function shortName(string $name): string
    {
        $separator = strrpos(
            $name,
            '\\',
        );

        return false === $separator
            ? $name
            : substr(
                $name,
                $separator + 1,
            );
    }

    /**
     * The next token that is not whitespace or a comment. `new`, the class name and the opening parenthesis can be
     * separated by either.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     */
    private function nextMeaningful(
        array $tokens,
        int $from,
    ): ?int {
        $count = count($tokens);

        for ($index = $from; $index < $count; ++$index) {
            $token = $tokens[$index];
            if (
                is_array($token)
                && in_array(
                    $token[0],
                    [
                        T_WHITESPACE,
                        T_COMMENT,
                        T_DOC_COMMENT,
                    ],
                    true,
                )
            ) {
                continue;
            }

            return $index;
        }

        return null;
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function sources(): iterable
    {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::ROOT)) as $file) {
            assert($file instanceof SplFileInfo);
            if (
                $file->isDir()
                || 'php' !== $file->getExtension()
            ) {
                continue;
            }

            yield $file;
        }
    }
}
