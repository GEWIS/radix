<?php

declare(strict_types=1);

namespace App\Util\Application;

use Symfony\Component\HttpFoundation\Request;

use function filter_var;
use function is_scalar;

use const FILTER_VALIDATE_BOOL;
use const FILTER_VALIDATE_INT;

final readonly class QueryValue
{
    public static function isSet(
        ?Request $request,
        string $name,
    ): bool {
        return (bool) filter_var(
            self::scalar(
                $request,
                $name,
            ),
            FILTER_VALIDATE_BOOL,
        );
    }

    public static function text(
        ?Request $request,
        string $name,
    ): string {
        return (string) self::scalar(
            $request,
            $name,
        );
    }

    public static function number(
        ?Request $request,
        string $name,
        int $default = 0,
    ): int {
        $value = filter_var(
            self::scalar(
                $request,
                $name,
            ),
            FILTER_VALIDATE_INT,
        );

        return false === $value
            ? $default
            : $value;
    }

    private static function scalar(
        ?Request $request,
        string $name,
    ): string|int|float|bool|null {
        $value = $request?->query->all()[$name] ?? null;

        return is_scalar($value)
            ? $value
            : null;
    }
}
