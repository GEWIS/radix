<?php

declare(strict_types=1);

namespace App\Service\Report;

use App\Entity\Database\Enums\BoardFunctions;
use App\Entity\Database\Enums\ConfigNamespaces;
use App\Entity\Database\Enums\InstallationFunctions;
use App\Exception\Report\VersionExpected as VersionExpectedException;
use App\Exception\Report\VersionFormat as VersionFormatException;
use App\Exception\Report\VersionIncompatible as VersionIncompatibleException;
use App\Service\Application\Config as ConfigService;
use DateTime;
use PHLAK\SemVer\Enums\Compare as SemanticCompare;
use PHLAK\SemVer\Exceptions\InvalidVersionException;
use PHLAK\SemVer\Version as SemanticVersion;
use Symfony\Contracts\Translation\TranslatorInterface;

use function is_bool;
use function is_string;
use function max;
use function preg_replace;

class ApiService
{
    /**
     * The release that introduced the function lists; consumers on an older contract are turned away.
     */
    private const string FUNCTIONS_MINIMUM_VERSION = 'v4.3.3';

    public function __construct(
        private readonly ConfigService $configService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array<non-empty-string, array{
     *  isAdministrative: bool,
     *  isLegacy: bool,
     *  translations: non-empty-array<array-key, string>
     * }>
     */
    public function getOrganFunctions(?string $acceptHeader): array
    {
        $this->assertVersion(
            new SemanticVersion(self::FUNCTIONS_MINIMUM_VERSION),
            null,
            $acceptHeader,
        );

        return InstallationFunctions::getMultilangArray($this->translator);
    }

    /**
     * @return array<non-empty-string, array{
     *  isLegacy: bool,
     *  translations: non-empty-array<array-key, string>
     * }>
     */
    public function getBoardFunctions(?string $acceptHeader): array
    {
        $this->assertVersion(
            new SemanticVersion(self::FUNCTIONS_MINIMUM_VERSION),
            null,
            $acceptHeader,
        );

        return BoardFunctions::getMultilangArray($this->translator);
    }

    /**
     * @return array{
     *     syncPaused: bool,
     *     syncPausedUntil: ?DateTime,
     * }
     */
    public function getFrontpageData(): array
    {
        return [
            'syncPaused' => $this->isSyncPaused(),
            'syncPausedUntil' => $this->getSyncPausedUntil(),
        ];
    }

    /**
     * Flag to other applications using the register's API that they should wait with syncing
     */
    public function pauseSync(int $minutes): void
    {
        $syncPausedUntil = max(
            $this->getSyncPausedUntil(),
            new DateTime()->modify('+' . $minutes . ' minutes'),
        );

        $this->configService->setConfig(
            ConfigNamespaces::DatabaseApi,
            'sync_paused',
            $syncPausedUntil,
        );
    }

    public function resumeSyncNow(): void
    {
        $this->configService->unsetConfig(
            ConfigNamespaces::DatabaseApi,
            'sync_paused',
        );
    }

    public function isSyncPaused(): bool
    {
        return $this->getSyncPausedUntil() > new DateTime();
    }

    private function getSyncPausedUntil(): ?DateTime
    {
        $pausedUntil = $this->configService->getConfig(
            ConfigNamespaces::DatabaseApi,
            'sync_paused',
        );

        if (is_string($pausedUntil)) {
            return null;
        }

        if (is_bool($pausedUntil)) {
            return null;
        }

        return $pausedUntil;
    }

    /**
     * Function that asserts that the given api version is between two bounds.
     *
     * The version is negotiated through the `Accept` header, which is handed in verbatim.
     *
     * @throws VersionExpectedException if not allowed.
     */
    public function assertVersion(
        SemanticVersion $lower,
        ?SemanticVersion $upper,
        ?string $acceptHeader,
    ): void {
        if (null === $acceptHeader) {
            throw new VersionExpectedException();
        }

        $count = 0;
        $value = preg_replace(
            pattern: '/application\\/vnd\\.gewis\\.gewisdb\\+json;version=(.*)/i',
            replacement: 'v${1}',
            subject: $acceptHeader,
            count: $count,
        );

        try {
            $given = new SemanticVersion($value);
        } catch (InvalidVersionException) {
            throw new VersionFormatException($value);
        }

        if (1 !== $count) {
            throw new VersionExpectedException();
        }

        if (
            $given->lt(
                $lower,
                SemanticCompare::PATCH,
            )
        ) {
            throw new VersionIncompatibleException(
                $lower,
                $upper,
                $given,
            );
        }

        if (
            null !== $upper
            && $given->gt(
                $upper,
                SemanticCompare::PATCH,
            )
        ) {
            throw new VersionIncompatibleException(
                $lower,
                $upper,
                $given,
            );
        }
    }
}
