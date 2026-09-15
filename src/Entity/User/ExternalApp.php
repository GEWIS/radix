<?php

declare(strict_types=1);

namespace App\Entity\User;

use App\Entity\Application\Traits\IdentifiableTrait;
use App\Entity\User\Enums\ExternalAppSignature;
use App\Entity\User\Enums\ExternalAppTokenDelivery;
use App\Entity\User\Enums\JWTClaims;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;

/**
 * ExternalApp model.
 */
#[Entity]
class ExternalApp
{
    use IdentifiableTrait;

    /**
     * Application ID.
     */
    #[Column(type: Types::STRING)]
    public string $appId;

    /**
     * Signing algorithm. Modern applications sign with one of the association's keys, published through the JWKS
     * endpoint, rather than a shared secret.
     */
    #[Column(
        type: Types::STRING,
        enumType: ExternalAppSignature::class,
        options: ['default' => ExternalAppSignature::EdDSA->value],
    )]
    public ExternalAppSignature $signature = ExternalAppSignature::EdDSA;

    /**
     * How the token is returned to the application.
     */
    #[Column(
        type: Types::STRING,
        enumType: ExternalAppTokenDelivery::class,
        options: ['default' => ExternalAppTokenDelivery::Fragment->value],
    )]
    public ExternalAppTokenDelivery $tokenDelivery = ExternalAppTokenDelivery::Fragment;

    /**
     * Shared secret, used only by applications signed with HS512.
     */
    #[Column(
        type: Types::STRING,
        nullable: true,
    )]
    public ?string $secret = null;

    /**
     * Callback URL.
     */
    #[Column(type: Types::STRING)]
    public string $callback;

    /**
     * URL for the application when the user does not authorise access.
     */
    #[Column(type: Types::STRING)]
    public string $url;

    /**
     * The claims that will be present in the JWT. If `null` only the member's id will be passed along.
     *
     * @var JWTClaims[]
     */
    #[Column(
        type: Types::SIMPLE_ARRAY,
        nullable: true,
        enumType: JWTClaims::class,
    )]
    private array $claims = [];

    /**
     * Whether the application may currently be used to authenticate.
     */
    #[Column(
        type: Types::BOOLEAN,
        options: ['default' => true],
    )]
    public bool $enabled = true;

    /**
     * The moment after which the application may no longer be used to authenticate, if any.
     */
    #[Column(
        type: Types::DATETIME_IMMUTABLE,
        nullable: true,
    )]
    public ?DateTimeImmutable $expiresAt = null;

    /**
     * @return JWTClaims[]
     */
    public function getClaims(): array
    {
        if ([] === $this->claims) {
            return [JWTClaims::Lidnr];
        }

        return $this->claims;
    }

    /**
     * @param JWTClaims[] $claims
     */
    public function setClaims(array $claims): void
    {
        $this->claims = $claims;
    }

    /**
     * Whether the application may currently mint tokens: enabled and not past any expiration date.
     */
    public function isActive(): bool
    {
        return $this->enabled
            && (null === $this->expiresAt || $this->expiresAt > new DateTimeImmutable());
    }
}
