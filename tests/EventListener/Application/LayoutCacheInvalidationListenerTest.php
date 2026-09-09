<?php

declare(strict_types=1);

namespace App\Tests\EventListener\Application;

use App\Entity\Application\Announcement;
use App\Entity\Application\MaintenanceWindow;
use App\Entity\Career\Company;
use App\Entity\Career\CompanyBannerPackage;
use App\Entity\Career\CompanyFeaturedPackage;
use App\Entity\Career\Vacancy;
use App\Entity\Career\VacancyRevision;
use App\Entity\Frontpage\NewsItem;
use App\EventListener\Application\LayoutCacheInvalidationListener;
use App\Repository\Application\AnnouncementRepository;
use App\Repository\Application\MaintenanceWindowRepository;
use App\Repository\Career\CompanyFeaturedPackageRepository;
use App\Twig\Extensions\CareerExtension;
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
        $listener = new LayoutCacheInvalidationListener(self::createStub(CacheItemPoolInterface::class));

        $listener->postPersist(new PostPersistEventArgs($entity, $this->manager($expectedKey)));
        $listener->postUpdate(new PostUpdateEventArgs($entity, $this->manager($expectedKey)));
        $listener->postRemove(new PostRemoveEventArgs($entity, $this->manager($expectedKey)));
    }

    /**
     * @return iterable<string, array{object}>
     */
    public static function entitiesBehindTheCareerBadges(): iterable
    {
        yield 'company' => [new Company()];

        yield 'vacancy' => [new Vacancy()];

        yield 'vacancy revision' => [new VacancyRevision()];

        // Any package, not only the featured one: which packages are running decides both counts.
        yield 'banner package' => [new CompanyBannerPackage()];

        yield 'featured package' => [new CompanyFeaturedPackage()];
    }

    /** The career badges are counted rather than filtered, so whatever decides the count has to drop them. */
    #[DataProvider('entitiesBehindTheCareerBadges')]
    public function testStoringOneOfTheseDropsTheCareerBadges(object $entity): void
    {
        $applicationCache = $this->createMock(CacheItemPoolInterface::class);
        $applicationCache->expects(self::once())
            ->method('deleteItem')
            ->with(CareerExtension::MENU_COUNTS_CACHE_KEY)
            ->willReturn(true);

        new LayoutCacheInvalidationListener($applicationCache)->postPersist(
            new PostPersistEventArgs(
                $entity,
                $this->managerWith(self::createStub(CacheItemPoolInterface::class)),
            ),
        );
    }

    /** An unrelated entity dropping these entries would turn the cache back into a query per page. */
    public function testStoringSomethingElseDropsNothing(): void
    {
        $resultCache = $this->createMock(CacheItemPoolInterface::class);
        $resultCache->expects(self::never())->method('deleteItem');

        $applicationCache = $this->createMock(CacheItemPoolInterface::class);
        $applicationCache->expects(self::never())->method('deleteItem');

        new LayoutCacheInvalidationListener($applicationCache)->postPersist(
            new PostPersistEventArgs(
                new NewsItem(),
                $this->managerWith($resultCache),
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
