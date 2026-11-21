<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\User;

use App\Entity\User\Enums\SecurityEventType;
use App\Entity\User\SecurityLog;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;

use function intval;

/**
 * The table serves questions a member or the board may ask months later, so it is kept for half a year. Older rows
 * are personal data nobody can be asked about, and the sweep removes them.
 */
final class PruneSecurityLogCommandTest extends DatabaseTestCase
{
    public function testEventsOlderThanTheRetentionAreForgotten(): void
    {
        $stale = $this->log('-200 days');
        $recent = $this->log('-10 days');

        $result = static::runCommand('app:user:prune-security-log');
        $this->assertCommandIsSuccessful($result);

        $this->entityManager->clear();
        self::assertNull(
            $this->entityManager->getRepository(SecurityLog::class)->find($stale->id),
            'Half a year is as long as an event stays answerable for.',
        );
        self::assertNotNull(
            $this->entityManager->getRepository(SecurityLog::class)->find($recent->id),
        );
        self::assertMatchesRegularExpression(
            '/Forgot \d+ security event/',
            $result->getDisplay(),
        );
    }

    /**
     * A run with nothing to remove leaves the boundary rows untouched and reports that in the singular.
     */
    public function testAnEventJustInsideTheRetentionSurvives(): void
    {
        $inside = $this->log('-179 days');

        $this->assertCommandIsSuccessful(static::runCommand('app:user:prune-security-log'));

        $this->entityManager->clear();
        self::assertNotNull(
            $this->entityManager->getRepository(SecurityLog::class)->find($inside->id),
        );
    }

    private function log(string $occurredAt): SecurityLog
    {
        $log = new SecurityLog();
        $log->occurredAt = new DateTimeImmutable($occurredAt);
        $log->event = SecurityEventType::SignInSucceeded;
        $log->userIdentifier = '8000';
        $log->firewallName = 'main';
        $log->detail = ['prune' => intval($log->occurredAt->format('Ymd'))];

        $this->entityManager->persist($log);
        $this->entityManager->flush();

        return $log;
    }
}
