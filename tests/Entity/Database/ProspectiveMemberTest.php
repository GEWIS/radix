<?php

declare(strict_types=1);

namespace App\Tests\Entity\Database;

use App\Entity\Database\Address;
use App\Entity\Database\CheckoutSession;
use App\Entity\Database\Enums\AddressTypes;
use App\Entity\Database\Enums\CheckoutSessionStates;
use App\Entity\Database\Enums\PostalRegions;
use App\Entity\Database\Enums\Studies;
use App\Entity\Database\PaymentLink;
use App\Entity\Database\ProspectiveMember;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProspectiveMember::class)]
final class ProspectiveMemberTest extends TestCase
{
    /**
     * A prospective member is shown by name throughout the registration flow, and the middle name is only part of it
     * when there is one.
     */
    public function testTheFullNameIncludesTheMiddleNameOnlyWhenItIsThere(): void
    {
        $member = $this->member();
        self::assertSame(
            'Test van Lid',
            $member->getFullName(),
        );

        $member->middleName = '';
        self::assertSame(
            'Test Lid',
            $member->getFullName(),
        );
    }

    /**
     * The registration is exported as one flat record, including the address a payment provider needs and the mailing
     * lists the applicant agreed to.
     */
    public function testTheRegistrationExportsTheApplicantAndTheirAddress(): void
    {
        $member = $this->member();
        $member->studentNumber = '1234567';
        $member->study = Studies::BCS;
        $member->addLists(['ggv', 'sport']);

        $array = $member->toArray();

        self::assertSame(
            8100,
            $array['lidnr'],
        );
        self::assertSame(
            'Test van Lid',
            $array['fullName'],
        );
        self::assertSame(
            '1234567',
            $array['studentNumber'],
        );
        self::assertSame(
            'Bachelor Computer Science and Engineering',
            $array['study'],
        );
        self::assertSame(
            '2000-01-01',
            $array['birth'],
        );
        self::assertSame(
            [
                'ggv',
                'sport',
            ],
            $array['lists'],
        );
        self::assertSame(
            '1',
            $array['agreed'],
        );
        self::assertSame(
            '1',
            $array['agreedStripe'],
        );
        self::assertSame(
            [
                'type' => AddressTypes::Student,
                'country' => PostalRegions::Netherlands,
                'street' => 'Den Dolech',
                'number' => '2',
                'city' => 'Eindhoven',
                'postalCode' => '5612 AZ',
                'phone' => '040-2471234',
            ],
            $array['address'],
        );
    }

    /**
     * The applicant has one address, which is their student address until they say otherwise.
     */
    public function testTheAddressIsCopiedOntoTheApplicant(): void
    {
        $member = new ProspectiveMember();
        $address = new Address();
        $address->type = AddressTypes::Home;
        $address->country = PostalRegions::Belgium;
        $address->street = 'Oude Markt';
        $address->number = '1';
        $address->city = 'Leuven';
        $address->postalCode = '3000';
        $address->phone = '016 123456';

        $member->setAddress($address);

        $addresses = $member->getAddresses();
        self::assertCount(
            1,
            $addresses,
        );
        self::assertSame(
            PostalRegions::Belgium,
            $addresses['studentAddress']->country,
        );
        self::assertSame(
            'Oude Markt',
            $addresses['studentAddress']->street,
        );
        self::assertSame(
            AddressTypes::Student,
            $addresses['studentAddress']->type,
        );
    }

    /**
     * Mailing list subscriptions are a set, not a list: signing up twice for the same list must not produce two rows.
     */
    public function testMailingListsAreDeduplicated(): void
    {
        $member = $this->member();
        $member->addList('ggv');
        $member->addList('ggv');
        $member->addLists(['sport', 'ggv']);

        self::assertSame(
            [
                'ggv',
                'sport',
            ],
            $member->getLists(),
        );
    }

