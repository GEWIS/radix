<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\Activity;

use App\Entity\Activity\Activity;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The cron queues the cards whose stale moment has passed, once: the moment is cleared as they are queued, and the
 * handler sets the next one when it redraws.
 */
final class RedrawStaleShareImagesCommandTest extends DatabaseTestCase
{
    public function testQueuesTheDueCardsOnce(): void
    {
        $activity = $this->entityManager->getRepository(Activity::class)->findOneBy(['unpublishedAt' => null]);
        self::assertInstanceOf(
            Activity::class,
            $activity,
        );
        self::assertNotNull($activity->getLiveRevision());

        $activity->shareImageStaleAt = new DateTimeImmutable('-1 minute');
        $this->entityManager->flush();

        $this->assertCommandIsSuccessful(static::runCommand('app:activity:redraw-stale-share-images'));
        self::assertCount(
            1,
            $this->transport()->getSent(),
        );
        self::assertNull($activity->shareImageStaleAt);

        $this->transport()->reset();
        $this->assertCommandIsSuccessful(static::runCommand('app:activity:redraw-stale-share-images'));
        self::assertCount(
            0,
            $this->transport()->getSent(),
        );
    }

    public function testACardThatIsNotDueYetIsLeftAlone(): void
    {
        $activity = $this->entityManager->getRepository(Activity::class)->findOneBy(['unpublishedAt' => null]);
        self::assertInstanceOf(
            Activity::class,
            $activity,
        );

        $activity->shareImageStaleAt = new DateTimeImmutable('+1 hour');
        $this->entityManager->flush();

        $this->assertCommandIsSuccessful(static::runCommand('app:activity:redraw-stale-share-images'));
        self::assertCount(
            0,
            $this->transport()->getSent(),
        );
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.images');
        self::assertInstanceOf(
            InMemoryTransport::class,
            $transport,
        );

        return $transport;
    }
}
