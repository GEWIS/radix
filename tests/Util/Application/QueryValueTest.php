<?php

declare(strict_types=1);

namespace App\Tests\Util\Application;

use App\Util\Application\QueryValue;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Reading a query parameter decides what to do with visitor input, so the tests cover the value returned for each
 * shape a query string can take.
 */
final class QueryValueTest extends TestCase
{
    public function testATextValueIsHandedBackAsItWasTyped(): void
    {
        self::assertSame(
            'Half a page',
            QueryValue::text(
                $this->request(['q' => 'Half a page']),
                'q',
            ),
        );
    }

    public function testAMissingValueIsTheEmptyString(): void
    {
        self::assertSame(
            '',
            QueryValue::text(
                $this->request(),
                'q',
            ),
        );
        self::assertSame(
            '',
            QueryValue::text(
                null,
                'q',
            ),
        );
    }

    /**
     * An array in the query string is not a value of the type asked for, and treating it as one would put "Array" on
     * the page.
     */
    public function testAValueThatIsNotScalarIsNotAValue(): void
    {
        self::assertSame(
            '',
            QueryValue::text(
                $this->request(['q' => ['a', 'b']]),
                'q',
            ),
        );
        self::assertSame(
            0,
            QueryValue::number(
                $this->request(['page' => ['1']]),
                'page',
            ),
        );
        self::assertFalse(
            QueryValue::isSet(
                $this->request(['flag' => ['1']]),
                'flag',
            ),
        );
    }

    public function testANumberIsParsedAndFallsBackToItsDefault(): void
    {
        self::assertSame(
            3,
            QueryValue::number(
                $this->request(['page' => '3']),
                'page',
            ),
        );
        self::assertSame(
            7,
            QueryValue::number(
                $this->request(['page' => 'not a number']),
                'page',
                7,
            ),
        );
        self::assertSame(
            7,
            QueryValue::number(
                $this->request(),
                'page',
                7,
            ),
        );
    }

    public function testAFlagIsReadTheWayAQueryStringSpellsOne(): void
    {
        self::assertTrue(QueryValue::isSet($this->request(['all' => '1']), 'all'));
        self::assertTrue(QueryValue::isSet($this->request(['all' => 'true']), 'all'));
        self::assertFalse(QueryValue::isSet($this->request(['all' => '0']), 'all'));
        self::assertFalse(QueryValue::isSet($this->request(['all' => 'false']), 'all'));
        self::assertFalse(QueryValue::isSet($this->request(), 'all'));
        self::assertFalse(QueryValue::isSet(null, 'all'));
    }

    /**
     * @param array<string, mixed> $query
     */
    private function request(array $query = []): Request
    {
        return new Request($query);
    }
}
