<?php

declare(strict_types=1);

namespace App\Entity\Database;

use App\Entity\Database\Enums\CheckoutSessionStates;
use App\Repository\Database\CheckoutSessionRepository;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;

/**
 * Saved query model.
 */
#[Entity(repositoryClass: CheckoutSessionRepository::class)]
class CheckoutSession
{
    /**
     * Payment ID.
     */
    #[Id]
    #[Column(type: 'integer')]
    #[GeneratedValue(strategy: 'AUTO')]
    public private(set) ?int $id = null;

    /**
     * Identifier of the checkout session, Stripe uses case SENSITIVE identifiers, with PostgreSQL this is not a problem
     * but if we ever switch we need to use the `utf8_bin` collation on this column (as recommended).
     *
     * See {@link https://stripe.com/docs/upgrades#what-changes-does-stripe-consider-to-be-backwards-compatible}.
     */
    #[Column(
        type: 'string',
        unique: true,
    )]
    public string $checkoutId;

    #[ManyToOne(
        targetEntity: ProspectiveMember::class,
        inversedBy: 'checkoutSessions',
    )]
    #[JoinColumn(
        name: 'prospective_member',
        referencedColumnName: 'lidnr',
    )]
    public ProspectiveMember $prospectiveMember;

    /**
     * Creation of the checkout session.
     */
    #[Column(type: 'datetime')]
    public DateTime $created;

    /**
     * Expiration of the checkout session.
     *
     * If $state == CheckoutSessionStates::Expired, then this is the last date this checkout session can be recovered.
     */
    #[Column(type: 'datetime')]
    public DateTime $expiration;

    /**
     * The identifier of the PaymentIntent associated with this Checkout Session when the state is 'PAID'.
     */
    #[Column(
        type: 'string',
        nullable: true,
    )]
    public ?string $paymentIntentId = null;

    /**
     * Recovery URL for the Checkout Session when the state is 'EXPIRED'.
     */
    #[Column(
        type: 'string',
        nullable: true,
    )]
    private ?string $recoveryUrl = null;

    /**
     * One-to-many side for self-reference after recovery.
     *
     * @var Collection<array-key, CheckoutSession>
     */
    #[OneToMany(
        targetEntity: self::class,
        mappedBy: 'recoveredFrom',
    )]
    private Collection $recoveredBy;

    #[ManyToOne(
        targetEntity: self::class,
        inversedBy: 'recoveredBy',
        cascade: ['remove'],
    )]
    #[JoinColumn(
        name: 'recovered_from_id',
        referencedColumnName: 'id',
        onDelete: 'SET NULL',
    )]
    private ?CheckoutSession $recoveredFrom = null;

    /**
     * The state of the payment.
     */
    #[Column(
        enumType: CheckoutSessionStates::class,
    )]
    public CheckoutSessionStates $state = CheckoutSessionStates::Created;

    public function __construct()
    {
        $this->recoveredBy = new ArrayCollection();
    }

    public function getRecoveryUrl(): ?string
    {
        return $this->recoveryUrl;
    }

    public function setRecoveryUrl(string $recoveryUrl): void
    {
        $this->recoveryUrl = $recoveryUrl;
    }

    /**
     * @return Collection<array-key, CheckoutSession>
     */
    public function getRecoveredBy(): Collection
    {
        return $this->recoveredBy;
    }

    public function getRecoveredFrom(): ?CheckoutSession
    {
        return $this->recoveredFrom;
    }

    public function setRecoveredFrom(CheckoutSession $recoveredFrom): void
    {
        $this->recoveredFrom = $recoveredFrom;
    }
}
