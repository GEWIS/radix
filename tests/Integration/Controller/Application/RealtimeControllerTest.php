<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Application;

use App\Controller\Application\RealtimeController;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function array_filter;
use function array_values;
use function base64_decode;
use function explode;
use function is_array;
use function json_decode;
use function str_contains;
use function strtr;

use const JSON_THROW_ON_ERROR;

#[CoversClass(RealtimeController::class)]
final class RealtimeControllerTest extends KernelTestCase
{
    public function testAFreshCookieIsHandedBackWithoutAnythingElse(): void
    {
        $response = self::bootKernel()->handle(Request::create('/en/realtime/grant'));

        self::assertSame(
            Response::HTTP_NO_CONTENT,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'no-store',
            $response->headers->get('Cache-Control') ?? '',
        );
    }

    public function testAPasserByIsGrantedTheBroadcastTopicOnly(): void
    {
        $response = self::bootKernel()->handle(Request::create('/en/realtime/grant'));

        $cookies = array_values(array_filter(
            $response->headers->getCookies(),
            static fn (Cookie $cookie): bool => str_contains(
                $cookie->getName(),
                'mercure',
            ),
        ));
        self::assertCount(
            1,
            $cookies,
        );

        self::assertSame(
            ['gewis/public'],
            $this->subscribeClaim($cookies[0]->getValue() ?? ''),
        );
    }

    public function testTheCompanyFirewallHasAnAddressOfItsOwn(): void
    {
        $response = self::bootKernel()->handle(Request::create('/en/company/realtime/grant'));

        self::assertSame(
            Response::HTTP_NO_CONTENT,
            $response->getStatusCode(),
        );
    }

    /**
     * @return string[]
     */
    private function subscribeClaim(string $token): array
    {
        $parts = explode(
            '.',
            $token,
        );
        self::assertCount(
            3,
            $parts,
        );

        $payload = base64_decode(
            strtr(
                $parts[1],
                '-_',
                '+/',
            ),
            true,
        );
        self::assertIsString($payload);

        /** @var array{mercure?: array{subscribe?: mixed}} $claims */
        $claims = json_decode(
            $payload,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        /** @var string[]|null $subscribe */
        $subscribe = $claims['mercure']['subscribe'] ?? null;
        self::assertTrue(is_array($subscribe));

        return $subscribe;
    }
}
