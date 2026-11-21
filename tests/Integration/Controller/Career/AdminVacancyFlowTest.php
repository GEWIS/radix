<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Career;

use App\Controller\Career\AdminVacancyController;
use App\Entity\Application\Enums\RevisionStatus;
use App\Entity\Career\Company;
use App\Entity\Career\CompanyJobPackage;
use App\Entity\Career\Enums\VacancyCategories;
use App\Entity\Career\Vacancy;
use App\Entity\Career\VacancyRevision;
use App\Entity\User\User;
use App\Repository\Career\CompanyRepository;
use App\Repository\Career\VacancyRepository;
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
 * The board posting and changing a vacancy through the stepper, which is the only way a vacancy is written: each
 * step is its own request, so the flow is driven here exactly as the browser drives it.
 */
final class AdminVacancyFlowTest extends DatabaseTestCase
{
    private const string RUN = 'admin-vacancy-run';

    public function testAStepThatDoesNotHoldComesBackWithWhatWasWrong(): void
    {
        $this->signIn();
        $session = $this->open();

        $response = $this->controller()->create(
            $this->post(
                'general',
                $this->generalFields(['endDate' => '']),
                'next',
                $session,
            ),
            $this->user(),
        );

        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Enter the day the vacancy closes.',
            (string) $response->getContent(),
        );
        self::assertSame(
            0,
            $this->vacancies('board-created-vacancy'),
        );
    }

    public function testACompleteStepperSavesTheVacancyAsADraft(): void
    {
        $this->signIn();
        $session = $this->open();

        $this->walk(
            $session,
            $this->generalFields(['slugName' => 'board-created-vacancy']),
            [
                'nameEN' => 'Board Created Role',
                'locationEN' => 'Eindhoven',
                'websiteEN' => 'https://example.com/board-created',
                'descriptionEN' => 'Posted by the committee.',
            ],
            ['contactName' => 'Ilse'],
        );

        $vacancy = $this->entityManager->getRepository(Vacancy::class)
            ->findOneBy(['slugName' => 'board-created-vacancy']);
        self::assertInstanceOf(
            Vacancy::class,
            $vacancy,
        );
        self::assertSame(
            RevisionStatus::Draft,
            $vacancy->getCurrentRevision()?->getStatus(),
        );
        self::assertSame(
            'Board Created Role',
            $vacancy->getCurrentRevision()->name->getValueEN(),
        );
        self::assertSame(
            'Ilse',
            $vacancy->getCurrentRevision()->contactName,
        );
        self::assertSame(
            'nexunt',
            $vacancy->getCompany()->slugName,
        );
    }

    public function testTheEditStepperWritesOntoTheDraftItWasOpenedOn(): void
    {
        $this->signIn();
        $session = $this->open();

        $vacancy = $this->vacancy('controls-engineer');
        $current = $vacancy->getCurrentRevision();
        self::assertInstanceOf(
            VacancyRevision::class,
            $current,
        );

        $this->walkEdit(
            $session,
            $vacancy,
            $this->generalFields([
                'slugName' => $vacancy->slugName,
                'packageId' => (string) $vacancy->package->id,
                'labelIds' => [],
                'startDate' => '2026-02-01',
                'endDate' => '2027-01-31',
            ]),
            [
                'nameEN' => 'Controls Engineer, revised',
                'locationEN' => 'Eindhoven',
                'websiteEN' => 'https://example.com/controls',
                'descriptionEN' => 'Rewritten by the board.',
            ],
            ['contactName' => 'Board'],
        );

        self::assertSame(
            '2027-01-31',
            $current->endDate?->format('Y-m-d'),
        );
        self::assertSame(
            'Controls Engineer, revised',
            $current->name->getValueEN(),
        );
        self::assertSame(
            $this->user()->getDisplayName(),
            $current->getLastEditorDisplayName(),
        );
        self::assertCount(
            0,
            $current->getLabels(),
        );
    }

    /**
     * Two people editing one vacancy at once would silently overwrite each other, so the second is shown the lock.
     */
    public function testAVacancySomebodyElseIsEditingIsNotHandedOver(): void
    {
        $this->signIn();
        $session = $this->open();

        $vacancy = $this->vacancy('controls-engineer');
        self::getContainer()->get(EditLockService::class)->acquire(
            $vacancy,
            $this->user(8026),
        );

        $response = $this->controller()->edit(
            $this->get(['flow' => self::RUN]),
            $vacancy,
            $this->user(),
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'being edited',
            (string) $response->getContent(),
        );
    }

    /**
     * Opening the editor is what starts a run of the flow; every later step is addressed by that run's key.
     */
    public function testOpeningTheEditorStartsARun(): void
    {
        $this->signIn();
        $this->open();

        $vacancy = $this->vacancy('controls-engineer');
        $request = new Request();
        $request->attributes->set(
            '_route',
            'admin/career/vacancies/edit',
        );
        $request->attributes->set(
            '_route_params',
            ['vacancy' => $vacancy->id],
        );

        $response = $this->controller()->edit(
            $request,
            $vacancy,
            $this->user(),
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertStringContainsString(
            'flow=',
            self::redirectTarget($response),
        );
    }

    public function testAVacancyWithoutACurrentRevisionHasNothingToEdit(): void
    {
        $this->signIn();
        $this->open();

        $vacancy = $this->vacancy('controls-engineer');
        $vacancy->setCurrentRevision(null);
        $this->entityManager->flush();

        $this->expectException(NotFoundHttpException::class);
        $this->controller()->edit(
            $this->get(['flow' => self::RUN]),
            $vacancy,
            $this->user(),
        );
    }

    public function testRevisingADraftOpensThatDraftRatherThanAnother(): void
    {
        $this->signIn();
        $session = $this->open();

        $vacancy = $this->vacancy('controls-engineer');

        $response = $this->controller()->revise(
            $vacancy,
            $this->user(),
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertStringContainsString(
            'edit',
            self::redirectTarget($response),
        );
        self::assertNotEmpty($session->getFlashBag()->peek('warning'));
        self::assertSame(
            RevisionStatus::Draft,
            $vacancy->getCurrentRevision()?->getStatus(),
        );
    }

    public function testRevisingWhileTheCommitteeHasItIsRefused(): void
    {
        $this->signIn();
        $session = $this->open();

        $vacancy = $this->vacancy('controls-engineer');
        $vacancy->getCurrentRevision()?->setStatus(RevisionStatus::Submitted);
        $this->entityManager->flush();

        $response = $this->controller()->revise(
            $vacancy,
            $this->user(),
        );

        self::assertStringContainsString(
            '/admin/career/vacancies',
            self::redirectTarget($response),
        );
        self::assertNotEmpty($session->getFlashBag()->peek('warning'));
    }

    public function testAClosedVacancyCannotBeRevised(): void
    {
        $this->signIn();
        $session = $this->open();

        $vacancy = $this->vacancy('controls-engineer');
        $vacancy->getCurrentRevision()?->setStatus(RevisionStatus::Closed);
        $this->entityManager->flush();

        $this->controller()->revise(
            $vacancy,
            $this->user(),
        );

        self::assertNotEmpty($session->getFlashBag()->peek('warning'));
        self::assertSame(
            RevisionStatus::Closed,
            $vacancy->getCurrentRevision()?->getStatus(),
        );
    }

    public function testRevisingAVacancyWithoutACurrentRevisionIsANotFound(): void
    {
        $this->signIn();
        $this->open();

        $vacancy = $this->vacancy('controls-engineer');
        $vacancy->setCurrentRevision(null);
        $this->entityManager->flush();

        $this->expectException(NotFoundHttpException::class);
        $this->controller()->revise(
            $vacancy,
            $this->user(),
        );
    }

    public function testTheBrowserKeepsAndDropsTheLock(): void
    {
        $this->signIn();
        $this->open();

        $vacancy = $this->vacancy('controls-engineer');
        self::getContainer()->get(EditLockService::class)->acquire(
            $vacancy,
            $this->user(),
        );

        $ping = $this->controller()->editPing(
            $this->user(),
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
            $this->user(),
            $vacancy,
        );
        self::assertSame(
            ['released' => true],
            json_decode(
                (string) $release->getContent(),
                true,
            ),
        );
    }

    /**
     * @param array<string, mixed> $general
     * @param array<string, mixed> $details
     * @param array<string, mixed> $contact
     */
    private function walk(
        SessionInterface $session,
        array $general,
        array $details,
        array $contact,
    ): void {
        $this->controller()->create(
            $this->post(
                'general',
                $general,
                'next',
                $session,
            ),
            $this->user(),
        );
        $this->controller()->create(
            $this->post(
                'details',
                $details + ['languageEnglish' => '1'],
                'next',
                $session,
            ),
            $this->user(),
        );
        $response = $this->controller()->create(
            $this->post(
                'contact',
                $contact,
                'finish',
                $session,
            ),
            $this->user(),
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertStringContainsString(
            '/admin/career/vacancies',
            self::redirectTarget($response),
        );
    }

    /**
     * @param array<string, mixed> $general
     * @param array<string, mixed> $details
     * @param array<string, mixed> $contact
     */
    private function walkEdit(
        SessionInterface $session,
        Vacancy $vacancy,
        array $general,
        array $details,
        array $contact,
    ): void {
        $this->controller()->edit(
            $this->post(
                'general',
                $general,
                'next',
                $session,
            ),
            $vacancy,
            $this->user(),
        );
        $this->controller()->edit(
            $this->post(
                'details',
                $details + ['languageEnglish' => '1'],
                'next',
                $session,
            ),
            $vacancy,
            $this->user(),
        );
        $response = $this->controller()->edit(
            $this->post(
                'contact',
                $contact,
                'finish',
                $session,
            ),
            $vacancy,
            $this->user(),
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
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
            'slugName' => 'board-created-vacancy',
            'packageId' => (string) $this->package()->id,
            'published' => '1',
            'category' => VacancyCategories::Jobs->value,
            'labelIds' => [],
            'startDate' => '2026-01-01',
            'endDate' => '2026-12-31',
        ];
    }

    private function package(): CompanyJobPackage
    {
        foreach ($this->company('nexunt')->getPackages() as $package) {
            if ($package instanceof CompanyJobPackage) {
                return $package;
            }
        }

        self::fail('The seed is expected to give Nexunt Systems a job package.');
    }

    private function vacancies(string $slug): int
    {
        return (int) self::getContainer()->get(VacancyRepository::class)
            ->count(['slugName' => $slug]);
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

    private function controller(): AdminVacancyController
    {
        return self::getContainer()->get(AdminVacancyController::class);
    }

    private function signIn(int $lidnr = 8025): void
    {
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken(
            $this->user($lidnr),
            'main',
            [
                'ROLE_COMPANY_ADMIN',
                'ROLE_BOARD',
            ],
        ));
    }

    private function user(int $lidnr = 8025): User
    {
        $user = $this->entityManager->getRepository(User::class)->find($lidnr);
        self::assertInstanceOf(
            User::class,
            $user,
        );

        return $user;
    }

    private function open(): FlashBagAwareSessionInterface
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        $this->push(
            $this->get(['flow' => self::RUN]),
            $session,
        );

        return $session;
    }

    /**
     * @param array<string, mixed> $query
     */
    private function get(array $query = []): Request
    {
        return new Request(query: $query);
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
            '_csrf_token' => self::getContainer()->get(CsrfTokenManagerInterface::class)
                ->getToken('submit')
                ->getValue(),
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

    private function push(
        Request $request,
        SessionInterface $session,
    ): void {
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);
    }
}
