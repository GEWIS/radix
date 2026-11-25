<?php

declare(strict_types=1);

namespace App\Tests\Service\Api;

use App\Entity\Database\Enums\BoardFunctions;
use App\Entity\Database\Enums\ConfigNamespaces;
use App\Entity\Database\Enums\InstallationFunctions;
use App\Exception\Api\VersionExpected;
use App\Exception\Api\VersionFormat;
use App\Exception\Api\VersionIncompatible;
use App\Exception\User\ApiException;
use App\Service\Api\ApiService;
use App\Service\Application\Config;
use DateTimeImmutable;
use PHLAK\SemVer\Version as SemanticVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * Version negotiation is parsed from the `Accept` header and decides whether a request is served, so each way of
 * getting it wrong must fail closed; the function lists return the shape their consumers expect.
 */
final class ApiServiceTest extends TestCase
{
    public function testAFunctionListIsServedFromTheMinimumVersion(): void
    {
        $functions = $this->service()->getOrganFunctions($this->accept('4.3.3'));

        self::assertSame(
            InstallationFunctions::getMultilangArray($this->translator()),
            $functions,
        );
        self::assertNotEmpty($functions);
    }

    public function testTheBoardFunctionsAreServedFromTheMinimumVersionToo(): void
    {
        $functions = $this->service()->getBoardFunctions($this->accept('5.0.0'));

        self::assertSame(
            BoardFunctions::getMultilangArray($this->translator()),
            $functions,
        );
        self::assertNotEmpty($functions);
    }

    public function testARequestWithoutAVersionIsRefused(): void
    {
        $this->expectException(VersionExpected::class);
        $this->service()->getOrganFunctions(null);
    }

    /**
     * The pattern does not occur in the header at all, so nothing was negotiated even though what is there looks like
     * a version.
     */
    public function testAHeaderThatCarriesNoVersionIsRefused(): void
    {
        $this->expectException(VersionExpected::class);
        $this->service()->getOrganFunctions('4.3.3');
    }

    /**
     * A header of another media type names a "version" that is not one, which is reported as a format problem.
     */
    public function testAHeaderOfAnotherMediaTypeIsRefusedAsMalformed(): void
    {
        $this->expectException(VersionFormat::class);
        $this->service()->getOrganFunctions('application/json');
    }

    /**
     * The pattern matches, but what it captured is not a version. Retrying with the captured value named in the error
     * message helps the consumer more than "no version given" would.
     */
    public function testAVersionThatIsNotSemanticIsRefusedAsSuch(): void
    {
        $this->expectException(VersionFormat::class);
        $this->service()->getOrganFunctions($this->accept('not.a.version'));
    }

    public function testAnOlderConsumerIsRefused(): void
    {
        $this->expectException(VersionIncompatible::class);
        $this->service()->getOrganFunctions($this->accept('4.3.2'));
    }

    public function testAConsumerAboveTheUpperBoundIsRefused(): void
    {
        $service = $this->service();

        $this->expectException(VersionIncompatible::class);
        $service->assertVersion(
            new SemanticVersion('4.0.0'),
            new SemanticVersion('4.5.0'),
            $this->accept('4.5.1'),
        );
    }

