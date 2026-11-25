<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Career;

use App\Controller\Career\AdminApprovalController;
use App\Entity\Application\Enums\RevisionStatus;
use App\Entity\Career\CompanyRevision;
use App\Entity\Career\Vacancy;
use App\Entity\Career\VacancyRevision;
use App\Entity\User\User;
use App\Repository\Career\CompanyRevisionCommentRepository;
use App\Repository\Career\VacancyRepository;
use App\Repository\Career\VacancyRevisionCommentRepository;
use App\Security\User\SudoMode;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

use function count;

/**
 * The committee's decisions from the career queue: claiming a submission, approving it, discarding a draft that is not
 * worth submitting, and the discussion that goes with both aggregates.
 */
final class AdminApprovalDecisionTest extends DatabaseTestCase
{
    private SessionInterface $session;

    public function testClaimingASubmissionKeepsTheReviewerOnTheScreen(): void
    {
        $this->signInWithSudo();
        $revision = $this->submittedCompanyRevision();

        $response = $this->controller()->decideCompany(
            $this->decide('start_review'),
            $revision,
            $this->user(),
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            '/admin/career/approvals/company/' . $revision->id,
            $response->headers->get('Location') ?? '',
        );
        self::assertSame(
            RevisionStatus::InReview,
            $revision->getStatus(),
        );
        self::assertNotEmpty(self::flashBag($this->session)->peek('success'));
    }

    public function testApprovingAVacancySendsTheReviewerBackToTheQueue(): void
    {
        $this->signInWithSudo();
        $vacancy = $this->vacancy('controls-engineer');
        $revision = $vacancy->getCurrentRevision();
        self::assertInstanceOf(
            VacancyRevision::class,
            $revision,
        );
        $revision->setStatus(RevisionStatus::InReview);
        $this->entityManager->flush();

        $response = $this->controller()->decideVacancy(
            $this->decide('approve'),
            $revision,
            $this->user(),
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            '/admin/career/approvals',
            $response->headers->get('Location') ?? '',
        );
        self::assertSame(
            RevisionStatus::Approved,
            $revision->getStatus(),
        );
    }

    /**
     * The board may submit a draft itself; the wording differs from a reviewer's verdict, and the queue shows that
     * difference.
     */
    public function testTheBoardCanSubmitADraftFromTheQueue(): void
    {
        $this->signInWithSudo();
        $revision = $this->draftCompanyRevision();

        $response = $this->controller()->decideCompany(
            $this->decide('submit'),
            $revision,
            $this->user(),
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertSame(
            RevisionStatus::Submitted,
            $revision->getStatus(),
        );
        self::assertNotEmpty(self::flashBag($this->session)->peek('success'));
    }

    public function testACompanyThreadTakesTheCommitteeReply(): void
    {
        $this->signInWithSudo();
        $revision = $this->submittedCompanyRevision();

        $response = $this->controller()->commentCompany(
            new Request(request: ['message' => 'Where is the office?']),
            $revision,
            $this->user(),
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            '/admin/career/approvals/company/' . $revision->id,
            $response->headers->get('Location') ?? '',
        );

        $comments = self::getContainer()->get(CompanyRevisionCommentRepository::class)
            ->findThreadForCompany($revision->company);
        self::assertNotEmpty($comments);
        self::assertSame(
            'Where is the office?',
            $comments[count($comments) - 1]->body,
        );
    }

    public function testAVacancyThreadTakesTheCommitteeReply(): void
    {
        $this->signInWithSudo();
        $vacancy = $this->vacancy('controls-engineer');
        $revision = $vacancy->getCurrentRevision();
        self::assertInstanceOf(
            VacancyRevision::class,
            $revision,
        );

        $response = $this->controller()->commentVacancy(
            new Request(request: ['message' => 'Add the salary range.']),
            $revision,
            $this->user(),
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            '/admin/career/approvals/vacancy/' . $revision->id,
            $response->headers->get('Location') ?? '',
        );

        $comments = self::getContainer()->get(VacancyRevisionCommentRepository::class)
            ->findThreadForVacancy($vacancy);
        self::assertNotEmpty($comments);
        self::assertSame(
            'Add the salary range.',
            $comments[count($comments) - 1]->body,
        );
    }

    public function testDiscardingACompanyDraftRestoresTheLiveProfile(): void
    {
        $this->signInWithSudo();
        $revision = $this->draftCompanyRevision();
        $company = $revision->company;
        $live = $company->getLiveRevision();

        $response = $this->controller()->discardCompany($revision);

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            '/admin/career/companies/' . $company->id,
            $response->headers->get('Location') ?? '',
        );
        self::assertSame(
            $live,
            $company->getCurrentRevision(),
        );
        self::assertNull(
            $this->entityManager->getRepository(CompanyRevision::class)->find((int) $revision->id),
        );
    }

