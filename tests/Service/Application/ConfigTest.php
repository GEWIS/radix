<?php

declare(strict_types=1);

namespace App\Tests\Service\Application;

use App\Entity\Database\ConfigItem;
use App\Entity\Database\Enums\ConfigNamespaces;
use App\Repository\Application\ConfigItemRepository;
use App\Service\Application\Config;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Settings are read several times per request, so a read is memoised, but a write deliberately invalidates the memo:
 * one of these settings is the mailing-list lock another process sets, which has to be visible on the next read.
 */
final class ConfigTest extends TestCase
{
    public function testAValueIsReadFromTheRepositoryOnceAndThenMemoised(): void
    {
        $repository = $this->createMock(ConfigItemRepository::class);
        $repository->expects(self::once())
            ->method('findByKey')
            ->with(
                ConfigNamespaces::DatabaseApi,
                'sync_paused',
            )
            ->willReturn($this->item('stored'));

        $config = new Config($repository);

        self::assertSame(
            'stored',
            $config->getConfig(
                ConfigNamespaces::DatabaseApi,
                'sync_paused',
            ),
        );
        self::assertSame(
            'stored',
            $config->getConfig(
                ConfigNamespaces::DatabaseApi,
                'sync_paused',
            ),
        );
    }

    public function testAMissingValueFallsBackToTheGivenDefault(): void
    {
        $repository = self::createStub(ConfigItemRepository::class);
        $repository->method('findByKey')->willReturn(null);

        $config = new Config($repository);

        self::assertSame(
            'fallback',
            $config->getConfig(
                ConfigNamespaces::DatabaseApi,
                'absent',
                'fallback',
            ),
        );
    }

    /**
     * An item with no value at all is treated as absent rather than as a null that was stored.
     */
    public function testAnItemWithoutAValueFallsThroughToTheDefault(): void
    {
        $repository = self::createStub(ConfigItemRepository::class);
        $repository->method('findByKey')->willReturn(new ConfigItem());

        $config = new Config($repository);

        self::assertSame(
            'fallback',
            $config->getConfig(
                ConfigNamespaces::DatabaseApi,
                'empty',
                'fallback',
            ),
        );
    }

    public function testResetForgetsWhatWasMemoised(): void
    {
        $repository = $this->createMock(ConfigItemRepository::class);
        $repository->expects(self::exactly(2))
            ->method('findByKey')
            ->willReturnOnConsecutiveCalls(
                $this->item('first'),
                $this->item('second'),
            );

        $config = new Config($repository);

        self::assertSame(
            'first',
            $config->getConfig(
                ConfigNamespaces::DatabaseApi,
                'sync_paused',
            ),
        );
        $config->reset();
        self::assertSame(
            'second',
            $config->getConfig(
                ConfigNamespaces::DatabaseApi,
                'sync_paused',
            ),
        );
    }

    public function testSettingANewValueCreatesTheRow(): void
    {
        $repository = $this->createMock(ConfigItemRepository::class);
        $repository->method('findByKey')->willReturn(null);
        $repository->expects(self::once())
            ->method('persist')
            ->with(self::callback(static function (ConfigItem $item): bool {
                return 'value' === $item->getValue();
            }));

        $config = new Config($repository);
        $config->setConfig(
            ConfigNamespaces::DatabaseApi,
            'sync_paused',
            'value',
        );
    }

    public function testSettingAnExistingValueUpdatesItAndDropsTheMemo(): void
    {
        $existing = $this->item('old');
        $repository = $this->createMock(ConfigItemRepository::class);
        $repository->expects(self::exactly(3))
            ->method('findByKey')
            ->willReturnOnConsecutiveCalls(
                $existing,
                $existing,
                $this->item('new'),
            );
        $repository->expects(self::once())->method('persist');

        $config = new Config($repository);

        self::assertSame(
            'old',
            $config->getConfig(
                ConfigNamespaces::DatabaseApi,
                'sync_paused',
            ),
        );
        $config->setConfig(
            ConfigNamespaces::DatabaseApi,
            'sync_paused',
            'new',
        );
        self::assertSame(
            'new',
            $config->getConfig(
                ConfigNamespaces::DatabaseApi,
                'sync_paused',
            ),
        );
    }

    public function testUnsettingRemovesTheRow(): void
    {
        $repository = $this->createMock(ConfigItemRepository::class);
        $repository->method('findByKey')->willReturn($this->item('value'));
        $repository->expects(self::once())
            ->method('remove')
            ->with(self::isInstanceOf(ConfigItem::class));

        $config = new Config($repository);
        $config->unsetConfig(
            ConfigNamespaces::DatabaseApi,
            'sync_paused',
        );
    }

    /**
     * Removing a setting that was never there is not an error, and must not touch the repository.
     */
    public function testUnsettingSomethingAbsentDoesNothing(): void
    {
        $repository = $this->createMock(ConfigItemRepository::class);
        $repository->method('findByKey')->willReturn(null);
        $repository->expects(self::never())->method('remove');

        $config = new Config($repository);
        $config->unsetConfig(
            ConfigNamespaces::DatabaseApi,
            'absent',
        );
    }

    private function item(bool|string|DateTimeImmutable $value): ConfigItem
    {
        $item = new ConfigItem();
        $item->setValue($value);

        return $item;
    }
}
