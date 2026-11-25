<?php

declare(strict_types=1);

namespace App\Tests\Util\Application;

use App\Attribute\User\Replayable;
use App\Util\Application\ControllerAttribute;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * A route names its controller as a string, so both the invokable form (the class alone) and the `Class::method` form
 * have to resolve before an attribute can be read from the action.
 */
final class ControllerAttributeTest extends TestCase
{
    public function testAnInvokableControllerIsReadFromItsClass(): void
    {
        self::assertTrue(ControllerAttribute::isPresent(
            $this->fixture(),
            Replayable::class,
        ));
    }

    public function testANamedActionIsReadFromItsMethod(): void
    {
        self::assertTrue(ControllerAttribute::isPresent(
            $this->fixture() . '::replayable',
            Replayable::class,
        ));
        self::assertFalse(ControllerAttribute::isPresent(
            $this->fixture() . '::plain',
            Replayable::class,
        ));
    }

    public function testAControllerThatIsNotAStringIsNotReflected(): void
    {
        self::assertFalse(ControllerAttribute::isPresent(
            new stdClass(),
            Replayable::class,
        ));
    }

    public function testAnActionThatDoesNotExistIsNotAnAttributeMatch(): void
    {
        self::assertFalse(ControllerAttribute::isPresent(
            $this->fixture() . '::missing',
            Replayable::class,
        ));
    }

    /**
     * A route string names one action; a second `::` is not part of a method name and must not be read as one.
     */
    public function testAControllerStringWithMoreThanOneMethodSeparatorIsRefused(): void
    {
        self::assertFalse(ControllerAttribute::isPresent(
            $this->fixture() . '::replayable::extra',
            Replayable::class,
        ));
    }

    private function fixture(): string
    {
        return (new class {
            #[Replayable]
            public function __invoke(): void
            {
            }

            #[Replayable]
            public function replayable(): void
            {
            }

            public function plain(): void
            {
            }
        })::class;
    }
}
