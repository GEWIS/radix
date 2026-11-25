<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Career;

use App\Controller\Career\CompanyProfileController;
use App\Entity\Application\Enums\RevisionStatus;
use App\Entity\Career\CompanyRevision;
use App\Entity\Career\CompanyRevisionComment;
use App\Entity\User\CompanyUser;
use App\Repository\Career\CompanyRevisionCommentRepository;
use App\Repository\User\CompanyUserRepository;
use App\Service\Application\EditLockService;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

use function json_decode;

/**
 * A representative writing their company's profile through the stepper, and everything the profile screen offers once a
 * draft exists: submit, discard, discuss, and recover from a profile that has gone stale.
 */
final class CompanyProfileFlowTest extends DatabaseTestCase
{
    private const string RUN = 'company-profile-run';

    public function testAStepThatDoesNotHoldComesBackWithWhatWasWrong(): void
    {
        $companyUser = $this->signIn();
        $session = $this->open();
        $this->controller()->revise($companyUser);

        $response = $this->controller()->edit(
            $this->post(
                'profile',
                [
                    'languageEnglish' => '1',
                    'sloganEN' => 'Building things',
                    'websiteEN' => 'https://example.com/nexunt',
                    'descriptionEN' => '   ',
                ],
                'next',
                $session,
            ),
            $companyUser,
        );

        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Fill in the English text.',
            (string) $response->getContent(),
        );
    }

    public function testACompleteStepperSavesTheDraftWithoutTouchingWhatIsLive(): void
    {
        $companyUser = $this->signIn();
        $session = $this->open();
        $company = $companyUser->company;
        $live = $company->getLiveRevision();
        self::assertInstanceOf(
            CompanyRevision::class,
            $live,
        );

        $this->controller()->revise($companyUser);
        $draft = $company->getCurrentRevision();
        self::assertInstanceOf(
            CompanyRevision::class,
            $draft,
        );

        $this->controller()->edit(
            $this->post(
                'profile',
                [
                    'languageEnglish' => '1',
                    'sloganEN' => 'We build even more',
                    'websiteEN' => 'https://example.com/nexunt-new',
                    'descriptionEN' => 'A rewritten profile.',
                ],
                'next',
                $session,
            ),
            $companyUser,
        );
        $this->controller()->edit(
            $this->post(
                'contact',
                [
                    'contactName' => 'New Contact',
                    'contactEmail' => 'new-contact@nexunt.example.com',
                    'contactPhone' => '+31 40 111 1111',
                    'contactAddress' => 'Eindhoven',
                ],
                'next',
                $session,
            ),
            $companyUser,
        );
        $response = $this->controller()->edit(
            $this->post(
                'logo',
                [],
                'finish',
                $session,
            ),
            $companyUser,
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            '/company/profile/status',
            $response->headers->get('Location') ?? '',
        );
        self::assertSame(
            'We build even more',
            $draft->slogan->getValueEN(),
        );
        self::assertSame(
            'A rewritten profile.',
            $draft->description->getValueEN(),
        );
        self::assertSame(
            'New Contact',
            $draft->contactName,
        );
        self::assertSame(
            $companyUser->getDisplayName(),
            $draft->getLastEditorDisplayName(),
        );
        // Review is the only way a draft reaches visitors.
        self::assertSame(
            $live,
            $company->getLiveRevision(),
        );
        self::assertSame(
            RevisionStatus::Draft,
            $draft->getStatus(),
        );
    }

    public function testAProfileAnotherRepresentativeIsEditingIsNotHandedOver(): void
    {
        $companyUser = $this->signIn();
        $this->open();
        $this->controller()->revise($companyUser);

        self::getContainer()->get(EditLockService::class)->acquire(
            $companyUser->company,
            $this->representative('talent@nexunt.example.com'),
        );

        $response = $this->controller()->edit(
            $this->get(),
            $companyUser,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Already being edited',
            (string) $response->getContent(),
        );
    }

    public function testOpeningTheEditorStartsARun(): void
    {
        $companyUser = $this->signIn();
        $this->open();
        $this->controller()->revise($companyUser);

        $request = new Request();
        $request->attributes->set(
            '_route',
            'company/profile/edit',
        );
        $request->attributes->set(
            '_route_params',
            [],
        );

        $response = $this->controller()->edit(
            $request,
            $companyUser,
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertStringContainsString(
            'flow=',
            $response->getTargetUrl(),
        );
    }

    public function testAProfileWithoutACurrentRevisionHasNothingToEdit(): void
    {
        $companyUser = $this->signIn();
        $this->open();

        $company = $companyUser->company;
        $company->setCurrentRevision(null);
        $this->entityManager->flush();

        $this->expectException(NotFoundHttpException::class);
        $this->controller()->edit(
            $this->get(),
            $companyUser,
        );
    }

    public function testRevisingADraftThatIsAlreadyOpenLeadsToIt(): void
    {
        $companyUser = $this->signIn();
        $this->open();
        $this->controller()->revise($companyUser);

        $response = $this->controller()->revise($companyUser);

        self::assertStringContainsString(
            '/company/profile/edit',
            $response->headers->get('Location') ?? '',
        );
    }

    public function testRevisingWhileTheCommitteeHasItIsRefused(): void
    {
        $companyUser = $this->signIn();
        $session = $this->open();

        $company = $companyUser->company;
        $company->getCurrentRevision()?->setStatus(RevisionStatus::Submitted);
        $this->entityManager->flush();

        $response = $this->controller()->revise($companyUser);

        self::assertStringContainsString(
            '/company/profile/status',
            $response->headers->get('Location') ?? '',
        );
        self::assertNotEmpty($session->getFlashBag()->peek('warning'));
    }

    public function testAProfileTheCommitteeClosedCannotBeRevised(): void
    {
        $companyUser = $this->signIn();
        $session = $this->open();

        $company = $companyUser->company;
        $company->getCurrentRevision()?->setStatus(RevisionStatus::Closed);
        $this->entityManager->flush();

        $response = $this->controller()->revise($companyUser);

        self::assertStringContainsString(
            '/company/profile',
            $response->headers->get('Location') ?? '',
        );
        self::assertNotEmpty($session->getFlashBag()->peek('warning'));
    }

    public function testAProfileWithoutACurrentRevisionCannotBeRevised(): void
    {
        $companyUser = $this->signIn();
        $this->open();

        $companyUser->company->setCurrentRevision(null);
        $this->entityManager->flush();

        $this->expectException(NotFoundHttpException::class);
        $this->controller()->revise($companyUser);
    }

    public function testSubmittingADraftHandsItToTheCommittee(): void
    {
        $companyUser = $this->signIn();
        $session = $this->open();
        $this->controller()->revise($companyUser);
        $draft = $companyUser->company->getCurrentRevision();
        self::assertInstanceOf(
            CompanyRevision::class,
            $draft,
        );

        $response = $this->controller()->decide(
            $this->decision($session),
            $companyUser,
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertSame(
            RevisionStatus::Submitted,
            $draft->getStatus(),
        );
        self::assertNotEmpty($session->getFlashBag()->peek('success'));
    }

    public function testTheDiscussionIsRecordedOnTheDraft(): void
    {
        $companyUser = $this->signIn();
        $this->open();
        $this->controller()->revise($companyUser);

        $this->controller()->comment(
            new Request(request: ['message' => 'Could we keep the old slogan?']),
            $companyUser,
        );

        $comments = self::getContainer()->get(CompanyRevisionCommentRepository::class)
            ->findThreadForCompany($companyUser->company);
        self::assertNotEmpty($comments);
        self::assertInstanceOf(
            CompanyRevisionComment::class,
            $comments[0],
        );
        self::assertSame(
            'Could we keep the old slogan?',
            $comments[0]->body,
        );
    }

    public function testAProfileWithoutACurrentRevisionHasNothingToDiscuss(): void
    {
        $companyUser = $this->signIn();
        $this->open();

        $companyUser->company->setCurrentRevision(null);
        $this->entityManager->flush();

        $this->expectException(NotFoundHttpException::class);
        $this->controller()->comment(
            new Request(request: ['message' => 'Hello?']),
            $companyUser,
        );
    }

    public function testDiscardingADraftRestoresTheLiveProfile(): void
    {
        $companyUser = $this->signIn();
        $session = $this->open();
        $company = $companyUser->company;
        $live = $company->getLiveRevision();
        $this->controller()->revise($companyUser);
        $draft = $company->getCurrentRevision();
        self::assertInstanceOf(
            CompanyRevision::class,
            $draft,
        );
        $draftId = (int) $draft->id;

        $response = $this->controller()->discard($companyUser);

        self::assertStringContainsString(
            '/company/profile',
            $response->headers->get('Location') ?? '',
        );
        self::assertSame(
            $live,
            $company->getCurrentRevision(),
        );
        self::assertNull(
            $this->entityManager->getRepository(CompanyRevision::class)->find($draftId),
        );
        self::assertNotEmpty($session->getFlashBag()->peek('success'));
    }

    /**
     * The very first draft of a profile that was never live is a deletion when thrown away, so it is refused.
     */
    public function testAFirstDraftCannotBeDiscarded(): void
    {
        $companyUser = $this->signIn();
        $session = $this->open();
        $company = $companyUser->company;
        $company->setLiveRevision(null);
        $this->controller()->revise($companyUser);

        $response = $this->controller()->discard($companyUser);

        self::assertStringContainsString(
            '/company/profile/status',
            $response->headers->get('Location') ?? '',
        );
        self::assertNotEmpty($session->getFlashBag()->peek('warning'));
        self::assertNotNull($company->getCurrentRevision());
    }

    public function testTheBrowserKeepsAndDropsTheLock(): void
    {
        $companyUser = $this->signIn();
        $this->open();
        $this->controller()->revise($companyUser);
        self::getContainer()->get(EditLockService::class)->acquire(
            $companyUser->company,
            $companyUser,
        );

        $ping = $this->controller()->editPing($companyUser);
        self::assertSame(
            ['held' => true],
            json_decode(
                (string) $ping->getContent(),
                true,
            ),
        );

        $release = $this->controller()->editRelease($companyUser);
        self::assertSame(
            ['released' => true],
            json_decode(
                (string) $release->getContent(),
                true,
            ),
        );
    }

    private function representative(string $email): CompanyUser
    {
        $companyUser = self::getContainer()->get(CompanyUserRepository::class)
            ->loadUserByIdentifier($email);
        self::assertInstanceOf(
            CompanyUser::class,
            $companyUser,
        );

        return $companyUser;
    }

    private function controller(): CompanyProfileController
    {
        return self::getContainer()->get(CompanyProfileController::class);
    }

    private function signIn(string $email = 'recruitment@nexunt.example.com'): CompanyUser
    {
        $companyUser = $this->representative($email);

        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken(
            $companyUser,
            'company',
            ['ROLE_COMPANY_USER'],
        ));

        return $companyUser;
    }

    private function open(): FlashBagAwareSessionInterface
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        $this->push(
            $this->get(),
            $session,
        );

        return $session;
    }

    private function get(): Request
    {
        return new Request(query: ['flow' => self::RUN]);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function post(
        string $step,
        array $fields,
        string $button,
        SessionInterface $session,
    ): Request {
        $body = [
            $step => $fields,
            $button => '',
            '_csrf_token' => $this->token(),
        ];

        $request = new Request(
            query: ['flow' => self::RUN],
            request: ['company_profile_flow' => $body],
            server: [
                'REQUEST_METHOD' => 'POST',
                'HTTP_SEC_FETCH_SITE' => 'same-origin',
            ],
        );
        $request->setMethod(Request::METHOD_POST);
        $this->push(
            $request,
            $session,
        );

        return $request;
    }

    private function decision(SessionInterface $session): Request
    {
        $request = new Request(
            query: ['flow' => self::RUN],
            request: [
                'review_decision' => [
                    'submit' => '',
                    '_csrf_token' => $this->token(),
                ],
            ],
            server: [
                'REQUEST_METHOD' => 'POST',
                'HTTP_SEC_FETCH_SITE' => 'same-origin',
            ],
        );
        $request->setMethod(Request::METHOD_POST);
        $this->push(
            $request,
            $session,
        );

        return $request;
    }

    private function token(): string
    {
        return self::getContainer()->get(CsrfTokenManagerInterface::class)
            ->getToken('submit')
            ->getValue();
    }

    private function push(
        Request $request,
        SessionInterface $session,
    ): void {
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);
    }
}
