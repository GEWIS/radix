<?php

declare(strict_types=1);

namespace App\Entity\Database;

use App\Entity\Database\Enums\AddressTypes;
use App\Entity\Database\Enums\CheckoutSessionStates;
use App\Entity\Database\Enums\PostalRegions;
use App\Entity\Database\Enums\Studies;
use App\Repository\Database\ProspectiveMemberRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\OrderBy;
use SortDirection;

use function assert;
use function in_array;

/**
 * ProspectiveMember model.
 */
#[Entity(repositoryClass: ProspectiveMemberRepository::class)]
class ProspectiveMember
{
    /**
     * The user
     */
    #[Id]
    #[Column(type: 'integer')]
    #[GeneratedValue(strategy: 'AUTO')]
    public int $lidnr;

    /**
     * Member's email address.
     */
    #[Column(type: 'string')]
    public string $email;

    /**
     * Member's last name.
     */
    #[Column(type: 'string')]
    public string $lastName;

    /**
     * Middle name.
     */
    #[Column(type: 'string')]
    public string $middleName;

    /**
     * Initials.
     */
    #[Column(type: 'string')]
    public string $initials;

    /**
     * First name.
     */
    #[Column(type: 'string')]
    public string $firstName;

    /**
     * TU/e student number.
     */
    #[Column(
        type: 'string',
        nullable: true,
    )]
    public ?string $studentNumber = null;

    /**
     * Study of the member.
     */
    #[Column(
        enumType: Studies::class,
    )]
    public Studies $study = Studies::Unknown;

    /**
     * Last changed date of membership.
     */
    #[Column(type: 'date_immutable')]
    public DateTimeImmutable $changedOn;

    /**
     * Member birthdate.
     */
    #[Column(type: 'date_immutable')]
    public DateTimeImmutable $birth;

    /**
     * How much the member has paid for membership. 0 by default.
     */
    #[Column(type: 'integer')]
    public int $paid = 0;

    /**
     * Country.
     */
    #[Column(
        enumType: PostalRegions::class,
    )]
    public private(set) PostalRegions $country;

    /**
     * Street.
     */
    #[Column(type: 'string')]
    public private(set) string $street;

    /**
     * House number (+ suffix)
     */
    #[Column(type: 'string')]
    public private(set) string $number;

    /**
     * Postal code.
     */
    #[Column(type: 'string')]
    public private(set) string $postalCode;

    /**
     * City.
     */
    #[Column(type: 'string')]
    public private(set) string $city;

    /**
     * Phone number.
     */
    #[Column(type: 'string')]
    public private(set) string $phone;

    /**
     * Memberships of mailing lists.
     *
     * @var ?string[] $lists
     */
    #[Column(
        type: 'simple_array',
        nullable: true,
    )]
    private ?array $lists = [];

    /**
     * The Checkout Sessions for this prospective member.
     *
     * @var Collection<array-key, CheckoutSession>
     */
    #[OneToMany(
        targetEntity: CheckoutSession::class,
        mappedBy: 'prospectiveMember',
        orphanRemoval: true,
        cascade: ['remove'],
    )]
    #[OrderBy(['created' => SortDirection::Ascending])]
    private Collection $checkoutSessions;

    /**
     * Payment link that can be used by the prospective member to restart a Checkout Session.
     */
    #[OneToOne(
        targetEntity: PaymentLink::class,
        mappedBy: 'prospectiveMember',
    )]
    private ?PaymentLink $paymentLink = null;

    public function __construct()
    {
        $this->checkoutSessions = new ArrayCollection();
    }

    /**
     * Assemble the member's full name.
     */
    public function getFullName(): string
    {
        $name = $this->firstName . ' ';

        $middle = $this->middleName;
        if (!empty($middle)) {
            $name .= $middle . ' ';
        }

        return $name . $this->lastName;
    }

    /**
     * Convert to array.
     *
     * @return array{
     *     lidnr: int,
     *     email: string,
     *     fullName: string,
     *     lastName: string,
     *     middleName: string,
     *     initials: string,
     *     firstName: string,
     *     studentNumber: ?string,
     *     study: string,
     *     birth: string,
     *     lists: string[],
     *     address: array{
     *         type: AddressTypes,
     *         country: PostalRegions,
     *         street: string,
     *         number: string,
     *         city: string,
     *         postalCode: string,
     *         phone: string,
     *     },
     *     agreed: string,
     *     agreedStripe: string,
     * }
     */
    public function toArray(): array
    {
        return [
            'lidnr' => $this->lidnr,
            'email' => $this->email,
            'fullName' => $this->getFullName(),
            'lastName' => $this->lastName,
            'middleName' => $this->middleName,
            'initials' => $this->initials,
            'firstName' => $this->firstName,
            'studentNumber' => $this->studentNumber,
            'study' => $this->study->getName()->getMessage(),
            'birth' => $this->birth->format('Y-m-d'),
            'lists' => $this->getLists(),
            'address' => $this->getAddresses()['studentAddress']->toArray(),
            'agreed' => '1',
            'agreedStripe' => '1',
        ];
    }

    /**
     * Get all addresses.
     *
     * @return array{studentAddress: Address}
     */
    public function getAddresses(): array
    {
        $address = new Address();
        $address->type = AddressTypes::Student;
        $address->country = $this->country;
        $address->street = $this->street;
        $address->number = $this->number;
        $address->postalCode = $this->postalCode;
        $address->city = $this->city;
        $address->phone = $this->phone;

        return ['studentAddress' => $address];
    }

    /**
     * Add an address.
     */
    public function setAddress(Address $address): void
    {
        $this->country = $address->country;
        $this->street = $address->street;
        $this->number = $address->number;
        $this->postalCode = $address->postalCode;
        $this->city = $address->city;
        $this->phone = $address->phone;
    }

    /**
     * Get mailing list subscriptions.
     *
     * @return string[]
     */
    public function getLists(): array
    {
        if (null === $this->lists) {
            return [];
        }

        return $this->lists;
    }

    /**
     * Add a mailing list subscription.
     *
     * Note that this is the owning side.
     */
    public function addList(string $list): void
    {
        if (
            in_array(
                $list,
                $this->lists,
            )
        ) {
            return;
        }

        $this->lists[] = $list;
    }

    /**
     * Add multiple mailing lists.
     *
     * @param string[] $lists
     */
    public function addLists(array $lists): void
    {
        foreach ($lists as $list) {
            $this->addList($list);
        }
    }

    /**
     * @return Collection<array-key, CheckoutSession>
     */
    public function getCheckoutSessions(): Collection
    {
        return $this->checkoutSessions;
    }

    /**
     * Determine whether the prospective member can be approved (and thus become a member). This should only be possible
     * if the Checkout Session's state is 'PAID' or 'FAILED' or 'EXPIRED'. The latter two indicate that this is a
     * manual approval, for example, when the prospective member paid with cash.
     */
    public function canBeApproved(): bool
    {
        $lastState = $this->getLastCheckoutSessionState();

        if (null === $lastState) {
            return false;
        }

        return CheckoutSessionStates::Paid === $lastState
            || CheckoutSessionStates::Failed === $lastState
            || CheckoutSessionStates::Expired === $lastState;
    }

    /**
     * Determine whether the prospective member can be deleted. This should only be possible if the last Checkout
     * Session's state is 'PAID' or fully 'EXPIRED'.
     */
    public function canBeDeleted(): bool
    {
        $lastCheckoutSession = $this->checkoutSessions->last();
        assert($lastCheckoutSession instanceof CheckoutSession || false === $lastCheckoutSession);

        if (false === $lastCheckoutSession) {
            // No Checkout Session can be found, we are in a state of many unknowns, do not allow removal.
            return false;
        }

        $lastState = $lastCheckoutSession->state;

        if (CheckoutSessionStates::Expired === $lastState) {
            // Checkout Session is fully expired, it cannot be recovered and is scheduled for automatic removal.
            return (new DateTimeImmutable()) >= $lastCheckoutSession->expiration;
        }

        return CheckoutSessionStates::Paid === $lastState;
    }

    /**
     * Determine whether the prospective member has paid. This should only be possible if the Checkout Session's state
     * is 'PAID'.
     */
    public function isPaymentSettled(): bool
    {
        $lastState = $this->getLastCheckoutSessionState();

        if (null === $lastState) {
            return false;
        }

        return CheckoutSessionStates::Paid === $lastState;
    }

    /**
     * The state of the applicant's most recent checkout, which is where their registration has got to. Null when
     * there is no checkout at all, which is its own kind of stuck.
     */
    public function getLastCheckoutSessionState(): ?CheckoutSessionStates
    {
        $lastCheckoutSession = $this->checkoutSessions->last();
        assert($lastCheckoutSession instanceof CheckoutSession || false === $lastCheckoutSession);

        if (false === $lastCheckoutSession) {
            // No Checkout Session can be found, we are in a state of many unknowns, return `null` to signal error.
            return null;
        }

        return $lastCheckoutSession->state;
    }

    /**
     * @psalm-ignore-nullable-return
     */
    public function getPaymentLink(): ?PaymentLink
    {
        return $this->paymentLink;
    }

    public function setPaymentLink(PaymentLink $paymentLink): void
    {
        $this->paymentLink = $paymentLink;
    }
}
