<?php

declare(strict_types=1);

namespace App\Controller\Database;

use App\Controller\Application\NoLeakHeadersTrait;
use App\Entity\Database\PaymentLink;
use App\Service\Database\ActionLinkService;
use App\Service\Database\CheckoutRestartFailure;
use App\Service\Database\RegistrationService;
use App\Service\Database\StripeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function is_string;

/**
 * Where the payment provider drops a prospective member off: the pages they return to after the checkout, the link
 * that puts them back on it, and the webhook that tells us what actually happened.
 *
 * The webhook keeps both of its plain addresses because Stripe calls it from what is configured in their dashboard.
 * The pages carry a language like every page somebody reads; the addresses they used to answer at are declared in
 * config/routes.yaml and redirect here.
 */
final class CheckoutController extends AbstractController
{
    use NoLeakHeadersTrait;

    public function __construct(
        private readonly RegistrationService $registrationService,
        private readonly StripeService $stripeService,
        private readonly ActionLinkService $actionLinkService,
    ) {
    }

    #[Route(
        path: '/{_locale}/checkout/completed',
        name: 'join_checkout_completed',
        requirements: ['_locale' => '%app.locales%'],
        defaults: ['_locale' => '%kernel.default_locale%'],
        methods: ['GET'],
    )]
    public function completed(Request $request): Response
    {
        return $this->renderStatus(
            'completed',
            $request,
        );
    }

    #[Route(
        path: '/{_locale}/checkout/cancelled',
        name: 'join_checkout_cancelled',
        requirements: ['_locale' => '%app.locales%'],
        defaults: ['_locale' => '%kernel.default_locale%'],
        methods: ['GET'],
    )]
    public function cancelled(Request $request): Response
    {
        return $this->renderStatus(
            'cancelled',
            $request,
        );
    }

    #[Route(
        path: '/{_locale}/checkout/error',
        name: 'join_checkout_error',
        requirements: ['_locale' => '%app.locales%'],
        defaults: ['_locale' => '%kernel.default_locale%'],
        methods: ['GET'],
    )]
    public function error(Request $request): Response
    {
        return $this->renderStatus(
            'failed',
            $request,
        );
    }

    #[Route(
        path: '/{_locale}/checkout/restart/{token}',
        name: 'join_checkout_restart',
        requirements: [
            '_locale' => '%app.locales%',
            'token' => '[0-9a-f]{32}\.[0-9a-f]{96}',
        ],
        defaults: ['_locale' => '%kernel.default_locale%'],
        methods: ['GET'],
    )]
    public function restart(string $token): Response
    {
        $paymentLink = $this->actionLinkService->resolvePayment($token);

        if (null === $paymentLink) {
            return $this->render(
                'database/join/checkout-restart.html.twig',
                ['error' => false],
            );
        }

        return $this->withNoLeakHeaders($this->redirectToRoute(
            'join_checkout_restart_resume',
            ['th' => $this->actionLinkService->claim($paymentLink)],
        ));
    }

    #[Route(
        path: '/{_locale}/checkout/restart',
        name: 'join_checkout_restart_resume',
        requirements: ['_locale' => '%app.locales%'],
        defaults: ['_locale' => '%kernel.default_locale%'],
        methods: ['GET'],
    )]
    public function restartResume(Request $request): Response
    {
        $tempHash = (string) $request->query->get(
            'th',
            '',
        );
        $paymentLink = '' === $tempHash
            ? null
            : $this->actionLinkService->findByTempHash($tempHash);

        if (!$paymentLink instanceof PaymentLink) {
            return $this->render(
                'database/join/checkout-restart.html.twig',
                ['error' => false],
            );
        }

        // Single-use: spent before the checkout reopens, so a hash seen twice is acted on once.
        $this->actionLinkService->consumeTempHash($paymentLink);

        $restart = $this->registrationService->restartCheckout($paymentLink);

        if (is_string($restart)) {
            return $this->withNoLeakHeaders($this->redirect(
                $restart,
                Response::HTTP_SEE_OTHER,
            ));
        }

        return $this->render(
            'database/join/checkout-restart.html.twig',
            [
                'error' => CheckoutRestartFailure::CheckoutUnavailable === $restart,
            ],
        );
    }

    /**
     * Stripe calls this server-to-server, without a session and without a token: the signature over the body is the
     * only thing that says the call is theirs, so nothing else happens until it has been verified.
     */
    #[Route(
        path: '/checkout/webhook',
        name: 'join_checkout_webhook_short',
        methods: ['POST'],
    )]
    #[Route(
        path: '/member/subscribe/checkout/webhook',
        name: 'join_checkout_webhook',
        methods: ['POST'],
    )]
    public function webhook(Request $request): Response
    {
        $accepted = $this->stripeService->handleWebhook(
            $request->getContent(),
            $request->headers->get('Stripe-Signature'),
        );

        return new Response(status: $accepted ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST);
    }

    /**
     * The prospective member is identified by the Checkout Session that Stripe puts in the return URL; it is not
     * known when they arrive here by hand or when the session has since gone.
     */
    private function renderStatus(
        string $status,
        Request $request,
    ): Response {
        $prospectiveMember = $this->registrationService->getProspectiveMemberByCheckoutSession(
            (string) $request->query->get(
                'stripe_session_id',
                '',
            ),
        );
        // Only a hash is kept, so handing out a way back mints a new token and the previous link dies.
        $paymentLink = $prospectiveMember?->getPaymentLink();

        return $this->render(
            'database/join/checkout-status.html.twig',
            [
                'status' => $status,
                'first_name' => $prospectiveMember?->getFirstName(),
                'restart_url' => null === $paymentLink
                    ? null
                    : $this->generateUrl(
                        'join_checkout_restart',
                        ['token' => $this->actionLinkService->reissue($paymentLink)],
                    ),
            ],
        );
    }
}
