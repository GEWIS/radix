<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Career;

use App\Controller\Career\CompanyVacancyController;
use App\Entity\Application\Enums\RevisionStatus;
use App\Entity\Career\Company;
use App\Entity\Career\CompanyJobPackage;
use App\Entity\Career\Enums\VacancyCategories;
use App\Entity\Career\Vacancy;
use App\Entity\Career\VacancyRevision;
use App\Entity\User\CompanyUser;
use App\Repository\Career\CompanyRepository;
use App\Repository\Career\VacancyRepository;
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
 * The representative's own stepper: posting a vacancy and changing the draft of one that is already up, one step per
 * request, plus what the portal refuses to do.
 */
final class CompanyVacancyFlowTest extends DatabaseTestCase
{
    private const string RUN = 'company-vacancy-run';

    public function testAStepThatDoesNotHoldComesBackWithWhatWasWrong(): void
    {
        $companyUser = $this->signIn();
        $session = $this->open();

        $response = $this->controller()->create(
            $this->post(
                'general',
                $this->generalFields(['slugName' => 'Not A Slug']),
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
            'A slug starts with a letter',
            (string) $response->getContent(),
        );
        self::assertSame(
            0,
            (int) self::getContainer()->get(VacancyRepository::class)
                ->count(['slugName' => 'Not A Slug']),
        );
    }

    public function testACompleteStepperSavesTheVacancyForTheRepresentativesCompany(): void
    {
        $companyUser = $this->signIn();
        $session = $this->open();

        $this->controller()->create(
            $this->post(
                'general',
                $this->generalFields(['slugName' => 'company-created-role']),
                'next',
                $session,
            ),
            $companyUser,
        );
        $this->controller()->create(
            $this->post(
                'details',
                $this->detailsFields(),
                'next',
                $session,
            ),
            $companyUser,
        );
        $response = $this->controller()->create(
            $this->post(
                'contact',
                ['contactName' => 'Ilse'],
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
            '/company/vacancies/',
            $response->headers->get('Location') ?? '',
        );

        $vacancy = self::getContainer()->get(VacancyRepository::class)
            ->findOneBy(['slugName' => 'company-created-role']);
        self::assertInstanceOf(
            Vacancy::class,
            $vacancy,
        );
        self::assertSame(
            'Nexunt Systems',
            $vacancy->getCompany()->name,
        );
        self::assertSame(
            RevisionStatus::Draft,
            $vacancy->getCurrentRevision()?->getStatus(),
        );
        self::assertSame(
            $companyUser,
            $vacancy->getCurrentRevision()->getAuthorCompanyUser(),
        );
        self::assertSame(
            'Company Created Role',
            $vacancy->getCurrentRevision()->name->getValueEN(),
        );
    }

    /**
     * Once a revision is live its slug and package are settled, so the edit flow only asks for what is still the
     * company's to change.
     */
    public function testTheEditStepperWritesOntoTheDraftBehindTheLiveVacancy(): void
    {
        $companyUser = $this->signIn('recruitment@delta-robotics.example.com');
        $session = $this->open();

        $vacancy = $this->vacancy('controls-engineer');
        $current = $vacancy->getCurrentRevision();
        self::assertInstanceOf(
            VacancyRevision::class,
            $current,
        );
        $slugBefore = $vacancy->slugName;
        $packageBefore = $vacancy->package;

        $this->controller()->edit(
            $this->post(
                'general',
                [
                    'category' => VacancyCategories::Jobs->value,
                    'labelIds' => [],
                    'startDate' => '',
                    'endDate' => '2027-06-30',
                ],
                'next',
                $session,
            ),
            $vacancy,
            $companyUser,
        );
        $this->controller()->edit(
            $this->post(
                'details',
                [
                    'languageEnglish' => '1',
                    'nameEN' => 'Controls Engineer, again',
                    'locationEN' => 'Eindhoven',
                    'websiteEN' => 'https://example.com/controls',
                    'descriptionEN' => 'Rewritten by the company.',
                ],
                'next',
                $session,
            ),
            $vacancy,
            $companyUser,
        );
        $response = $this->controller()->edit(
            $this->post(
                'contact',
                ['contactName' => 'Sander'],
                'finish',
                $session,
            ),
            $vacancy,
            $companyUser,
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            '/company/vacancies/' . $vacancy->id . '/status',
            $response->headers->get('Location') ?? '',
        );
        self::assertSame(
            '2027-06-30',
            $current->endDate?->format('Y-m-d'),
        );
        self::assertSame(
            'Controls Engineer, again',
            $current->name->getValueEN(),
        );
        self::assertSame(
            $companyUser->getDisplayName(),
            $current->getLastEditorDisplayName(),
        );
        // The slug and the package were not part of the flow, so they cannot have been blanked by saving it.
        self::assertSame(
            $slugBefore,
            $vacancy->slugName,
        );
        self::assertSame(
            $packageBefore,
            $vacancy->package,
        );
    }

    public function testAVacancyAnotherRepresentativeIsEditingIsNotHandedOver(): void
    {
        $companyUser = $this->signIn();
        $this->open();

        $vacancy = $this->vacancy('backend-engineer');
        // Its live revision is approved, so proposing a change is what gives it a draft to lock.
        $this->controller()->revise(
            $vacancy,
            $companyUser,
        );

        self::getContainer()->get(EditLockService::class)->acquire(
            $vacancy,
            $this->representative('talent@nexunt.example.com'),
        );

        $response = $this->controller()->edit(
            $this->get(),
            $vacancy,
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

        $vacancy = $this->vacancy('backend-engineer');
        $this->controller()->revise(
            $vacancy,
            $companyUser,
        );

        $request = new Request();
        $request->attributes->set(
            '_route',
            'company/vacancies/edit',
        );
        $request->attributes->set(
            '_route_params',
            ['vacancy' => $vacancy->id],
        );

        $response = $this->controller()->edit(
            $request,
            $vacancy,
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

    public function testAVacancyWithoutACurrentRevisionHasNothingToEdit(): void
    {
        $companyUser = $this->signIn();
        $this->open();

        $vacancy = $this->vacancy('backend-engineer');
        $vacancy->setCurrentRevision(null);
        $this->entityManager->flush();

        $this->expectException(NotFoundHttpException::class);
        $this->controller()->edit(
            $this->get(),
            $vacancy,
            $companyUser,
        );
    }

    public function testAClosedVacancyCannotBeRevisedByItsCompany(): void
    {
        $companyUser = $this->signIn();
        $session = $this->open();

        $vacancy = $this->vacancy('backend-engineer');
        $vacancy->getCurrentRevision()?->setStatus(RevisionStatus::Closed);
        $this->entityManager->flush();

        $response = $this->controller()->revise(
            $vacancy,
            $companyUser,
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertNotEmpty($session->getFlashBag()->peek('warning'));
        self::assertSame(
            RevisionStatus::Closed,
            $vacancy->getCurrentRevision()?->getStatus(),
        );
    }

    /**
     * The company's only decision is to hand its draft to the committee; no reviewer grant is needed for that.
     */
    public function testSubmittingADraftSendsItToTheCommittee(): void
    {
        $companyUser = $this->signIn();
        $session = $this->open();

        $vacancy = $this->vacancy('backend-engineer');
        $this->controller()->revise(
            $vacancy,
            $companyUser,
        );
        $draft = $vacancy->getCurrentRevision();
        self::assertInstanceOf(
            VacancyRevision::class,
            $draft,
        );

        $response = $this->controller()->decide(
            $this->decision($session),
            $vacancy,
            $companyUser,
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            '/company/vacancies/' . $vacancy->id . '/status',
            $response->headers->get('Location') ?? '',
        );
        self::assertSame(
            RevisionStatus::Submitted,
            $draft->getStatus(),
        );
        self::assertNotEmpty($session->getFlashBag()->peek('success'));
    }

    /**
     * The lock endpoints are the same on every screen; here they are reached through the company's own routes.
     */
    public function testTheBrowserKeepsAndDropsTheLock(): void
    {
        $companyUser = $this->signIn();
        $this->open();

        $vacancy = $this->vacancy('backend-engineer');
        $this->controller()->revise(
            $vacancy,
            $companyUser,
        );
        self::getContainer()->get(EditLockService::class)->acquire(
            $vacancy,
            $companyUser,
        );

        $ping = $this->controller()->editPing(
            $companyUser,
            $vacancy,
        );
        self::assertSame(
            ['held' => true],
            json_decode(
                (string) $ping->getContent(),
                true,
            ),
        );

        $release = $this->controller()->editRelease(
            $companyUser,
            $vacancy,
        );
        self::assertSame(
            ['released' => true],
            json_decode(
                (string) $release->getContent(),
                true,
            ),
        );
        self::assertNull(
            self::getContainer()->get(EditLockService::class)->blockingLock(
                $vacancy,
                $companyUser,
            ),
        );
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function generalFields(array $overrides = []): array
    {
        return $overrides + [
            'slugName' => 'company-created-role',
            'packageId' => (string) $this->jobPackage()->id,
            'category' => VacancyCategories::Jobs->value,
            'labelIds' => [],
            'startDate' => '2026-01-01',
            'endDate' => '2026-12-31',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detailsFields(): array
    {
        return [
            'languageEnglish' => '1',
            'nameEN' => 'Company Created Role',
            'locationEN' => 'Eindhoven',
            'websiteEN' => 'https://example.com/company-created',
            'descriptionEN' => 'Posted by the company itself.',
        ];
    }

    private function jobPackage(): CompanyJobPackage
    {
        foreach ($this->company('nexunt')->getPackages() as $package) {
            if ($package instanceof CompanyJobPackage) {
                return $package;
            }
        }

        self::fail('The seed is expected to give Nexunt Systems a job package.');
    }

    private function vacancy(string $slug): Vacancy
    {
        $vacancy = self::getContainer()->get(VacancyRepository::class)->findOneBy(['slugName' => $slug]);
        self::assertInstanceOf(
            Vacancy::class,
            $vacancy,
        );

        return $vacancy;
    }

    private function company(string $slug): Company
    {
        $company = self::getContainer()->get(CompanyRepository::class)->findOneBy(['slugName' => $slug]);
        self::assertInstanceOf(
            Company::class,
            $company,
        );

        return $company;
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

    private function controller(): CompanyVacancyController
    {
        return self::getContainer()->get(CompanyVacancyController::class);
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
            request: ['vacancy_flow' => $body],
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
