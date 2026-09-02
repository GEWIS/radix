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
use App\State\Api\ApiVersion;
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
     * The release that introduced the function lists; consumers on an older contract are turned away. Public because
     * the document states the same bound, and the two saying different things is the bug.
     */
    public const ApiVersion FUNCTIONS_MINIMUM_VERSION = ApiVersion::V4_3_3;

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
            self::FUNCTIONS_MINIMUM_VERSION,
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
            self::FUNCTIONS_MINIMUM_VERSION,
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
    public function getStatusFigures(): array
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
        ApiVersion $lower,
        ?ApiVersion $upper,
        ?string $acceptHeader,
    ): void {
        $lowerBound = new SemanticVersion($lower->value);
        $upperBound = null === $upper
            ? null
            : new SemanticVersion($upper->value);

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
                $lowerBound,
                SemanticCompare::PATCH,
            )
        ) {
            throw new VersionIncompatibleException(
                $lowerBound,
                $upperBound,
                $given,
            );
        }

        if (
            null !== $upperBound
            && $given->gt(
                $upperBound,
                SemanticCompare::PATCH,
            )
        ) {
            throw new VersionIncompatibleException(
                $lowerBound,
                $upperBound,
                $given,
            );
        }
    }
}
