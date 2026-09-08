<?php

declare(strict_types=1);

namespace App\Tests\Support;

use ArrayObject;
use Redis;

/**
 * In-memory stand-in for Valkey.
 *
 * TTLs are not implemented because the callers read expiry from the stored value rather than from the key.
 */
trait StandsInForValkey
{
    private function valkey(): Redis
    {
        /** @var ArrayObject<string, string> $store */
        $store = new ArrayObject();

        $valkey = self::createStub(Redis::class);
        $valkey->method('setex')->willReturnCallback(
            static function (
                string $key,
                int $expire,
                mixed $value,
            ) use ($store): bool {
                $store[$key] = (string) $value;

                return true;
            },
        );
        $valkey->method('get')->willReturnCallback(
            static fn (string $key): string|false => $store[$key] ?? false,
        );
        $valkey->method('del')->willReturnCallback(
            static function (
                array|string $key,
                string ...$otherKeys,
            ) use ($store): int {
                $removed = 0;

                foreach (
                    [
                        ...(array) $key,
                        ...$otherKeys,
                    ] as $one
                ) {
                    if (!$store->offsetExists($one)) {
                        continue;
                    }

                    $store->offsetUnset($one);
                    ++$removed;
                }

                return $removed;
            },
        );

        return $valkey;
    }
}
