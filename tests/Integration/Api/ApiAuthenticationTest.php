<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Database\User\ApiPrincipal;
use App\Entity\User\Enums\ApiPermissions;
use App\Repository\User\ApiPrincipalRepository;
use App\Security\Api\ApiTokenAuthenticator;
use DateTime;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Response;

use function hash;
use function substr;

#[CoversClass(ApiPrincipal::class)]
#[CoversClass(ApiPrincipalRepository::class)]
#[CoversClass(ApiTokenAuthenticator::class)]
final class ApiAuthenticationTest extends ApiTestCase
{
    public function testTheTokenItselfIsNotStored(): void
    {
        $token = $this->principalWith([ApiPermissions::HealthR]);
        $principal = $this->principalFor($token);

        self::assertSame(
            hash(
                'sha256',
                $token,
            ),
            $principal->getTokenHash(),
            'the column holds a hash, so a read of the table is not a read of the credential',
        );
        self::assertStringNotContainsString(
            $token,
            $principal->getToken(),
            'the mask must not leak the token it stands for',
        );
    }

    public function testTheMaskKeepsOnlyTheLastCharacters(): void
    {
        $token = $this->principalWith([ApiPermissions::HealthR]);

        $hint = substr(
            $token,
            -5,
        );
        self::assertNotSame(
            '',
            $hint,
        );
        self::assertStringEndsWith(
            $hint,
            $this->principalFor($token)->getToken(),
        );
    }

    public function testUsingATokenRecordsTheDay(): void
    {
        $token = $this->principalWith([ApiPermissions::HealthR]);

        $this->get(
            '/api/health',
            $token,
        );

        self::assertSame(
            new DateTime('today')->format('Y-m-d'),
            $this->principalFor($token)->getLastUsedAt()?->format('Y-m-d'),
        );
    }

    public function testAFreshTokenHasNeverBeenUsed(): void
    {
        self::assertNull(
            $this->principalFor($this->principalWith([ApiPermissions::HealthR]))->getLastUsedAt(),
        );
    }

    public function testAnExpiredTokenIsRefusedLikeAnUnknownOne(): void
    {
        $token = $this->principalWith([ApiPermissions::HealthR]);
        $principal = $this->principalFor($token);
        $principal->setExpiresAt(new DateTime('yesterday'));
        $this->saveLedger();

        self::assertSame(
            Response::HTTP_UNAUTHORIZED,
            $this->get(
                '/api/health',
                $token,
            )->getStatusCode(),
        );
    }

    public function testATokenExpiringTodayStillWorks(): void
    {
        $token = $this->principalWith([ApiPermissions::HealthR]);
        $principal = $this->principalFor($token);
        $principal->setExpiresAt(new DateTime('today'));
        $this->saveLedger();

        self::assertSame(
            Response::HTTP_OK,
            $this->get(
                '/api/health',
                $token,
            )->getStatusCode(),
        );
    }

    public function testARevokedTokenIsRefusedLikeAnUnknownOne(): void
    {
        $token = $this->principalWith([ApiPermissions::HealthR]);
        $principal = $this->principalFor($token);
        $principal->revoke();
        $this->saveLedger();

        self::assertSame(
            Response::HTTP_UNAUTHORIZED,
            $this->get(
                '/api/health',
                $token,
            )->getStatusCode(),
        );
    }
}