    public function testAVersionInsideTheBoundsIsAccepted(): void
    {
        $service = $this->service();

        $service->assertVersion(
            new SemanticVersion('4.0.0'),
            new SemanticVersion('4.5.0'),
            $this->accept('4.5.0'),
        );

        self::addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function headerProvider(): iterable
    {
        yield 'full media type' => [
            'application/vnd.gewis.gewisdb+json;version=4.3.3',
            true,
        ];

        yield 'upper-case parameters' => [
            'APPLICATION/VND.GEWIS.GEWISDB+JSON;VERSION=4.3.3',
            true,
        ];

        // The trailing parameter becomes part of the captured version, which is then not a version at all.
        yield 'trailing parameters' => [
            'application/vnd.gewis.gewisdb+json;version=4.3.3;charset=utf-8',
            false,
        ];
    }

    #[DataProvider('headerProvider')]
    public function testTheHeaderShapeThatIsAccepted(
        string $header,
        bool $accepted,
    ): void {
        $service = $this->service();
        $thrown = null;

        try {
            $service->assertVersion(
                new SemanticVersion('4.3.3'),
                null,
                $header,
            );
        } catch (Throwable $e) {
            self::assertInstanceOf(
                ApiException::class,
                $e,
            );
            $thrown = $e;
        }

        self::assertSame(
            !$accepted,
            null !== $thrown,
        );
    }

    public function testPausingSyncHoldsTheLongestOfTheTwoDurations(): void
    {
        $config = $this->config(null);
        $service = new ApiService(
            $config,
            $this->translator(),
        );

        $service->pauseSync(15);

        $until = $config->getConfig(
            ConfigNamespaces::DatabaseApi,
            'sync_paused',
        );
        self::assertInstanceOf(
            DateTimeImmutable::class,
            $until,
        );
        self::assertTrue($service->isSyncPaused());
        self::assertSame(
            $until,
            $service->getStatusFigures()['syncPausedUntil'],
        );
        self::assertTrue($service->getStatusFigures()['syncPaused']);
    }

    /**
     * Pausing for a shorter time than a pause that is already in place may not shorten it.
     */
    public function testAShorterPauseDoesNotShortenAnExistingOne(): void
    {
        $existing = new DateTimeImmutable('+2 hours');
        $config = $this->config($existing);
        $service = new ApiService(
            $config,
            $this->translator(),
        );

        $service->pauseSync(1);

        self::assertSame(
            $existing,
            $config->getConfig(
                ConfigNamespaces::DatabaseApi,
                'sync_paused',
            ),
        );
    }

    public function testResumingClearsThePausedValue(): void
    {
        $config = $this->config(new DateTimeImmutable('+1 hour'));
        $service = new ApiService(
            $config,
            $this->translator(),
        );

        $service->resumeSyncNow();

        self::assertFalse($service->isSyncPaused());
        self::assertNull($service->getStatusFigures()['syncPausedUntil']);
    }

    /**
     * A value that is not a date is not a pause; guessing at it would keep external applications away for no reason.
     */
    public function testAStoredValueThatIsNotADateMeansNothingIsPaused(): void
    {
        $config = $this->config('yes please');
        $service = new ApiService(
            $config,
            $this->translator(),
        );

        self::assertFalse($service->isSyncPaused());
        self::assertNull($service->getStatusFigures()['syncPausedUntil']);
    }

    private function service(): ApiService
    {
        return new ApiService(
            $this->config(null),
            $this->translator(),
        );
    }

    private function config(bool|string|DateTimeImmutable|null $stored): Config
    {
        $values = [ConfigNamespaces::DatabaseApi->value . '.sync_paused' => $stored];

        $config = self::createStub(Config::class);
        $config->method('getConfig')->willReturnCallback(
            static function (ConfigNamespaces $namespace, string $key, mixed $default = null) use (&$values) {
                return $values[$namespace->value . '.' . $key] ?? $default;
            },
        );
        $config->method('setConfig')->willReturnCallback(
            static function (ConfigNamespaces $namespace, string $key, mixed $value) use (&$values): void {
                $values[$namespace->value . '.' . $key] = $value;
            },
        );
        $config->method('unsetConfig')->willReturnCallback(
            static function (ConfigNamespaces $namespace, string $key) use (&$values): void {
                unset($values[$namespace->value . '.' . $key]);
            },
        );

        return $config;
    }

    private function translator(): TranslatorInterface
    {
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return $translator;
    }

    private function accept(string $version): string
    {
        return 'application/vnd.gewis.gewisdb+json;version=' . $version;
    }
}
