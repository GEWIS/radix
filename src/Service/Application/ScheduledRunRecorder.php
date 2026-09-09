<?php

declare(strict_types=1);

namespace App\Service\Application;

use DateTimeImmutable;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function hash;
use function is_int;
use function preg_replace;
use function substr;

/**
 * When each scheduled command last completed. Written by {@see RecordScheduledRunListener} on the worker that ran
 * it and read by {@see ScheduledTaskStatusProvider}. Both halves are one class because the key a run is written
 * under has to be the key the dashboard reads it back by.
 *
 * On `cache.app`, which is Valkey and so is shared between the workers and the web containers and survives a
 * deploy. A run is a fact about the deployment rather than about one container. A record lost to a flushed cache
 * reports a task as not having run, which is the wrong answer in the direction that gets looked at.
 */
final readonly class ScheduledRunRecorder
{
    private const string PREFIX = 'scheduled_run.';

    /** Set the first time anything asks, so a fresh deployment measures lateness from itself rather than from 1970. */
    private const string SINCE_KEY = 'scheduled_run_since';

    private const int EXPIRY = 5 * 365 * 86400;

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cache,
        private ClockInterface $clock,
    ) {
    }

    public function record(
        string $command,
        DateTimeImmutable $at,
    ): void {
        // Touched here as well as on the dashboard, so the point lateness is measured from is roughly when this
        // deployment started running things rather than whenever an administrator first looked.
        $this->since();

        $this->save(
            $this->cache->getItem($this->key($command)),
            $at->getTimestamp(),
        );
    }

    public function lastRun(string $command): ?DateTimeImmutable
    {
        return $this->read($this->cache->getItem($this->key($command)));
    }

    /**
     * The point a command that has never been recorded is measured from. Without it every task is reported late for
     * as long as its own period after the cache is first written.
     */
    public function since(): DateTimeImmutable
    {
        $item = $this->cache->getItem(self::SINCE_KEY);
        $stored = $this->read($item);

        if (null !== $stored) {
            return $stored;
        }

        $now = $this->clock->now();
        $this->save(
            $item,
            $now->getTimestamp(),
        );

        return $now;
    }

    /**
     * Anything but the integer this writes is treated as absent: a cache holding something else is a cache that has
     * been written to by something other than this, and guessing at it would report a run that never happened.
     */
    private function read(CacheItemInterface $item): ?DateTimeImmutable
    {
        $timestamp = $item->get();

        if (
            !$item->isHit()
            || !is_int($timestamp)
        ) {
            return null;
        }

        return new DateTimeImmutable('@' . $timestamp);
    }

    private function save(
        CacheItemInterface $item,
        int $timestamp,
    ): void {
        $item->set($timestamp);
        $item->expiresAfter(self::EXPIRY);

        $this->cache->save($item);
    }

    /**
     * A command line is not a valid cache key: `database:mailinglist:fetch all` contains both a reserved character
     * and a space. The hash distinguishes two commands that sanitise to the same string.
     */
    private function key(string $command): string
    {
        $readable = preg_replace(
            '/[^A-Za-z0-9_.]/',
            '-',
            $command,
        );

        // `preg_replace` returns null only on a malformed pattern or an exhausted backtrack limit, neither of which
        // this pattern reaches. The hash identifies the command on its own if it ever did.
        return self::PREFIX
            . ($readable ?? '')
            . '.'
            . substr(
                hash(
                    'sha256',
                    $command,
                ),
                0,
                8,
            );
    }
}
