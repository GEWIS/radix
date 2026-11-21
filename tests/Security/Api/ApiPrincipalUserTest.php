<?php

declare(strict_types=1);

namespace App\Tests\Security\Api;

use App\Entity\Database\User\ApiPrincipal;
use App\Security\Api\ApiPrincipalUser;
use App\Security\Api\ApiToken;
use PHPUnit\Framework\TestCase;

/**
 * The entity is left free of security interfaces: its natural identifier is the bearer token itself, which must never
 * end up in logs or exception messages as a user identifier.
 */
final class ApiPrincipalUserTest extends TestCase
{
    public function testTheUserIdentifierIsOpaque(): void
    {
        $user = new ApiPrincipalUser($this->principal(5));

        self::assertSame(
            'api-principal-5',
            $user->getUserIdentifier(),
        );
        self::assertSame(
            [ApiPrincipalUser::ROLE],
            $user->getRoles(),
        );
    }

    public function testThePrincipalIsReachableFromTheAdapter(): void
    {
        $principal = $this->principal(5);

        self::assertSame(
            $principal,
            new ApiPrincipalUser($principal)->getApiPrincipal(),
        );
    }

    public function testThereAreNoCredentialsToErase(): void
    {
        $this->expectNotToPerformAssertions();

        new ApiPrincipalUser($this->principal(5))->eraseCredentials();
    }

    public function testTheTokenCarriesThePrincipalAndItsRoles(): void
    {
        $principal = $this->principal(5);
        $token = new ApiToken(
            new ApiPrincipalUser($principal),
            'api',
        );

        self::assertSame(
            $principal,
            $token->getApiPrincipal(),
        );
        self::assertSame(
            [ApiPrincipalUser::ROLE],
            $token->getRoleNames(),
        );
        self::assertSame(
            'api-principal-5',
            $token->getUserIdentifier(),
        );
    }

    private function principal(int $id): ApiPrincipal
    {
        $principal = new class extends ApiPrincipal {
            public function withId(int $id): self
            {
                $this->id = $id;

                return $this;
            }
        };

        return $principal->withId($id);
    }
}
