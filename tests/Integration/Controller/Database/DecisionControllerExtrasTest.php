<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Database;

use App\Controller\Database\DecisionController;
use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The decision register's remaining actions: the lookups behind the pickers, the page that offers every decision form
 * for a place that is still free, the export, and the translation page's pagination boundaries.
 */
final class DecisionControllerExtrasTest extends DatabaseTestCase
{
    public function testTheDecisionSearchReturnsJson(): void
    {
        $this->authenticate();
        $this->pushRequest();

        $response = $this->controller()->search(Request::create(
            '/en/meetings/decisions/search',
            'GET',
            ['q' => 'besluit'],
        ));

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
    }

    public function testAPlaceThatAlreadyHasADecisionSaysSo(): void
    {
        $this->authenticate();
        $this->pushRequest();

        $response = $this->controller()->create(
            MeetingTypes::BV,
            1,
            1,
            1,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Decision Already Exists',
            (string) $response->getContent(),
        );
    }

    public function testAnUnknownFormNameIsNotFound(): void
    {
        $this->authenticate();
        $this->pushRequest();

        $this->expectException(NotFoundHttpException::class);

        $this->controller()->form(
            new Request(),
            'not_a_decision',
        );
    }

    public function testTheFormOfADecisionRendersForANewEntry(): void
    {
        $this->authenticate();
        $this->pushRequest();

        $response = $this->controller()->form(
            new Request(),
            'other',
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'other[contentNL]',
            (string) $response->getContent(),
        );
    }

    public function testAnEmptyDecisionSubmissionIsRejected(): void
    {
        $this->authenticate();
        $request = Request::create(
            '/en/meetings/decisions/form/other',
            'POST',
            ['other' => ['_csrf_token' => 'csrf-token']],
        );
        $request->headers->set(
            'Sec-Fetch-Site',
            'same-origin',
        );
        $this->pushRequest($request);

        $response = $this->controller()->form(
            $request,
            'other',
        );

        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $response->getStatusCode(),
        );
    }

    public function testTheExportFormRenders(): void
    {
        $this->authenticate();
        $this->pushRequest();

        $response = $this->controller()->export(new Request());

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Pick the meetings to include',
            (string) $response->getContent(),
        );
    }

    public function testExportingAMeetingProducesTheLatexDocument(): void
    {
        $this->authenticate();
        $request = Request::create(
            '/en/meetings/export',
            'POST',
            [
                'export' => [
                    '_csrf_token' => 'csrf-token',
                    'meetings' => ['BV-1'],
                ],
            ],
        );
        $request->headers->set(
            'Sec-Fetch-Site',
            'same-origin',
        );
        $this->pushRequest($request);

        $response = $this->controller()->export($request);

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Paste this into a LaTeX editor',
            (string) $response->getContent(),
        );
    }

    public function testAPageSizeThatIsNotOfferedIsIgnored(): void
    {
        $this->authenticate();
        $this->pushRequest();

        $response = $this->controller()->translations(
            1,
            7,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
    }

    public function testAPageBeyondTheLastOneIsBroughtBack(): void
    {
        $this->authenticate();
        $this->pushRequest();

        $response = $this->controller()->translations(9999);

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            '/meetings/decisions/translations',
            self::redirectTarget($response),
        );
    }

    public function testTranslatingASubdecisionThatIsNotWaitingIsNotFound(): void
    {
        $this->authenticate();
        $this->pushRequest();

        $this->expectException(NotFoundHttpException::class);

        $this->controller()->translate(
            new Request(),
            MeetingTypes::BV,
            1,
            999,
            1,
            1,
        );
    }

    private function controller(): DecisionController
    {
        return self::getContainer()->get(DecisionController::class);
    }

    private function pushRequest(?Request $request = null): void
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        $request ??= new Request();
        $request->setLocale('en');
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);
    }

    private function authenticate(): void
    {
        $user = $this->entityManager->getRepository(User::class)->find(8002);
        self::assertInstanceOf(
            User::class,
            $user,
        );

        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken(
                $user,
                'main',
                [
                    UserRoles::Board->value,
                    UserRoles::DatabaseAdmin->value,
                ],
            ),
        );
    }
}
