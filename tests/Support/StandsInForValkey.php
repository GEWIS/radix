<?php

declare(strict_types=1);

namespace App\Tests\Support;

use ArrayObject;
use Psr\Clock\ClockInterface;
use Redis;
use Symfony\Component\Clock\MockClock;

/**
 * In-memory stand-in for Valkey.
 *
 * Expiry is kept, because a key whose own expiry has passed is gone whatever its value says, and a grant writes that
 * expiry from a window that moves. The clock is the same one the caller reads, so a test that moves time forward sees
 * a key disappear at the moment the real one would.
 */
trait StandsInForValkey
{
    private function valkey(?ClockInterface $clock = null): Redis
    {
        /** @var ArrayObject<string, array{string, int}> $store */
        $store = new ArrayObject();
        $clock ??= new MockClock();
        $now = static fn (): int => $clock->now()->getTimestamp();

        $valkey = self::createStub(Redis::class);
        $valkey->method('setex')->willReturnCallback(
            static function (
                string $key,
                int $expire,
                mixed $value,
            ) use (
                $store,
                $now,
            ): bool {
                $store[$key] = [
                    (string) $value,
                    $now() + $expire,
                ];

                return true;
            },
        );
        $valkey->method('get')->willReturnCallback(
            static function (string $key) use ($store, $now): string|false {
                $entry = $store[$key] ?? null;
                if (null === $entry) {
                    return false;
                }

                [
                    $value, $expiresAt
                ] = $entry;

                if ($expiresAt <= $now()) {
                    $store->offsetUnset($key);

                    return false;
                }

                return $value;
            },
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
