<?php

declare(strict_types=1);

namespace App\Entity\Database;

use App\Entity\Application\Traits\TempHashTrait;
use App\Repository\Database\ActionLinkRepository;
use App\Util\Application\SplitToken;
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
 * Only the selector and a hash of the verifier are kept, so a link cannot be written out again from what is stored:
 * {@see self::getPlainToken()} answers in the minting request only, and {@see self::rotateToken()} is how a page
 * hands one out again, at the price of the token that went before. Expiry differs per kind, so each subclass answers
 * {@see self::linkExpired()} for itself.
 */
#[Entity(repositoryClass: ActionLinkRepository::class)]
#[InheritanceType('SINGLE_TABLE')]
#[DiscriminatorColumn(
    name: 'type',
    type: 'string',
)]
#[DiscriminatorMap(
    value: [
        'payment' => PaymentLink::class,
        'renewal' => RenewalLink::class,
        'email_change' => EmailChangeLink::class,
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
    #[Column(type: 'integer')]
    #[GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    /**
     * If the URL was clicked
     */
    #[Column(type: 'boolean')]
    private bool $used = false;

    #[Column(type: 'string')]
    private string $selector;

    #[Column(type: 'string')]
    private string $hashedToken;

    /**
     * Not stored, so it answers only in the request that minted it.
     */
    private ?string $plainToken = null;

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

    public function getSelector(): string
    {
        return $this->selector;
    }

    public function getHashedToken(): string
    {
        return $this->hashedToken;
    }

    public function getPlainToken(): ?string
    {
        return $this->plainToken;
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
     * A kind of link that does not go stale on its own says so by not overriding this.
     */
    public function linkExpired(): bool
    {
        return false;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function isUsed(): bool
    {
        return $this->used;
    }

    public function setUsed(bool $used): void
    {
        $this->used = $used;
    }
}
