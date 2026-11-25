<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Decision;

use App\Controller\Decision\AdminBodyApprovalController;
use App\Entity\Application\Enums\RevisionStatus;
use App\Entity\Decision\OrganInformationRevision;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Repository\Decision\OrganInformationRevisionCommentRepository;
use App\Repository\Decision\OrganInformationRevisionRepository;
use App\Security\User\SudoMode;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Workflow\Registry;

use function count;
use function sprintf;
use function strval;

/**
 * The board's queue of body pages waiting on it, and the review screen behind each of them. A decision is posted as
 * the review form posts it: the pressed button names the workflow transition, and the shared form makes feedback
 * mandatory for a rejection.
 */
final class AdminBodyApprovalControllerTest extends DatabaseTestCase
{
    public function testTheQueueListsWhatWaitsOnTheBoard(): void
    {
        $this->authenticateBoard();
        $this->pushRequest();

        $response = $this->controller()->index();

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'KEUR',
            (string) $response->getContent(),
        );
    }

    /**
     * Opening a review is a reviewer action, so it needs a live sudo grant on top of the board role.
     */
    public function testTheBoardCanOpenARevisionWaitingOnIt(): void
    {
        $this->authenticateBoard();
        $this->pushRequestWithSudo();

        $revision = $this->revisionIn(RevisionStatus::Submitted);

        $response = $this->controller()->review($revision);

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );

        $content = (string) $response->getContent();
        self::assertStringContainsString(
            $revision->getOrgan()->abbr,
            $content,
        );
        self::assertStringContainsString(
            'Start review',
            $content,
        );
    }

    public function testAnUnrelatedMemberCannotOpenARevision(): void
    {
        $this->authenticate(
            8030,
            [UserRoles::Member->value],
        );
        $this->pushRequest();

        $this->expectException(AccessDeniedException::class);

        $this->controller()->review($this->revisionIn(RevisionStatus::Submitted));
    }

    public function testACommentIsAddedToTheDiscussion(): void
    {
        $this->authenticateBoard();
        $this->pushRequest();

        $revision = $this->revisionIn(RevisionStatus::Submitted);

        $request = Request::create(
            '/',
            'POST',
            ['message' => 'The new description reads well.'],
        );

        $response = $this->controller()->comment(
            $request,
            $revision,
            $this->board(),
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            sprintf(
                '/admin/bodies/approvals/%d',
                $revision->id,
            ),
            self::redirectTarget($response),
        );

        $bodies = [];
        foreach (
            self::getContainer()->get(OrganInformationRevisionCommentRepository::class)
                ->findThreadForOrganInformation($revision->organInformation) as $comment
        ) {
            $bodies[] = $comment->body;
        }

        self::assertContains(
            'The new description reads well.',
            $bodies,
        );
    }

    /**
     * An empty discussion box is not an empty entry: it is simply ignored.
     */
    public function testAnEmptyCommentIsIgnored(): void
    {
        $this->authenticateBoard();
        $this->pushRequest();

        $revision = $this->revisionIn(RevisionStatus::Submitted);
        $before = count(self::getContainer()->get(OrganInformationRevisionCommentRepository::class)
            ->findThreadForOrganInformation($revision->organInformation));

        $response = $this->controller()->comment(
            Request::create(
                '/',
                'POST',
                ['message' => '   '],
            ),
            $revision,
            $this->board(),
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertCount(
            $before,
            self::getContainer()->get(OrganInformationRevisionCommentRepository::class)
                ->findThreadForOrganInformation($revision->organInformation),
        );
    }

    public function testADraftIsDiscardedBackToTheLivePage(): void
    {
        $this->authenticateBoard();
        $this->pushRequest();

        $revision = $this->discardableDraft();
        $id = (int) $revision->id;
        $organId = $revision->getOrgan()->id;

        $response = $this->controller()->discard($revision);

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            sprintf(
                '/admin/bodies/%d',
                $organId,
            ),
            self::redirectTarget($response),
        );
        self::assertNull(
            $this->entityManager->getRepository(OrganInformationRevision::class)->find($id),
        );
    }

    /**
     * Claiming a submission keeps the reviewer on the screen, so they can decide straight away.
     */
    public function testStartingAReviewStaysOnTheScreen(): void
    {
        $revision = $this->revisionIn(RevisionStatus::Submitted);

        $response = $this->decide(
            $revision,
            'start_review',
        );

        self::assertSame(
            RevisionStatus::InReview,
            $revision->getStatus(),
        );
        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            sprintf(
                '/admin/bodies/approvals/%d',
                $revision->id,
            ),
            self::redirectTarget($response),
        );
    }

    /**
     * A body that submits its own draft goes back to its page, where it can see what it just sent.
     */
    public function testSubmittingADraftGoesBackToTheBodysPage(): void
    {
        $revision = $this->discardableDraft();
        $organId = $revision->getOrgan()->id;

        $response = $this->decide(
            $revision,
            'submit',
        );

        self::assertSame(
            RevisionStatus::Submitted,
            $revision->getStatus(),
        );
        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            sprintf(
                '/admin/bodies/%d',
                $organId,
            ),
            self::redirectTarget($response),
        );
    }

    public function testApprovingAPagePublishesIt(): void
    {
        $revision = $this->revisionIn(RevisionStatus::Submitted);
        $this->startReview($revision);

        $response = $this->decide(
            $revision,
            'approve',
        );

        self::assertSame(
            RevisionStatus::Approved,
            $revision->getStatus(),
        );
        self::assertSame(
            $revision,
            $revision->organInformation->getLiveRevision(),
        );
        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringEndsWith(
            '/admin/bodies/approvals',
            self::redirectTarget($response),
        );
    }

    public function testRejectingAPageLeavesTheLivePageAlone(): void
    {
        $revision = $this->revisionIn(RevisionStatus::Submitted);
        $live = $revision->organInformation->getLiveRevision();
        $this->startReview($revision);

        $response = $this->decide(
            $revision,
            'reject',
            'The description does not say what the body does.',
        );

        self::assertSame(
            RevisionStatus::Rejected,
            $revision->getStatus(),
        );
        self::assertSame(
            $live,
            $revision->organInformation->getLiveRevision(),
        );
        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
    }

    /**
     * A rejection has to say why, so one without feedback comes back with the screen instead of deciding.
     */
    public function testRejectingWithoutFeedbackComesBackWithTheScreen(): void
    {
        $revision = $this->revisionIn(RevisionStatus::Submitted);
        $this->startReview($revision);

        $response = $this->decide(
            $revision,
            'reject',
        );

        self::assertSame(
            RevisionStatus::InReview,
            $revision->getStatus(),
        );
        self::assertStringContainsString(
            'Feedback',
            strval($response->getContent()),
        );
    }

    private function controller(): AdminBodyApprovalController
    {
        return self::getContainer()->get(AdminBodyApprovalController::class);
    }

    private function decide(
        OrganInformationRevision $revision,
        string $transition,
        string $message = '',
    ): Response {
        $parameters = [
            'review_decision' => [
                $transition => '',
                '_csrf_token' => 'csrf-token',
            ],
        ];

        if ('' !== $message) {
            $parameters['review_decision']['message'] = $message;
        }

        $request = new Request(
            request: $parameters,
            server: ['HTTP_SEC_FETCH_SITE' => 'same-origin'],
        );
        $request->setMethod(Request::METHOD_POST);

        $this->authenticateBoardWithSudo($request);

        return $this->controller()->decide(
            $request,
            $revision,
            $this->board(),
        );
    }

    private function startReview(OrganInformationRevision $revision): void
    {
        // The transition guards read the signed-in reviewer and their sudo grant, so both have to be in place first.
        $this->authenticateBoard();
        $this->pushRequestWithSudo();

        self::getContainer()->get(Registry::class)->get(
            $revision,
            'revision',
        )->apply(
            $revision,
            'start_review',
        );
        $this->entityManager->flush();
    }

    private function revisionIn(RevisionStatus $status): OrganInformationRevision
    {
        $revision = self::getContainer()->get(OrganInformationRevisionRepository::class)
            ->createQueryBuilder('r')
            ->where('r.status = :status')
            ->setParameter(
                'status',
                $status->value,
            )
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        self::assertInstanceOf(
            OrganInformationRevision::class,
            $revision,
            sprintf(
                'The seed is expected to contain a revision in %s.',
                $status->value,
            ),
        );

        return $revision;
    }

    /**
     * A draft of a page that is already published, which is the only draft that can be thrown away.
     */
    private function discardableDraft(): OrganInformationRevision
    {
        $drafts = self::getContainer()->get(OrganInformationRevisionRepository::class)
            ->createQueryBuilder('r')
            ->where('r.status = :status')
            ->setParameter(
                'status',
                RevisionStatus::Draft->value,
            )
            ->getQuery()
            ->getResult();

        foreach ($drafts as $draft) {
            $live = $draft->organInformation->getLiveRevision();
            if (
                null !== $live
                && $live !== $draft
            ) {
                return $draft;
            }
        }

        self::fail('The seed is expected to contain a draft of a published body page.');
    }

    private function board(): User
    {
        $user = $this->entityManager->getRepository(User::class)->find(8000);
        self::assertInstanceOf(
            User::class,
            $user,
        );

        return $user;
    }

    private function authenticateBoard(): void
    {
        $this->authenticate(
            8000,
            [UserRoles::Board->value],
        );
    }

    /**
     * @param list<string> $roles
     */
    private function authenticate(
        int $lidnr,
        array $roles,
    ): void {
        $user = $this->entityManager->getRepository(User::class)->find($lidnr);
        self::assertInstanceOf(
            User::class,
            $user,
            sprintf(
                'The seed is expected to contain a user for member %d.',
                $lidnr,
            ),
        );

        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken(
                $user,
                'main',
                $roles,
            ),
        );
    }

    private function pushRequest(): Request
    {
        $request = new Request();
        $this->attachSession($request);

        return $request;
    }

    private function pushRequestWithSudo(): Request
    {
        $request = $this->pushRequest();
        $request->cookies->set(
            $request->getSession()->getName(),
            'test',
        );
        self::getContainer()->get(SudoMode::class)->grant();

        return $request;
    }

    /**
     * The decision request is the one the CSRF check and the sudo grant both read, so it is the one that goes on the
     * stack.
     */
    private function authenticateBoardWithSudo(Request $request): void
    {
        $this->authenticateBoard();
        $this->attachSession($request);
        $request->cookies->set(
            $request->getSession()->getName(),
            'test',
        );

        self::getContainer()->get(SudoMode::class)->grant();
    }

    private function attachSession(Request $request): void
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        $request->setLocale('en');
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);
    }
}
