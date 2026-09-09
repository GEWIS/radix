<?php

declare(strict_types=1);

namespace App\Tests\EventListener\Application;

use App\Entity\Application\Announcement;
use App\Entity\Application\MaintenanceWindow;
use App\Entity\Career\CompanyFeaturedPackage;
use App\Entity\Frontpage\NewsItem;
use App\EventListener\Application\LayoutCacheInvalidationListener;
use App\Repository\Application\AnnouncementRepository;
use App\Repository\Application\MaintenanceWindowRepository;
use App\Repository\Career\CompanyFeaturedPackageRepository;
use DateTimeImmutable;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;

/**
 * What is asserted is the cache key. A wrong key leaves the banner on yesterday's answer until the day rolls over,
 * which fails silently.
 */
final class LayoutCacheInvalidationListenerTest extends TestCase
{
    /**
     * @return iterable<string, array{object, string}>
     */
    public static function cachedEntities(): iterable
    {
        $today = new DateTimeImmutable()->setTime(
            0,
            0,
        );

        yield 'maintenance window' => [
            new MaintenanceWindow(),
            MaintenanceWindowRepository::cacheKeyFor($today),
        ];

        yield 'announcement' => [
            new Announcement(),
            AnnouncementRepository::cacheKeyFor($today),
        ];

        yield 'featured package' => [
            new CompanyFeaturedPackage(),
            CompanyFeaturedPackageRepository::cacheKeyFor($today),
        ];
    }

    #[DataProvider('cachedEntities')]
    public function testStoringOneDropsTheDayItIsCachedUnder(
        object $entity,
        string $expectedKey,
    ): void {
        $listener = new LayoutCacheInvalidationListener();

        $listener->postPersist(new PostPersistEventArgs($entity, $this->manager($expectedKey)));
        $listener->postUpdate(new PostUpdateEventArgs($entity, $this->manager($expectedKey)));
        $listener->postRemove(new PostRemoveEventArgs($entity, $this->manager($expectedKey)));
    }

    /** An unrelated entity dropping these entries would turn the cache back into a query per page. */
    public function testStoringSomethingElseDropsNothing(): void
    {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->expects(self::never())->method('deleteItem');

        new LayoutCacheInvalidationListener()->postPersist(
            new PostPersistEventArgs(
                new NewsItem(),
                $this->managerWith($cache),
            ),
        );
    }

    private function manager(string $expectedKey): EntityManagerInterface
    {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->expects(self::once())
            ->method('deleteItem')
            ->with($expectedKey)
            ->willReturn(true);

        return $this->managerWith($cache);
    }

    private function managerWith(CacheItemPoolInterface $cache): EntityManagerInterface
    {
        $configuration = self::createStub(Configuration::class);
        $configuration->method('getResultCache')->willReturn($cache);

        $manager = self::createStub(EntityManagerInterface::class);
        $manager->method('getConfiguration')->willReturn($configuration);

        return $manager;
    }
}
