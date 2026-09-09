<?php

declare(strict_types=1);

namespace App\Service\Application;

use App\Entity\Database\ConfigItem;
use App\Entity\Database\Enums\ConfigNamespaces;
use App\Repository\Application\ConfigItemRepository;
use DateTime;
use Override;
use Symfony\Contracts\Service\ResetInterface;

use function array_key_exists;

/**
 * Settings are read several times over a request, so the values are memoised for its length.
 *
 * Deliberately not shared between requests: one of these settings is the lock the mailing list synchronisation
 * acquires, which has to be visible to the next request as soon as another process sets it. The memo is cleared
 * through {@see ResetInterface}, which is required under FrankenPHP's worker mode.
 */
class Config implements ResetInterface
{
    /** @var array<string, bool|string|DateTime|null> */
    private array $values = [];

    public function __construct(private readonly ConfigItemRepository $configItemRepository)
    {
    }

    #[Override]
    public function reset(): void
    {
        $this->values = [];
    }

    /**
     * @template T of bool|string|DateTime|null
     *
     * @psalm-param T $default
     *
     * @psalm-return (T is null ? bool|string|DateTime|null : T)
     */
    public function getConfig(
        ConfigNamespaces $namespace,
        string $key,
        bool|string|DateTime|null $default = null,
    ): bool|string|DateTime|null {
        $memoKey = $namespace->value . '.' . $key;

        if (
            !array_key_exists(
                $memoKey,
                $this->values,
            )
        ) {
            $this->values[$memoKey] = $this->configItemRepository->findByKey(
                $namespace,
                $key,
            )?->getValue();
        }

        return $this->values[$memoKey] ?? $default;
    }

    public function setConfig(
        ConfigNamespaces $namespace,
        string $key,
        bool|string|DateTime $value,
    ): void {
        $configItem = $this->configItemRepository->findByKey(
            $namespace,
            $key,
        );

        if (null === $configItem) {
            $configItem = new ConfigItem();
            $configItem->setKey(
                $namespace,
                $key,
            );
        }

        $configItem->setValue($value);
        $this->configItemRepository->persist($configItem);

        unset($this->values[$namespace->value . '.' . $key]);
    }

    public function unsetConfig(
        ConfigNamespaces $namespace,
        string $key,
    ): void {
        $configItem = $this->configItemRepository->findByKey(
            $namespace,
            $key,
        );

        unset($this->values[$namespace->value . '.' . $key]);

        if (null === $configItem) {
            return;
        }

        $this->configItemRepository->remove($configItem);
    }
}
