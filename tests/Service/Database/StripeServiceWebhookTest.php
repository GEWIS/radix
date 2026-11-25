<?php

declare(strict_types=1);

namespace App\Tests\Service\Database;

use App\Entity\Database\CheckoutSession;
use App\Entity\Database\Enums\CheckoutSessionStates;
use App\Entity\Database\PaymentLink;
use App\Entity\Database\ProspectiveMember;
use App\Message\Database\RegistrationUpdate;
use App\Repository\Database\ActionLinkRepository;
use App\Repository\Database\CheckoutSessionRepository;
use App\Service\Database\Member as MemberService;
use App\Service\Database\StripeService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Stripe\Checkout\Session as StripeCheckoutSession;
use Stripe\Event;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\WebhookSignature;
use UnexpectedValueException;

use function json_encode;

/**
 * The webhook is the only place where the payment state moves, and its signature is the only thing that authenticates
 * the call, so every event either changes the stored session or is refused before anything is written.
 */
#[CoversClass(StripeService::class)]
class StripeServiceWebhookTest extends TestCase
{
    private const string SECRET = 'whsec_test';

    public function testAWebhookWithoutASignatureIsRefused(): void
    {
        $checkoutSessionRepository = $this->createMock(CheckoutSessionRepository::class);
        $checkoutSessionRepository->expects(self::never())->method('persist');

        $service = $this->service($checkoutSessionRepository);

        self::assertFalse($service->handleWebhook($this->completedPayload(), null));
    }

    public function testAWebhookWithAWrongSignatureIsRefused(): void
    {
        $checkoutSessionRepository = $this->createMock(CheckoutSessionRepository::class);
        $checkoutSessionRepository->expects(self::never())->method('persist');

        $payload = $this->completedPayload();
        $service = $this->service($checkoutSessionRepository);

        self::assertFalse($service->handleWebhook(
            $payload,
            WebhookSignature::generateSignatureHeader(
                $payload,
                'whsec_other',
            ),
        ));
    }

    public function testAValidlySignedRefundProblemIsAcceptedAndMailed(): void
    {
        $memberService = $this->createMock(MemberService::class);
        $memberService->expects(self::once())
            ->method('sendRefundProblemEmail')
            ->with(
                're_test',
                Refund::STATUS_FAILED,
            );

        $service = $this->service(memberService: $memberService);
        $payload = $this->refundPayload(Refund::STATUS_FAILED);

        self::assertTrue($service->handleWebhook(
            $payload,
            WebhookSignature::generateSignatureHeader(
                $payload,
                self::SECRET,
            ),
        ));
    }

    public function testAMalformedPayloadDoesNotVerify(): void
    {
        $payload = '{"type": "charge.refund.updated", ';

        self::assertNull($this->service()->verifyEvent(
            $payload,
            WebhookSignature::generateSignatureHeader(
                $payload,
                self::SECRET,
            ),
        ));
    }

    public function testAPayloadSignedWithAnotherSecretDoesNotVerify(): void
    {
        $payload = $this->completedPayload();

        self::assertNull($this->service()->verifyEvent(
            $payload,
            WebhookSignature::generateSignatureHeader(
                $payload,
                'whsec_other',
            ),
        ));
    }

