<?php

declare(strict_types=1);

namespace App\Entity\Database;

use App\Entity\Application\Traits\TempHashTrait;
use App\Repository\Database\ActionLinkRepository;
use App\Util\Application\SplitToken;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DiscriminatorColumn;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\InheritanceType;

/**
 * Class for links that can be clicked.
 *
 * Only the selector and a hash of the verifier are stored, so a link cannot be reconstructed from the register.
 * {@see self::$plainToken} is set in the request that generates the token and nowhere else, and
 * {@see self::rotateToken()} generates a replacement, which invalidates the previous token. Expiry differs per
 * subclass, so each one implements {@see self::linkExpired()}.
 */
#[Entity(repositoryClass: ActionLinkRepository::class)]
#[InheritanceType('SINGLE_TABLE')]
#[DiscriminatorColumn(
    name: 'type',
    type: Types::STRING,
)]
#[DiscriminatorMap(
    value: [
        'payment' => PaymentLink::class,
        'renewal' => RenewalLink::class,
        'email_change' => EmailChangeLink::class,
        'graduate_conversion' => GraduateConversionLink::class,
    ],
)]
#[Index(
    columns: ['selector'],
    name: 'IDX_action_link_selector',
)]
#[Index(
    columns: ['tempHash'],
    name: 'IDX_action_link_temp_hash',
)]
abstract class ActionLink
{
    use TempHashTrait;

    public const string HASH_ALGO = 'sha256';

    private const int SELECTOR_BYTES = 16;
    private const int VERIFIER_BYTES = 48;

    /**
     * Identity
     */
    #[Id]
    #[Column(type: Types::INTEGER)]
    #[GeneratedValue(strategy: 'AUTO')]
    public private(set) ?int $id = null;

    /**
     * If the URL was clicked
     */
    #[Column(type: Types::BOOLEAN)]
    public bool $used = false;

    #[Column(type: Types::STRING)]
    public private(set) string $selector;

    #[Column(type: Types::STRING)]
    public private(set) string $hashedToken;

    /**
     * Not a column, so it is only set in the request that generated the token.
     */
    public private(set) ?string $plainToken = null;

    public function __construct()
    {
        $this->rotateToken();
    }

    public function rotateToken(): string
    {
        $token = SplitToken::generate(
            self::SELECTOR_BYTES,
            self::VERIFIER_BYTES,
            self::HASH_ALGO,
        );

        $this->selector = $token['selector'];
        $this->hashedToken = $token['hashedToken'];
        $this->plainToken = $token['token'];

        return $token['token'];
    }

    public function tokenMatches(string $verifier): bool
    {
        return SplitToken::matches(
            $this->hashedToken,
            $verifier,
            self::HASH_ALGO,
        );
    }

    /**
     * A link that does not expire on its own does not override this.
     */
    public function linkExpired(): bool
    {
        return false;
    }
}
