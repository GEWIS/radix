<?php

declare(strict_types=1);

namespace App\Entity\Database\User;

use App\Entity\Application\Traits\TimestampableTrait;
use App\Entity\User\Enums\ApiPermissions;
use App\Repository\User\ApiPrincipalRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\HasLifecycleCallbacks;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\UniqueConstraint;
use SensitiveParameter;
use Symfony\Component\Validator\Constraints as Assert;

use function array_map;
use function base64_encode;
use function hash;
use function in_array;
use function random_bytes;
use function str_repeat;
use function substr;

/**
 * An owner of an API token, and the set of permissions that token grants. Only a hash of the token is kept; the
 * last few characters stay in the clear so an administrator can tell two apart.
 */
#[Entity(repositoryClass: ApiPrincipalRepository::class)]
#[HasLifecycleCallbacks]
#[UniqueConstraint(
    name: 'apiprincipal_token_hash_unique_idx',
    columns: ['tokenHash'],
)]
class ApiPrincipal
{
    use TimestampableTrait;

    public const int TOKEN_LENGTH = 128;

    private const int HINT_LENGTH = 5;

    #[Id]
    #[Column(type: 'integer')]
    #[GeneratedValue(strategy: 'AUTO')]
    public protected(set) ?int $id = null;

    #[Column(
        type: 'string',
        length: 64,
    )]
    public protected(set) string $tokenHash;

    #[Column(
        type: 'string',
        length: self::HINT_LENGTH,
    )]
    protected string $tokenHint;

    #[Column(
        type: 'string',
        nullable: true,
    )]
    #[Assert\Length(
        min: 8,
        max: 255,
    )]
    public ?string $description = null;

    /**
     * Column type is necessary here.
     *
     * @var ApiPermissions[] $permissions
     */
    #[Column(
        type: 'simple_array',
        nullable: true,
        enumType: ApiPermissions::class,
    )]
    protected ?array $permissions = null;

    #[Column(
        type: Types::DATE_IMMUTABLE,
        nullable: true,
    )]
    public protected(set) ?DateTimeImmutable $lastUsedAt = null;

    #[Column(
        type: Types::DATE_IMMUTABLE,
        nullable: true,
    )]
    public ?DateTimeImmutable $expiresAt = null;

    #[Column(
        type: Types::DATETIME_IMMUTABLE,
        nullable: true,
    )]
    public protected(set) ?DateTimeImmutable $revokedAt = null;

    public static function hash(
        #[SensitiveParameter]
        string $token,
    ): string {
        return hash(
            'sha256',
            $token,
        );
    }

    public function getToken(): string
    {
        return str_repeat(
            '*',
            self::TOKEN_LENGTH - self::HINT_LENGTH,
        ) . $this->tokenHint;
    }

    public function generateToken(): string
    {
        $token = base64_encode(random_bytes(96));

        $this->tokenHash = self::hash($token);
        $this->tokenHint = substr(
            $token,
            -self::HINT_LENGTH,
        );

        return $token;
    }

    /**
     * @return ApiPermissions[]
     */
    public function getPermissions(): array
    {
        return $this->permissions ?? [];
    }

    /**
     * To allow for hydrator, we convert possible strings
     *
     * @param ApiPermissions[]|string[] $permissions
     */
    public function setPermissions(array $permissions): void
    {
        $this->permissions = array_map(
            static function ($p): ApiPermissions {
                return $p instanceof ApiPermissions
                    ? $p
                    : ApiPermissions::from($p);
            },
            $permissions,
        );
    }

    public function markUsedOn(DateTimeImmutable $day): void
    {
        $this->lastUsedAt = $day;
    }

    public function revoke(): void
    {
        $this->revokedAt ??= new DateTimeImmutable();
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }

    public function isExpired(): bool
    {
        return null !== $this->expiresAt
            && $this->expiresAt < new DateTimeImmutable('today');
    }

    public function isUsable(): bool
    {
        return !$this->isRevoked()
            && !$this->isExpired();
    }

    public function can(ApiPermissions $permission): bool
    {
        if (
            in_array(
                ApiPermissions::All,
                $this->getPermissions(),
                true,
            )
        ) {
            return true;
        }

        return in_array(
            $permission,
            $this->getPermissions(),
            true,
        );
    }
}
