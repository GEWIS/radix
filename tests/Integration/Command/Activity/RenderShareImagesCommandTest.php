<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\Activity;

use App\Entity\Activity\Activity;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

use function count;

/**
 * The backfill queues a card for every published activity that has none, and for every one of them when forced.
 */
final class RenderShareImagesCommandTest extends DatabaseTestCase
{
    public function testQueuesTheActivitiesWithoutACardAndAllOfThemWhenForced(): void
    {
        $published = $this->entityManager->getRepository(Activity::class)->createQueryBuilder('a')
            ->join(
                'a.liveRevision',
                'r',
            )
            ->andWhere('a.unpublishedAt IS NULL')
            ->getQuery()
            ->getResult();
        self::assertNotEmpty($published);

        $activity = $published[0];
        self::assertInstanceOf(
            Activity::class,
            $activity,
        );
        $activity->shareImagePaths = [
            'en' => 'activities/share/a.png',
            'nl' => 'activities/share/b.png',
        ];
        $this->entityManager->flush();

        $this->assertCommandIsSuccessful(static::runCommand('app:activity:render-share-images'));
        self::assertCount(
            count($published) - 1,
            $this->transport()->getSent(),
        );

        $this->transport()->reset();
        $this->assertCommandIsSuccessful(static::runCommand(
            'app:activity:render-share-images',
            ['--force' => true],
        ));
        self::assertCount(
            count($published),
            $this->transport()->getSent(),
        );

        $this->transport()->reset();
        $this->assertCommandIsSuccessful(static::runCommand(
            'app:activity:render-share-images',
            ['--dry-run' => true],
        ));
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
