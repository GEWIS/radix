<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Career;

use App\Controller\Career\CompanyVacancyController;
use App\Entity\Application\Enums\RevisionStatus;
use App\Entity\Career\Vacancy;
use App\Entity\Career\VacancyRevision;
use App\Entity\Career\VacancyRevisionComment;
use App\Entity\User\CompanyUser;
use App\Repository\Career\VacancyRevisionCommentRepository;
use App\Repository\User\CompanyUserRepository;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * The company's own view of a vacancy's working version: its status screen, starting a new draft, the discussion, and
 * the two endpoints the browser keeps an edit lock alive with.
 */
final class CompanyVacancyReviewTest extends DatabaseTestCase
{
    /**
     * A draft that is already open is what the representative wants to work on, which is not worth a warning.
     */
    public function testRevisingADraftLeadsStraightToTheEditor(): void
    {
        $companyUser = $this->signIn();
        $vacancy = $this->vacancy('backend-engineer');
        $this->controller()->revise(
            $vacancy,
            $companyUser,
        );

        $response = $this->controller()->revise(
            $vacancy,
            $companyUser,
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertStringContainsString(
            '/company/vacancies/' . $vacancy->id . '/edit',
            $response->getTargetUrl(),
        );
    }

    public function testAProfileWithTheCommitteeSaysSoRatherThanStartAnotherDraft(): void
    {
        $companyUser = $this->signIn();
        $vacancy = $this->vacancy('backend-engineer');
        $revision = $vacancy->getCurrentRevision();
        self::assertInstanceOf(
            VacancyRevision::class,
            $revision,
        );
        $revision->setStatus(RevisionStatus::Submitted);
        $this->entityManager->flush();

        $response = $this->controller()->revise(
            $vacancy,
            $companyUser,
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertNotEmpty($this->session()->getFlashBag()->peek('warning'));
    }

    public function testAnApprovedVacancyIsRevisedIntoANewDraft(): void
    {
        $companyUser = $this->signIn();
        $vacancy = $this->vacancy('backend-engineer');
        $live = $vacancy->getLiveRevision();

        $response = $this->controller()->revise(
            $vacancy,
            $companyUser,
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertNotSame(
            $live,
            $vacancy->getCurrentRevision(),
        );
        self::assertSame(
            RevisionStatus::Draft,
            $vacancy->getCurrentRevision()?->getStatus(),
        );
    }

    public function testTheDiscussionRecordsWhatTheRepresentativeWrote(): void
    {
        $companyUser = $this->signIn();
        $vacancy = $this->vacancy('backend-engineer');
        $this->controller()->revise(
            $vacancy,
            $companyUser,
        );

        $this->controller()->comment(
            new Request(request: ['message' => 'Can we lower the hours?']),
            $vacancy,
            $companyUser,
        );

        $comments = self::getContainer()->get(VacancyRevisionCommentRepository::class)
            ->findThreadForVacancy($vacancy);
        self::assertNotEmpty($comments);
        self::assertInstanceOf(
            VacancyRevisionComment::class,
            $comments[0],
        );
        self::assertSame(
            'Can we lower the hours?',
            $comments[0]->body,
        );
    }

    /**
     * The box is posted as a plain field, so an empty one is simply ignored rather than recorded.
     */
    public function testAnEmptyCommentChangesNothing(): void
    {
        $companyUser = $this->signIn();
        $vacancy = $this->vacancy('backend-engineer');
        $this->controller()->revise(
            $vacancy,
            $companyUser,
        );

        $this->controller()->comment(
            new Request(),
            $vacancy,
            $companyUser,
        );

        self::assertSame(
            [],
            self::getContainer()->get(VacancyRevisionCommentRepository::class)->findThreadForVacancy($vacancy),
        );
    }

    /**
     * A first draft has nothing behind it, so discarding it would be a deletion; that is refused.
     */
    public function testAFirstDraftCannotBeDiscarded(): void
    {
        $companyUser = $this->signIn();
        $vacancy = $this->firstDraft($companyUser);

        $response = $this->controller()->discard(
            $vacancy,
            $companyUser,
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertNotEmpty($this->session()->getFlashBag()->peek('warning'));
        self::assertNotNull($vacancy->getCurrentRevision());
    }

    /**
     * The status screen is the review screen with the vacancy's own actions, which read the vacancy the page was
     * opened for.
     */
    public function testTheStatusScreenRenders(): void
    {
        $companyUser = $this->signIn();
        $vacancy = $this->vacancy('backend-engineer');

        $response = $this->controller()->status(
            $vacancy,
            $companyUser,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Backend Engineer',
            (string) $response->getContent(),
        );
    }

    public function testTheBrowserCanSayItIsStillThere(): void
    {
        $companyUser = $this->signIn();
        $vacancy = $this->vacancy('backend-engineer');

        $response = $this->controller()->editPing(
            $companyUser,
            $vacancy,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertArrayHasKey(
            'held',
            json_decode(
                (string) $response->getContent(),
                true,
                512,
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    /**
     * Releasing is idempotent: a screen that never had the lock gets the same answer.
     */
    public function testTheBrowserCanSayItHasGone(): void
    {
        $companyUser = $this->signIn();

        $response = $this->controller()->editRelease(
            $companyUser,
            $this->vacancy('backend-engineer'),
        );

        self::assertSame(
            ['released' => true],
            json_decode(
                (string) $response->getContent(),
                true,
                512,
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    private function firstDraft(CompanyUser $companyUser): Vacancy
    {
        $source = $this->vacancy('backend-engineer');

        $vacancy = new Vacancy();
        $vacancy->slugName = 'first-draft';
        $vacancy->published = true;
        $vacancy->package = $source->package;

        $revision = new VacancyRevision();
        $revision->endDate = new DateTimeImmutable('+1 month');
        $revision->setAuthorCompanyUser($companyUser);
        $vacancy->addRevision($revision);
        $vacancy->setCurrentRevision($revision);

        $this->entityManager->persist($vacancy);
        $this->entityManager->persist($revision);
        $this->entityManager->flush();

        return $vacancy;
    }

    private function vacancy(string $slug): Vacancy
    {
        $vacancy = $this->entityManager->getRepository(Vacancy::class)->findOneBy(['slugName' => $slug]);
        self::assertInstanceOf(
            Vacancy::class,
            $vacancy,
        );

        return $vacancy;
    }

    private function signIn(string $email = 'recruitment@nexunt.example.com'): CompanyUser
    {
        $companyUser = self::getContainer()->get(CompanyUserRepository::class)->loadUserByIdentifier($email);
        self::assertInstanceOf(
            CompanyUser::class,
            $companyUser,
        );

        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken(
            $companyUser,
            'company',
            ['ROLE_COMPANY_USER'],
        ));
        $this->pushRequestWithSession();

        return $companyUser;
    }

    private function session(): FlashBagAwareSessionInterface
    {
        $session = self::getContainer()->get('request_stack')->getSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        return $session;
    }

    private function pushRequestWithSession(): FlashBagAwareSessionInterface
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        $request = new Request(query: ['flow' => 'testing']);
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);

        return $session;
    }

    private function controller(): CompanyVacancyController
    {
        return self::getContainer()->get(CompanyVacancyController::class);
    }
}
