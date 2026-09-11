<?php

declare(strict_types=1);

namespace App\Tests\Service\Database;

use App\Entity\Database\CheckoutSession;
use App\Entity\Database\Enums\CheckoutSessionStates;
use App\Entity\Database\PaymentLink;
use App\Entity\Database\ProspectiveMember;
use App\Repository\Database\ActionLinkRepository;
use App\Repository\Database\CheckoutSessionRepository;
use App\Service\Database\Member as MemberService;
use App\Service\Database\StripeService;
use DateTime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Stripe\Checkout\Session as StripeCheckoutSession;
use Stripe\Event;
use Stripe\PaymentIntent;

/**
 * What the checkout decides before it talks to Stripe.
 *
 * Everything that reaches the API is left alone here: the client is built inside the service, so those paths cannot
 * be exercised without a network. The decisions in front of them can, and they are the ones that decide whether
 * somebody is sent back to a checkout, kept away from one, or charged twice.
 */
#[CoversClass(StripeService::class)]
class StripeServiceTest extends TestCase
{
    /**
     * A finished or in-flight payment is not something to reopen: it would be a second chance to pay for the same
     * membership.
     */
    #[DataProvider('statesThatMayNotBeReopened')]
    public function testRefusesToReopenACheckoutThatIsFinishedOrUnderWay(CheckoutSessionStates $state): void
    {
        $prospectiveMember = new ProspectiveMember();

        self::assertNull(
            $this->service($this->checkoutSession($state))->restartCheckoutLink($prospectiveMember),
        );
    }

    /**
     * @return array<string, array{CheckoutSessionStates}>
     */
    public static function statesThatMayNotBeReopened(): array
    {
        return [
            'paid for' => [CheckoutSessionStates::Paid],
            'payment being processed' => [CheckoutSessionStates::Pending],
        ];
    }

    /**
     * Stripe keeps a recovery URL for 30 days; while it lasts, that is where someone is sent back to.
     */
    public function testSendsSomeoneBackToTheRecoveryUrlOfAnAbandonedCheckout(): void
    {
        $session = $this->checkoutSession(
            CheckoutSessionStates::Expired,
            '+5 days',
        );
        $session->setRecoveryUrl('https://checkout.stripe.test/recover');

        self::assertSame(
            'https://checkout.stripe.test/recover',
            $this->service($session)->restartCheckoutLink(new ProspectiveMember()),
        );
    }

    /**
     * Once the recovery URL is dead the payment link that leads to it is burned as well, so a second attempt does
     * not arrive at a page that cannot work.
     */
    public function testBurnsThePaymentLinkOfACheckoutThatIsPastRecovery(): void
    {
        $prospectiveMember = new ProspectiveMember();
        $paymentLink = new PaymentLink();
        $paymentLink->prospectiveMember = $prospectiveMember;
        $prospectiveMember->setPaymentLink($paymentLink);

        $actionLinkRepository = $this->createMock(ActionLinkRepository::class);
        $actionLinkRepository->expects(self::once())
            ->method('persist')
            ->with($paymentLink);

        $service = $this->service(
            $this->checkoutSession(
                CheckoutSessionStates::Expired,
                '-1 day',
            ),
            $actionLinkRepository,
        );

        self::assertNull($service->restartCheckoutLink($prospectiveMember));
        self::assertTrue($paymentLink->used);
    }

    /**
     * A recovered session points back at the one it came from, which is the session with the usable dates.
     */
    public function testFollowsARecoveredCheckoutBackToTheOneItCameFrom(): void
    {
        $original = $this->checkoutSession(
            CheckoutSessionStates::Expired,
            '+5 days',
        );
        $original->setRecoveryUrl('https://checkout.stripe.test/original');

        $recovered = $this->checkoutSession(
            CheckoutSessionStates::Created,
            '+5 days',
        );
        $recovered->setRecoveredFrom($original);

        self::assertSame(
            'https://checkout.stripe.test/original',
            $this->service($recovered)->restartCheckoutLink(new ProspectiveMember()),
        );
    }

    /**
     * The session id arrives in a URL, where "no session" can turn into the four letters that spell it.
     */
    #[DataProvider('sessionIdsThatAreNotOne')]
    public function testTakesNoSessionIdAsNoSession(string $sessionId): void
    {
        self::assertNull($this->service()->getLidnrFromCheckoutSession($sessionId));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function sessionIdsThatAreNotOne(): array
    {
        return [
            'empty' => [''],
            'the word null' => ['null'],
        ];
    }

    public function testCannotTellWhetherSomeoneWasRefundedWithoutAPaymentToLookUp(): void
    {
        $prospectiveMember = new ProspectiveMember();

        self::assertNull($this->service()->hasRefund($prospectiveMember));

        $withoutPaymentIntent = $this->checkoutSession(CheckoutSessionStates::Paid);

        self::assertNull($this->service($withoutPaymentIntent)->hasRefund($prospectiveMember));
    }

    /**
     * Stripe types an expandable field as either the id it was returned as or the object that id stands for,
     * depending on what the request asked to have expanded, and `payment_intent` is one of those. What is stored is
     * the id, whichever of the two arrives, because it is what a refund is looked up by later.
     */
    #[DataProvider('paymentIntentsOfASucceededPayment')]
    public function testStoresAPaymentIntentAsItsIdentifierHoweverStripeSendsIt(
        string|PaymentIntent $paymentIntent,
    ): void {
        $stored = $this->checkoutSession(CheckoutSessionStates::Pending);

        $this->service(storedSession: $stored)->handleEvent(Event::constructFrom([
            'type' => Event::CHECKOUT_SESSION_ASYNC_PAYMENT_SUCCEEDED,
            'data' => [
                'object' => StripeCheckoutSession::constructFrom([
                    'id' => 'cs_test',
                    'client_reference_id' => '8000',
                    'payment_intent' => $paymentIntent,
                ]),
            ],
        ]));

        self::assertSame(
            CheckoutSessionStates::Paid,
            $stored->state,
        );
        self::assertSame(
            'pi_test',
            $stored->paymentIntentId,
        );
    }

    /**
     * @return array<string, array{string|PaymentIntent}>
     */
    public static function paymentIntentsOfASucceededPayment(): array
    {
        return [
            'the id on its own' => ['pi_test'],
            'the object it stands for' => [PaymentIntent::constructFrom(['id' => 'pi_test'])],
        ];
    }

    private function checkoutSession(
        CheckoutSessionStates $state,
        string $expiration = '+1 day',
    ): CheckoutSession {
        $session = new CheckoutSession();
        $session->checkoutId = 'cs_test';
        $session->state = $state;
        $session->created = new DateTime('-1 hour');
        $session->expiration = new DateTime($expiration);

        return $session;
    }

    private function service(
        ?CheckoutSession $lastSession = null,
        ?ActionLinkRepository $actionLinkRepository = null,
        ?CheckoutSession $storedSession = null,
    ): StripeService {
        $checkoutSessionRepository = self::createStub(CheckoutSessionRepository::class);
        $checkoutSessionRepository->method('findLatest')->willReturn($lastSession);
        $checkoutSessionRepository->method('findById')->willReturn($storedSession);

        return new StripeService(
            self::createStub(LoggerInterface::class),
            $actionLinkRepository ?? self::createStub(ActionLinkRepository::class),
            $checkoutSessionRepository,
            self::createStub(MemberService::class),
            '2024-06-20',
            'sk_test',
            'whsec_test',
            'price_test',
            'https://join.gewis.test/cancel',
            'https://join.gewis.test/success',
        );
    }
}
