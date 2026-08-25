<?php

declare(strict_types=1);

namespace App\Scheduler;

use Override;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * The one schedule. Jobs are collected onto it from `#[AsCronTask]` by the compiler pass; nothing is listed here.
 *
 * It only dispatches. Each task names a `transports:`, so a due time puts a message on `cron`, `gdpr` or
 * `maintenance` and the workers there run the command. That is what makes a single schedule safe: a schedule is
 * generated rather than queued, the lock below limits it to one consumer, and running `app:decision:generate` here
 * would stop the minute-cadence jobs for the hour it takes.
 *
 * Dispatched at most once per due time whatever the container does in between:
 *  - stateful(), because an in-memory checkpoint starts at "now" on every boot, so a restart cannot distinguish a
 *    due time it has handled from one it has not.
 *  - processOnlyLastMissedRun(), because every job recomputes what the previous run would have done, so an outage is
 *    worth catching up on once rather than replaying.
 *  - lock(), because a rolling deploy has both containers consuming briefly.
 *
 * Scaling this worker adds failover but no throughput: a second replica cannot take the lock, so it generates
 * nothing.
 */
#[AsSchedule('default')]
final readonly class MainSchedule implements ScheduleProviderInterface
{
    public function __construct(
        #[Autowire(service: 'cache.app')]
        private CacheInterface $cache,
        private LockFactory $lockFactory,
    ) {
    }

    #[Override]
    public function getSchedule(): Schedule
    {
        return new Schedule()
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true)
            ->lock($this->lockFactory->createLock('scheduler-default'));
    }
}
