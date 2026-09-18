<?php

declare(strict_types=1);

namespace App\Util\Application;

use ReflectionException;
use ReflectionMethod;

use function explode;
use function is_string;
use function str_contains;

/**
 * Whether the action a route resolves to declares a given attribute.
 *
 * A route states its controller as a string, so the attribute is only reachable by reflection. Two places need the
 * same few lines: {@see \App\EventListener\Application\InvalidSubmissionStatusListener} tests for
 * {@see \App\Attribute\Application\RendersOnSuccess} and {@see \App\Security\User\SudoReplay} for
 * {@see \App\Attribute\User\Replayable}.
 */
final class ControllerAttribute
{
    /**
     * @param class-string $attribute
     */
    public static function isPresent(
        mixed $controller,
        string $attribute,
    ): bool {
        if (!is_string($controller)) {
            return false;
        }

        // An invokable controller is identified by its class alone; everything else appends `::method`.
        [
            $class, $method
        ] = str_contains(
            $controller,
            '::',
        )
            ? explode(
                '::',
                $controller,
                2,
            )
            : [
                $controller,
                '__invoke',
            ];

        try {
            $action = new ReflectionMethod(
                $class,
                $method,
            );
        } catch (ReflectionException) {
            return false;
        }

        return [] !== $action->getAttributes($attribute);
    }
}
