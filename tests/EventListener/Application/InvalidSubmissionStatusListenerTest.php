<?php

declare(strict_types=1);

namespace App\Tests\EventListener\Application;

use App\Controller\Database\DecisionController;
use App\EventListener\Application\InvalidSubmissionStatusListener;
use App\Service\Application\LiveComponentAction;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Turbo refuses to render a 200 response to a form submission, which leaves the errors invisible. A rendered response
 * to a submission is a rejection, so it is given the status that says so.
 */
final class InvalidSubmissionStatusListenerTest extends KernelTestCase
{
    public function testARejectedSubmissionIsGiven422(): void
    {
        $response = $this->listen(Request::create('/en/admin/pages/create', 'POST'));

        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $response->getStatusCode(),
        );
    }

    public function testAReadIsNeverARejectedSubmission(): void
    {
        $response = $this->listen(Request::create('/en/admin/pages/create', 'GET'));

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
    }

    public function testAResponseThatIsNotARejectionIsLeftAlone(): void
    {
        $response = $this->listen(
            Request::create(
                '/en/admin/pages/create',
                'POST',
            ),
            new Response(
                'created',
                Response::HTTP_CREATED,
                ['Content-Type' => 'text/html'],
            ),
        );

        self::assertSame(
            Response::HTTP_CREATED,
            $response->getStatusCode(),
        );
    }

    public function testADownloadIsNotARenderedPage(): void
    {
        $response = $this->listen(
            Request::create(
                '/en/admin/pages/create',
                'POST',
            ),
            new Response(
                '',
                Response::HTTP_OK,
                ['Content-Type' => 'application/pdf'],
            ),
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
    }

    public function testAnEmptyBodyIsNotARenderedPage(): void
    {
        $response = $this->listen(
            Request::create(
                '/en/admin/pages/create',
                'POST',
            ),
            new Response(
                '',
                Response::HTTP_OK,
                ['Content-Type' => 'text/html'],
            ),
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
    }

    /**
     * A live component returns a rendered 200 for every action, including the ones that write.
     */
    public function testALiveComponentResponseIsLeftAlone(): void
    {
        $request = Request::create(
            '/_components',
            'POST',
        );
        $request->attributes->set(
            '_route',
            'ux_live_component',
        );

        $response = $this->listen($request);

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
    }

    /**
     * The few actions that render on success declare it, and state their own status.
     */
    public function testAnActionThatRendersOnSuccessIsLeftAlone(): void
    {
        $request = Request::create(
            '/en/database/decisions/export',
            'POST',
        );
        $request->attributes->set(
            '_controller',
            DecisionController::class . '::export',
        );

        $response = $this->listen($request);

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
    }

    public function testASubRequestIsLeftAlone(): void
    {
        $response = $this->listen(
            Request::create(
                '/en/admin/pages/create',
                'POST',
            ),
            type: HttpKernelInterface::SUB_REQUEST,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
    }

    private function listen(
        Request $request,
        ?Response $response = null,
        int $type = HttpKernelInterface::MAIN_REQUEST,
    ): Response {
        $response ??= new Response(
            'rendered page',
            Response::HTTP_OK,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );

        $event = new ResponseEvent(
            self::createStub(HttpKernelInterface::class),
            $request,
            $type,
            $response,
        );

        self::bootKernel();

        $liveComponentAction = self::getContainer()->get(LiveComponentAction::class);
        self::assertInstanceOf(
            LiveComponentAction::class,
            $liveComponentAction,
        );

        $listener = new InvalidSubmissionStatusListener($liveComponentAction);
        $listener($event);

        return $response;
    }
}