    /**
     * What may be done with an application follows from the state of its last checkout: only a paid or manually
     * settled one may be approved, and only a fully expired one may be removed.
     */
    #[DataProvider('checkoutStates')]
    public function testTheCheckoutStateDecidesWhatMayBeDone(
        CheckoutSessionStates $state,
        bool $canBeApproved,
        bool $paymentSettled,
        bool $canBeDeleted,
    ): void {
        $member = $this->member();
        $member->getCheckoutSessions()->add($this->checkout($state, '+1 day'));

        self::assertSame(
            $state,
            $member->getLastCheckoutSessionState(),
        );
        self::assertSame(
            $canBeApproved,
            $member->canBeApproved(),
        );
        self::assertSame(
            $paymentSettled,
            $member->isPaymentSettled(),
        );
        self::assertSame(
            $canBeDeleted,
            $member->canBeDeleted(),
        );
    }

    /**
     * @return iterable<string, array{CheckoutSessionStates, bool, bool, bool}>
     */
    public static function checkoutStates(): iterable
    {
        yield 'created' => [
            CheckoutSessionStates::Created,
            false,
            false,
            false,
        ];

        yield 'cancelled' => [
            CheckoutSessionStates::Cancelled,
            false,
            false,
            false,
        ];

        yield 'pending' => [
            CheckoutSessionStates::Pending,
            false,
            false,
            false,
        ];

        yield 'failed' => [
            CheckoutSessionStates::Failed,
            true,
            false,
            false,
        ];

        yield 'paid' => [
            CheckoutSessionStates::Paid,
            true,
            true,
            true,
        ];

        yield 'expired' => [
            CheckoutSessionStates::Expired,
            true,
            false,
            false,
        ];
    }

    /**
     * An expired checkout is only gone once its expiration has actually passed; until then it can still be recovered,
     * so the application cannot be thrown away.
     */
    public function testAnExpiredCheckoutMustAlsoBePastItsDate(): void
    {
        $member = $this->member();
        $member->getCheckoutSessions()->add($this->checkout(CheckoutSessionStates::Expired, '-1 minute'));

        self::assertTrue($member->canBeDeleted());

        $member = $this->member();
        $member->getCheckoutSessions()->add($this->checkout(CheckoutSessionStates::Expired, '+1 minute'));

        self::assertFalse($member->canBeDeleted());
    }

    /**
     * An application with no checkout at all is stuck rather than approvable: there is no payment to judge.
     */
    public function testAnApplicationWithoutACheckoutCannotBeApprovedOrRemoved(): void
    {
        $member = $this->member();

        self::assertNull($member->getLastCheckoutSessionState());
        self::assertFalse($member->canBeApproved());
        self::assertFalse($member->isPaymentSettled());
        self::assertFalse($member->canBeDeleted());
    }

    /**
     * A payment link that was already clicked settled the fee; sending it again would invite a second payment.
     */
    public function testAPaymentLinkCanOnlyBeResentWhileUnused(): void
    {
        $member = $this->member();
        self::assertFalse($member->canResendPaymentLink());

        $link = new PaymentLink();
        $member->setPaymentLink($link);

        self::assertSame(
            $link,
            $member->getPaymentLink(),
        );
        self::assertTrue($member->canResendPaymentLink());

        $link->used = true;

        self::assertFalse($member->canResendPaymentLink());
    }

    private function member(): ProspectiveMember
    {
        $member = new ProspectiveMember();
        $member->lidnr = 8100;
        $member->email = 'lid@example.org';
        $member->initials = 'T.';
        $member->firstName = 'Test';
        $member->middleName = 'van';
        $member->lastName = 'Lid';
        $member->birth = new DateTimeImmutable('2000-01-01');
        $member->changedOn = new DateTimeImmutable('2026-01-01');

        $address = new Address();
        $address->country = PostalRegions::Netherlands;
        $address->street = 'Den Dolech';
        $address->number = '2';
        $address->city = 'Eindhoven';
        $address->postalCode = '5612 AZ';
        $address->phone = '040-2471234';
        $member->setAddress($address);

        return $member;
    }

    private function checkout(
        CheckoutSessionStates $state,
        string $expiration,
    ): CheckoutSession {
        $session = new CheckoutSession();
        $session->state = $state;
        $session->expiration = new DateTimeImmutable($expiration);

        return $session;
    }
}
