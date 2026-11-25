<?php

declare(strict_types=1);

namespace App\Tests\State\Api;

use ApiPlatform\Metadata\Get;
use ApiPlatform\State\ProviderInterface;
use App\EventListener\Api\VendorAcceptListener;
use App\Service\Api\ApiService;
use App\State\Api\ApiVersion;
use App\State\Api\MinimumVersionProvider;
use PHLAK\SemVer\Version;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\HttpFoundation\Request;

/**
 * An operation may state the oldest contract it still answers. The check runs before the read, so a consumer on an
 * older contract is refused rather than handed a document it will misread.
 */
final class MinimumVersionProviderTest extends TestCase
{
    public function testAnOperationWithAMinimumChecksTheNegotiatedVersionFirst(): void
    {
        $request = Request::create('/api/members');
        $request->attributes->set(
            VendorAcceptListener::NEGOTIATED_VERSION,
            '5.0.0',
        );

        $api = $this->apiService();
        $api->expects(self::once())->method('assertVersion')->with(
            self::callback(static fn (Version $version): bool => '5.0.0' === (string) $version),
            null,
            '5.0.0',
        );

        $provider = $this->provider($api);

        self::assertInstanceOf(
            stdClass::class,
            $provider->provide(
                $this->operation('v5.0.0'),
                [],
                ['request' => $request],
            ),
        );
    }

    public function testAnOperationWithoutAMinimumDoesNotCheckAnything(): void
    {
        $api = $this->apiService();
        $api->expects(self::never())->method('assertVersion');

        self::assertInstanceOf(
            stdClass::class,
            $this->provider($api)->provide(new Get()),
        );
    }

    /**
     * A minimum that is not a string is not a version to compare against.
     */
    public function testAMinimumThatIsNotAVersionIsIgnored(): void
    {
        $api = $this->apiService();
        $api->expects(self::never())->method('assertVersion');

        $operation = new Get()->withExtraProperties([ApiVersion::MINIMUM => ['v5.0.0']]);

        self::assertInstanceOf(
            stdClass::class,
            $this->provider($api)->provide($operation),
        );
    }

    public function testWithoutARequestNothingWasNegotiated(): void
    {
        $api = $this->apiService();
        $api->expects(self::once())->method('assertVersion')->with(
            self::isInstanceOf(Version::class),
            null,
            null,
        );

        $this->provider($api)->provide($this->operation('v5.0.0'));
    }

    public function testARequestThatNegotiatedNothingPassesNullOn(): void
    {
        $api = $this->apiService();
        $api->expects(self::once())->method('assertVersion')->with(
            self::isInstanceOf(Version::class),
            null,
            null,
        );

        $this->provider($api)->provide(
            $this->operation('v5.0.0'),
            [],
            ['request' => Request::create('/api/members')],
        );
    }

    private function provider(ApiService $api): MinimumVersionProvider
    {
        $decorated = self::createStub(ProviderInterface::class);
        $decorated->method('provide')->willReturn(new stdClass());

        return new MinimumVersionProvider(
            $decorated,
            $api,
        );
    }

    private function apiService(): ApiService&MockObject
    {
        return $this->createMock(ApiService::class);
    }

    private function operation(string $minimum): Get
    {
        return new Get()->withExtraProperties([ApiVersion::MINIMUM => $minimum]);
    }
}
