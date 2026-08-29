<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Database;

use App\Controller\Database\DecisionController;
use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Repository\Database\MeetingRepository;
use App\Service\Database\Meeting as MeetingService;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function sprintf;

/**
 * The page that offers every kind of decision for one place in a meeting. It renders a dozen forms at once, three of
 * which hand their collection prototype to a Stimulus controller before the form theme gets to it, so rendering it at
 * all is the thing worth asserting.
 */
final class DecisionControllerTest extends DatabaseTestCase
{
    public function testEveryDecisionFormIsOffered(): void
    {
        $this->authenticateSecretary();
        $this->pushRequest();
        $meeting = $this->latestVirtualMeeting();

        $response = self::getContainer()->get(DecisionController::class)->create(
            MeetingTypes::VIRT,
            $meeting,
            99,
            99,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );

        $content = (string) $response->getContent();

        foreach (
            [
                'tab-organ-install',
                'tab-organ-continuation',
                'tab-board-install',
                'tab-board-candidacy',
                'tab-member-warning',
                'tab-member-suspension',
                'tab-other',
            ] as $kind
        ) {
            self::assertStringContainsString(
                $kind,
                $content,
            );
        }

        foreach (
            [
                'other[contentNL]',
                'other[contentEN]',
            ] as $field
        ) {
            self::assertStringContainsString(
                $field,
                $content,
            );
        }
    }

    public function testOnlyDecisionsWithoutAnEnglishTextAreOfferedForTranslation(): void
    {
        $this->authenticateSecretary();
        $this->pushRequest();

        $response = self::getContainer()->get(DecisionController::class)->translations();

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );

        $content = (string) $response->getContent();

        self::assertStringContainsString(
            'Het bestuur besluit de jaarplanning van GETÉST vast te stellen.',
            $content,
        );
        self::assertStringNotContainsString(
            'Het bestuur besluit de notulen van de vorige bestuursvergadering vast te stellen.',
            $content,
        );
    }

    public function testTranslatingADecisionTakesItOffThePage(): void
    {
        $this->authenticateSecretary();

        $service = self::getContainer()->get(MeetingService::class);
        $waiting = $service->getUntranslatedDecisions(
            1,
            1,
        )['items'];

        self::assertNotEmpty(
            $waiting,
            'The seed is expected to contain a free-text decision without an English text.',
        );

        $subdecision = $waiting[0];
        $name = sprintf(
            'translation_%s_%d_%d_%d_%d',
            $subdecision->getMeetingType()->value,
            $subdecision->getMeetingNumber(),
            $subdecision->getDecisionPoint(),
            $subdecision->getDecisionNumber(),
            $subdecision->getSequence(),
        );

        $request = $this->translationRequest(
            $name,
            'The board decides to buy a cake.',
        );
        // The request the translation arrives on is the one the CSRF check reads.
        $this->pushRequest($request);

        $response = self::getContainer()->get(DecisionController::class)->translate(
            $request,
            $subdecision->getMeetingType(),
            $subdecision->getMeetingNumber(),
            $subdecision->getDecisionPoint(),
            $subdecision->getDecisionNumber(),
            $subdecision->getSequence(),
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertSame(
            'The board decides to buy a cake.',
            $subdecision->getContentEN(),
        );
        self::assertNull($service->getUntranslatedDecision(
            $subdecision->getMeetingType(),
            $subdecision->getMeetingNumber(),
            $subdecision->getDecisionPoint(),
            $subdecision->getDecisionNumber(),
            $subdecision->getSequence(),
        ));
    }

    /** CSRF is stateless here, so a same-origin request is what the manager accepts. */
    private function translationRequest(
        string $name,
        string $contentEN,
    ): Request {
        $request = new Request(
            request: [
                $name => [
                    'contentEN' => $contentEN,
                    '_csrf_token' => 'csrf-token',
                ],
            ],
            server: ['HTTP_SEC_FETCH_SITE' => 'same-origin'],
        );
        $request->setMethod(Request::METHOD_POST);

        return $request;
    }

    /**
     * The page extends the register's layout, which asks the current request for the language it renders in and
     * the session for the language switch.
     */
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

    private function latestVirtualMeeting(): int
    {
        $repository = self::getContainer()->get(MeetingRepository::class);
        $meeting = $repository->findMeeting(
            MeetingTypes::VIRT,
            2,
        );

        self::assertNotNull(
            $meeting,
            'The seed is expected to contain a second virtual meeting.',
        );

        return $meeting->getNumber();
    }

    private function authenticateSecretary(): void
    {
        $user = $this->entityManager->getRepository(User::class)->find(8002);
        self::assertInstanceOf(
            User::class,
            $user,
            'The seed is expected to contain a user that keeps the register.',
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