    public function testDiscardingAVacancyDraftRestoresTheLiveVacancy(): void
    {
        $this->signInWithSudo();
        $vacancy = $this->vacancy('controls-engineer');
        $revision = $vacancy->getCurrentRevision();
        self::assertInstanceOf(
            VacancyRevision::class,
            $revision,
        );
        $live = $vacancy->getLiveRevision();

        $response = $this->controller()->discardVacancy($revision);

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            '/admin/career/vacancies',
            $response->headers->get('Location') ?? '',
        );
        self::assertSame(
            $live,
            $vacancy->getCurrentRevision(),
        );
        self::assertNull(
            $this->entityManager->getRepository(VacancyRevision::class)->find((int) $revision->id),
        );
    }

    private function submittedCompanyRevision(): CompanyRevision
    {
        $revision = $this->entityManager->getRepository(CompanyRevision::class)
            ->findOneBy(['status' => RevisionStatus::Submitted]);
        self::assertInstanceOf(
            CompanyRevision::class,
            $revision,
        );

        return $revision;
    }

    /**
     * The only company draft in the seed: Halcyon Mobility's, left half-written on top of its live profile.
     */
    private function draftCompanyRevision(): CompanyRevision
    {
        $revision = $this->entityManager->getRepository(CompanyRevision::class)
            ->findOneBy(['status' => RevisionStatus::Draft]);
        self::assertInstanceOf(
            CompanyRevision::class,
            $revision,
        );

        return $revision;
    }

    private function vacancy(string $slug): Vacancy
    {
        $vacancy = self::getContainer()->get(VacancyRepository::class)
            ->findOneBy(['slugName' => $slug]);
        self::assertInstanceOf(
            Vacancy::class,
            $vacancy,
        );

        return $vacancy;
    }

    private function controller(): AdminApprovalController
    {
        return self::getContainer()->get(AdminApprovalController::class);
    }

    private function decide(string $transition): Request
    {
        $request = new Request(
            request: [
                'review_decision' => [
                    $transition => '',
                    '_csrf_token' => self::getContainer()->get(CsrfTokenManagerInterface::class)
                    ->getToken('submit')
                    ->getValue(),
                ],
            ],
            server: [
                'REQUEST_METHOD' => 'POST',
                'HTTP_SEC_FETCH_SITE' => 'same-origin',
            ],
        );
        $request->setMethod(Request::METHOD_POST);
        $request->setSession($this->session);
        self::getContainer()->get('request_stack')->push($request);

        return $request;
    }

    private function signInWithSudo(): void
    {
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken(
            $this->user(),
            'main',
            ['ROLE_BOARD'],
        ));

        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );
        $this->session = $session;

        $request = new Request();
        $request->setSession($session);
        // A sudo grant is only read back from a session the request already had, so the cookie has to be there.
        $request->cookies->set(
            $session->getName(),
            'test',
        );
        self::getContainer()->get('request_stack')->push($request);

        self::getContainer()->get(SudoMode::class)->grant();
    }

    private function user(): User
    {
        $user = $this->entityManager->getRepository(User::class)->find(8025);
        self::assertInstanceOf(
            User::class,
            $user,
        );

        return $user;
    }
}
