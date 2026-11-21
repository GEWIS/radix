<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Database;

use App\Controller\Database\CheckoutController;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The pages a prospective member returns to from the payment provider, the restart link behind the hash, and the
 * webhook. None of these are behind a firewall: the checkout session or the signature is what identifies the caller.
 * Invoked directly, as the rest of the suite does.
 */
final class CheckoutControllerTest extends DatabaseTestCase
{
    public function testTheCompletedPageThanksTheMember(): void
    {
        $this->assertStatusPageContains(
            'completed',
            'Awesome!',
        );
    }

    public function testTheCancelledPageExplainsWhatHappened(): void
    {
        $this->assertStatusPageContains(
            'cancelled',
            'Mistake?',
        );
    }

    public function testTheErrorPageSaysTheCheckoutCouldNotBePrepared(): void
    {
        $this->assertStatusPageContains(
            'error',
            'Oh snap!',
        );
    }

    public function testARestartLinkThatIsNotKnownIsReportedAsSuch(): void
    {
        $this->pushRequest(Request::create('/en/checkout/restart', 'GET'));

        $response = self::getContainer()->get(CheckoutController::class)->restart(
            '0123456789abcdef0123456789abcdef.'
            . '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'This payment link could not be found.',
            (string) $response->getContent(),
        );
    }

    public function testAResumeWithoutAHashIsReportedAsSuch(): void
    {
        $request = Request::create(
            '/en/checkout/restart',
            'GET',
        );
        $this->pushRequest($request);

        $response = self::getContainer()->get(CheckoutController::class)->restartResume($request);

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'This payment link could not be found.',
            (string) $response->getContent(),
        );
    }

    private function pushRequest(Request $request): void
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        $request->setLocale('en');
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);
    }

    public function testAWebhookWithoutASignatureIsRefused(): void
    {
        $response = self::getContainer()->get(CheckoutController::class)->webhook(
            Request::create(
                '/checkout/webhook',
                'POST',
                content: '{"type":"checkout.session.completed"}',
            ),
        );

        self::assertSame(
            Response::HTTP_BAD_REQUEST,
            $response->getStatusCode(),
        );
    }

    private function assertStatusPageContains(
        string $action,
        string $needle,
    ): void {
        $request = Request::create(
            '/en/checkout/' . $action,
            'GET',
        );
        $this->pushRequest($request);

        $controller = self::getContainer()->get(CheckoutController::class);
        $response = match ($action) {
            'completed' => $controller->completed($request),
            'cancelled' => $controller->cancelled($request),
            'error' => $controller->error($request),
            default => self::fail('Unknown checkout status page ' . $action),
        };

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            $needle,
            (string) $response->getContent(),
        );
    }
}