    /**
     * The secretary has to act on a refund that did not go through, so each terminal failure is reported, while a
     * successful refund is not.
     */
    #[DataProvider('refundStatuses')]
    public function testOnlyARefundThatNeedsAttentionIsMailed(
        string $status,
        bool $mailed,
    ): void {
        $memberService = $this->createMock(MemberService::class);
        $memberService
            ->expects($mailed ? self::once() : self::never())
            ->method('sendRefundProblemEmail')
            ->with(
                're_test',
                $status,
            );

        $this->service(memberService: $memberService)->handleEvent($this->refundEvent($status));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function refundStatuses(): array
    {
        return [
            'failed' => [
                Refund::STATUS_FAILED,
                true,
            ],
            'requires action' => [
                Refund::STATUS_REQUIRES_ACTION,
                true,
            ],
            'canceled' => [
                Refund::STATUS_CANCELED,
                true,
            ],
            'succeeded' => [
                Refund::STATUS_SUCCEEDED,
                false,
            ],
        ];
    }

    #[DataProvider('paymentIntentsOfASucceededPayment')]
    public function testACompletedPaidCheckoutIsStoredAsPaidAndClosesThePaymentLink(
        string|PaymentIntent $paymentIntent,
    ): void {
        $stored = $this->storedSession();
        $paymentLink = $this->paymentLink($stored->prospectiveMember);

        $checkoutSessionRepository = $this->createMock(CheckoutSessionRepository::class);
        $checkoutSessionRepository->method('findById')->willReturn($stored);
        $checkoutSessionRepository->expects(self::atLeastOnce())->method('persist')->with($stored);

        $actionLinkRepository = $this->createMock(ActionLinkRepository::class);
        $actionLinkRepository->expects(self::once())
            ->method('findPaymentByProspectiveMember')
            ->with(8000)
            ->willReturn($paymentLink);
        $actionLinkRepository->expects(self::once())->method('persist')->with($paymentLink);

        $this->service(
            $checkoutSessionRepository,
            $actionLinkRepository,
        )->handleEvent($this->checkoutEvent(
            Event::CHECKOUT_SESSION_COMPLETED,
            [
                'payment_status' => 'paid',
                'payment_intent' => $paymentIntent,
            ],
        ));

        self::assertSame(
            CheckoutSessionStates::Paid,
            $stored->state,
        );
        self::assertSame(
            'pi_test',
            $stored->paymentIntentId,
        );
        self::assertTrue($paymentLink->used);
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

    public function testACompletedCheckoutWithADelayedPaymentIsPendingAndAlsoClosesThePaymentLink(): void
    {
        $stored = $this->storedSession();
        $paymentLink = $this->paymentLink($stored->prospectiveMember);

        $checkoutSessionRepository = $this->createMock(CheckoutSessionRepository::class);
        $checkoutSessionRepository->method('findById')->willReturn($stored);
        $checkoutSessionRepository->expects(self::atLeastOnce())->method('persist')->with($stored);

        $actionLinkRepository = $this->stubPaymentLinkRepository($paymentLink);

        $this->service(
            $checkoutSessionRepository,
            $actionLinkRepository,
        )->handleEvent($this->checkoutEvent(
            Event::CHECKOUT_SESSION_COMPLETED,
            ['payment_status' => 'unpaid'],
        ));

        self::assertSame(
            CheckoutSessionStates::Pending,
            $stored->state,
        );
        self::assertNull($stored->paymentIntentId);
        self::assertTrue($paymentLink->used);
    }

    public function testADelayedPaymentThatSucceededMarksTheCheckoutPaid(): void
    {
        $stored = $this->storedSession();
        $stored->state = CheckoutSessionStates::Pending;
        $paymentLink = $this->paymentLink($stored->prospectiveMember);

        $checkoutSessionRepository = $this->createMock(CheckoutSessionRepository::class);
        $checkoutSessionRepository->method('findById')->willReturn($stored);
        $checkoutSessionRepository->expects(self::atLeastOnce())->method('persist')->with($stored);

        $this->service(
            $checkoutSessionRepository,
            $this->stubPaymentLinkRepository($paymentLink),
        )->handleEvent($this->checkoutEvent(
            Event::CHECKOUT_SESSION_ASYNC_PAYMENT_SUCCEEDED,
            ['payment_intent' => 'pi_test'],
        ));

        self::assertSame(
            CheckoutSessionStates::Paid,
            $stored->state,
        );
        self::assertSame(
            'pi_test',
            $stored->paymentIntentId,
        );
        self::assertTrue($paymentLink->used);
    }

    public function testADelayedPaymentThatFailedReopensThePaymentLinkAndMails(): void
    {
        $stored = $this->storedSession();
        $stored->state = CheckoutSessionStates::Pending;
        $paymentLink = $this->paymentLink($stored->prospectiveMember);
        $paymentLink->used = true;

        $checkoutSessionRepository = $this->createMock(CheckoutSessionRepository::class);
        $checkoutSessionRepository->method('findById')->willReturn($stored);
        $checkoutSessionRepository->expects(self::atLeastOnce())->method('persist')->with($stored);

        $memberService = $this->createMock(MemberService::class);
        $memberService->expects(self::once())
            ->method('sendRegistrationUpdateEmail')
            ->with(
                $stored->prospectiveMember,
                RegistrationUpdate::CheckoutFailed,
            );

        $this->service(
            $checkoutSessionRepository,
            $this->stubPaymentLinkRepository($paymentLink),
            $memberService,
        )->handleEvent($this->checkoutEvent(Event::CHECKOUT_SESSION_ASYNC_PAYMENT_FAILED));

        self::assertSame(
            CheckoutSessionStates::Failed,
            $stored->state,
        );
        self::assertFalse($paymentLink->used);
    }

    /**
     * An abandoned checkout gets a recovery URL that lives for 30 days, so the expiration moves to the recovery's
     * deadline and the payment link is re-enabled. This happens once, hence the one e-mail.
     */
    public function testAnExpiredCheckoutWithRecoveryKeepsTheRecoveryUrlAndMails(): void
    {
        $stored = $this->storedSession();
        $paymentLink = $this->paymentLink($stored->prospectiveMember);
        $paymentLink->used = true;

        $recoveryExpiresAt = new DateTimeImmutable('+30 days')->getTimestamp();

        $checkoutSessionRepository = $this->createMock(CheckoutSessionRepository::class);
        $checkoutSessionRepository->method('findById')->willReturn($stored);
        $checkoutSessionRepository->expects(self::atLeastOnce())->method('persist')->with($stored);

        $memberService = $this->createMock(MemberService::class);
        $memberService->expects(self::once())
            ->method('sendRegistrationUpdateEmail')
            ->with(
                $stored->prospectiveMember,
                RegistrationUpdate::CheckoutExpired,
            );

        $this->service(
            $checkoutSessionRepository,
            $this->stubPaymentLinkRepository($paymentLink),
            $memberService,
        )->handleEvent($this->checkoutEvent(
            Event::CHECKOUT_SESSION_EXPIRED,
            [
                'after_expiration' => [
                    'recovery' => [
                        'expires_at' => $recoveryExpiresAt,
                        'url' => 'https://checkout.stripe.test/recover',
                    ],
                ],
            ],
        ));

        self::assertSame(
            CheckoutSessionStates::Expired,
            $stored->state,
        );
        self::assertSame(
            $recoveryExpiresAt,
            $stored->expiration->getTimestamp(),
        );
        self::assertSame(
            'https://checkout.stripe.test/recover',
            $stored->getRecoveryUrl(),
        );
        self::assertFalse($paymentLink->used);
    }

    /**
     * Without a recovery the checkout is simply abandoned and there is nothing to tell the prospective member.
     */
    public function testAnExpiredCheckoutWithoutRecoveryKeepsItsExpirationAndSendsNothing(): void
    {
        $stored = $this->storedSession();
        $expiration = $stored->expiration;

        $checkoutSessionRepository = $this->createMock(CheckoutSessionRepository::class);
        $checkoutSessionRepository->method('findById')->willReturn($stored);
        $checkoutSessionRepository->expects(self::atLeastOnce())->method('persist')->with($stored);

        $memberService = $this->createMock(MemberService::class);
        $memberService->expects(self::never())->method('sendRegistrationUpdateEmail');

        $this->service(
            $checkoutSessionRepository,
            memberService: $memberService,
        )
            ->handleEvent($this->checkoutEvent(Event::CHECKOUT_SESSION_EXPIRED));

        self::assertSame(
            CheckoutSessionStates::Expired,
            $stored->state,
        );
        self::assertSame(
            $expiration,
            $stored->expiration,
        );
        self::assertNull($stored->getRecoveryUrl());
    }

    /**
     * A session the register does not know and that does not point back at one was never ours to update: the
     * prospective member has been removed.
     */
    public function testAnUnknownCheckoutSessionThatWasNotRecoveredIsIgnored(): void
    {
        $checkoutSessionRepository = $this->createMock(CheckoutSessionRepository::class);
        $checkoutSessionRepository->method('findById')->willReturn(null);
        $checkoutSessionRepository->expects(self::never())->method('persist');

        $this->service($checkoutSessionRepository)->handleEvent($this->checkoutEvent(
            Event::CHECKOUT_SESSION_COMPLETED,
            ['payment_status' => 'paid'],
            'cs_unknown',
        ));
    }

    /**
     * The recovered session points at an original that is no longer there, so the prospective member was removed.
     */
    public function testARecoveredCheckoutWhoseOriginalIsGoneIsIgnored(): void
    {
        $checkoutSessionRepository = $this->createMock(CheckoutSessionRepository::class);
        $checkoutSessionRepository->method('findById')->willReturn(null);
        $checkoutSessionRepository->expects(self::never())->method('persist');

        $this->service($checkoutSessionRepository)->handleEvent($this->checkoutEvent(
            Event::CHECKOUT_SESSION_COMPLETED,
            [
                'payment_status' => 'paid',
                'recovered_from' => 'cs_original',
            ],
            'cs_recovered',
        ));
    }

    /**
     * Once a recovery has been paid, later events about the same original session cannot be a second payment.
     */
    public function testARecoveryAfterAPaidRecoveryIsIgnored(): void
    {
        $original = $this->storedSession('cs_original');
        $paidRecovery = $this->storedSession('cs_paid');
        $paidRecovery->state = CheckoutSessionStates::Paid;
        $original->getRecoveredBy()->add($paidRecovery);

        $checkoutSessionRepository = $this->createMock(CheckoutSessionRepository::class);
        $checkoutSessionRepository->method('findById')->willReturnCallback(
            static fn (string $id): ?CheckoutSession => match ($id) {
                'cs_original' => $original,
                default => null,
            },
        );
        $checkoutSessionRepository->expects(self::never())->method('persist');

        $this->service($checkoutSessionRepository)->handleEvent($this->checkoutEvent(
            Event::CHECKOUT_SESSION_COMPLETED,
            [
                'payment_status' => 'paid',
                'recovered_from' => 'cs_original',
            ],
            'cs_recovered',
        ));
    }

    /**
     * The first event about a recovered session creates the register's copy, linked to the original and to the same
     * prospective member.
     */
    public function testARecoveredCheckoutIsStoredLinkedToTheOriginal(): void
    {
        $original = $this->storedSession('cs_original');

        $persisted = [];
        $checkoutSessionRepository = self::createStub(CheckoutSessionRepository::class);
        $checkoutSessionRepository->method('findById')->willReturnCallback(
            static fn (string $id): ?CheckoutSession => 'cs_original' === $id ? $original : null,
        );
        $checkoutSessionRepository->method('persist')->willReturnCallback(
            static function (CheckoutSession $session) use (&$persisted): void {
                $persisted[] = $session;
            },
        );

        $this->service($checkoutSessionRepository)->handleEvent($this->checkoutEvent(
            Event::CHECKOUT_SESSION_COMPLETED,
            [
                'payment_status' => 'paid',
                'payment_intent' => 'pi_recovered',
                'recovered_from' => 'cs_original',
            ],
            'cs_recovered',
        ));

        // The newly created session is stored, and the same object is stored again when the event is handled.
        self::assertCount(
            2,
            $persisted,
        );
        self::assertSame(
            $persisted[0],
            $persisted[1],
        );
        $recovered = $persisted[0];
        self::assertSame(
            'cs_recovered',
            $recovered->checkoutId,
        );
        self::assertSame(
            $original,
            $recovered->getRecoveredFrom(),
        );
        self::assertSame(
            $original->prospectiveMember,
            $recovered->prospectiveMember,
        );
        self::assertSame(
            CheckoutSessionStates::Paid,
            $recovered->state,
        );
        self::assertSame(
            'pi_recovered',
            $recovered->paymentIntentId,
        );
    }

    public function testAnEventOfAnUnhandledTypeIsRejected(): void
    {
        $service = $this->service();

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIsOrContains('Unhandled event type, got invoice.paid');

        $service->handleEvent(Event::constructFrom([
            'id' => 'evt_test',
            'object' => 'event',
            'type' => 'invoice.paid',
            'data' => [
                'object' => [
                    'object' => 'invoice',
                    'id' => 'in_test',
                ],
            ],
        ]));
    }

    private function service(
        ?CheckoutSessionRepository $checkoutSessionRepository = null,
        ?ActionLinkRepository $actionLinkRepository = null,
        ?MemberService $memberService = null,
    ): StripeService {
        return new StripeService(
            self::createStub(LoggerInterface::class),
            $actionLinkRepository ?? self::createStub(ActionLinkRepository::class),
            $checkoutSessionRepository ?? self::createStub(CheckoutSessionRepository::class),
            $memberService ?? self::createStub(MemberService::class),
            '2024-06-20',
            'sk_test',
            self::SECRET,
            'price_test',
            'https://join.gewis.test/cancel',
            'https://join.gewis.test/success',
        );
    }

    private function storedSession(string $checkoutId = 'cs_test'): CheckoutSession
    {
        $session = new CheckoutSession();
        $session->checkoutId = $checkoutId;
        $session->prospectiveMember = new ProspectiveMember();
        $session->created = new DateTimeImmutable('-1 hour');
        $session->expiration = new DateTimeImmutable('+1 hour');

        return $session;
    }

    private function paymentLink(ProspectiveMember $prospectiveMember): PaymentLink
    {
        $paymentLink = new PaymentLink();
        $paymentLink->prospectiveMember = $prospectiveMember;

        return $paymentLink;
    }

    private function stubPaymentLinkRepository(
        PaymentLink $paymentLink,
    ): ActionLinkRepository {
        $actionLinkRepository = self::createStub(ActionLinkRepository::class);
        $actionLinkRepository->method('findPaymentByProspectiveMember')->willReturn($paymentLink);

        return $actionLinkRepository;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function checkoutEvent(
        string $type,
        array $fields = [],
        string $sessionId = 'cs_test',
    ): Event {
        return Event::constructFrom([
            'id' => 'evt_test',
            'object' => 'event',
            'type' => $type,
            'data' => [
                'object' => StripeCheckoutSession::constructFrom([
                    'id' => $sessionId,
                    'object' => 'checkout.session',
                    'client_reference_id' => '8000',
                    'created' => new DateTimeImmutable('-1 hour')->getTimestamp(),
                    'expires_at' => new DateTimeImmutable('+1 hour')->getTimestamp(),
                    'recovered_from' => null,
                    'after_expiration' => null,
                    ...$fields,
                ]),
            ],
        ]);
    }

    private function refundEvent(string $status): Event
    {
        return Event::constructFrom([
            'id' => 'evt_test',
            'object' => 'event',
            'type' => Event::CHARGE_REFUND_UPDATED,
            'data' => [
                'object' => Refund::constructFrom([
                    'id' => 're_test',
                    'object' => 'refund',
                    'status' => $status,
                ]),
            ],
        ]);
    }

    private function refundPayload(string $status): string
    {
        return (string) json_encode([
            'id' => 'evt_test',
            'object' => 'event',
            'type' => Event::CHARGE_REFUND_UPDATED,
            'data' => [
                'object' => [
                    'id' => 're_test',
                    'object' => 'refund',
                    'status' => $status,
                ],
            ],
        ]);
    }

    private function completedPayload(): string
    {
        return (string) json_encode([
            'id' => 'evt_test',
            'object' => 'event',
            'type' => Event::CHECKOUT_SESSION_COMPLETED,
            'data' => [
                'object' => [
                    'id' => 'cs_test',
                    'object' => 'checkout.session',
                    'client_reference_id' => '8000',
                    'payment_status' => 'paid',
                ],
            ],
        ]);
    }
}
