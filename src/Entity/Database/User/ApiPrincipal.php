<?php

declare(strict_types=1);

namespace App\Entity\Database\User;

use App\Entity\Application\Traits\TimestampableTrait;
use App\Entity\User\Enums\ApiPermissions;
use App\Repository\User\ApiPrincipalRepository;
use DateTime;
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
 * A holder of an API token, and the set of permissions that token carries. Only a hash of the token is kept; the
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
    protected ?int $id = null;

    #[Column(
        type: 'string',
        length: 64,
    )]
    protected string $tokenHash;

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
    protected ?string $description = null;

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
        type: Types::DATE_MUTABLE,
        nullable: true,
    )]
    protected ?DateTime $lastUsedAt = null;

    #[Column(
        type: Types::DATE_MUTABLE,
        nullable: true,
    )]
    protected ?DateTime $expiresAt = null;

    #[Column(
        type: Types::DATETIME_MUTABLE,
        nullable: true,
    )]
    protected ?DateTime $revokedAt = null;

    /**
     * @psalm-ignore-nullable-return
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    public static function hash(
        #[SensitiveParameter]
        string $token,
    ): string {
        return hash(
            'sha256',
            $token,
        );
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
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

    public function getLastUsedAt(): ?DateTime
    {
        return $this->lastUsedAt;
    }

    public function markUsedOn(DateTime $day): void
    {
        $this->lastUsedAt = $day;
    }

    public function getExpiresAt(): ?DateTime
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?DateTime $expiresAt): void
    {
        $this->expiresAt = $expiresAt;
    }

    public function getRevokedAt(): ?DateTime
    {
        return $this->revokedAt;
    }

    public function revoke(): void
    {
        $this->revokedAt ??= new DateTime();
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }

    public function isExpired(): bool
    {
        return null !== $this->expiresAt
            && $this->expiresAt < new DateTime('today');
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
