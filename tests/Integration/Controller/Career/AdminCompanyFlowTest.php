<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Career;

use App\Controller\Career\AdminController;
use App\Entity\Application\Enums\RevisionStatus;
use App\Entity\Career\Company;
use App\Entity\Career\CompanyRevision;
use App\Entity\User\User;
use App\Repository\Career\CompanyRepository;
use App\Service\Application\EditLockService;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

use function imagecolorallocate;
use function imagecreatetruecolor;
use function imagefilledrectangle;
use function imagejpeg;
use function json_decode;
use function sys_get_temp_dir;
use function tempnam;

/**
 * The board creating and rewriting a company through the same stepper the company itself uses, including the identity
 * step only the board is offered.
 */
final class AdminCompanyFlowTest extends DatabaseTestCase
{
    private const string RUN = 'admin-company-run';

    public function testTheBoardStepperCreatesTheCompanyAsADraft(): void
    {
        $this->signIn();
        $session = $this->open();

        $this->controller()->create(
            $this->post(
                'identity',
                [
                    'name' => 'New Venture',
                    'slugName' => 'new-venture',
                    'published' => '1',
                ],
                'next',
                $session,
            ),
            $this->user(),
        );
        $this->controller()->create(
            $this->post(
                'profile',
                $this->profileFields(['sloganEN' => 'Fresh blood']),
                'next',
                $session,
            ),
            $this->user(),
        );
        $this->controller()->create(
            $this->post(
                'contact',
                ['contactName' => 'Founder'],
                'next',
                $session,
            ),
            $this->user(),
        );
        $response = $this->controller()->create(
            $this->postLogo($session),
            $this->user(),
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            '/admin/career/companies/',
            $response->headers->get('Location') ?? '',
        );

        $company = $this->company('new-venture');
        self::assertSame(
            'New Venture',
            $company->name,
        );
        self::assertTrue($company->published);
        self::assertSame(
            RevisionStatus::Draft,
            $company->getCurrentRevision()?->getStatus(),
        );
        self::assertSame(
            'Fresh blood',
            $company->getCurrentRevision()->slogan->getValueEN(),
        );
        $revision = $company->getCurrentRevision();
        $authorLidnr = $revision->getAuthor()?->lidnr;
        self::assertNotNull($authorLidnr);
        $member = $this->user()->member;
        self::assertSame(
            $member->lidnr,
            $authorLidnr,
        );
    }

    public function testAStepThatDoesNotHoldComesBackWithWhatWasWrong(): void
    {
        $this->signIn();
        $session = $this->open();

        $response = $this->controller()->create(
            $this->post(
                'identity',
                [
                    'name' => '',
                    'slugName' => 'new-venture',
                    'published' => '1',
                ],
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
            'Enter a name.',
            (string) $response->getContent(),
        );
    }

    public function testTheEditStepperWritesOntoTheDraftOnly(): void
    {
        $this->signIn();
        $session = $this->open();

        $company = $this->company('nexunt');
        $live = $company->getLiveRevision();
        self::assertInstanceOf(
            CompanyRevision::class,
            $live,
        );
        $this->controller()->revise(
            $company,
            $this->user(),
        );
        $draft = $company->getCurrentRevision();
        self::assertInstanceOf(
            CompanyRevision::class,
            $draft,
        );

        $this->controller()->edit(
            $this->post(
                'identity',
                [
                    'name' => 'Nexunt Systems',
                    'slugName' => 'nexunt',
                    'published' => '1',
                ],
                'next',
                $session,
            ),
            $company,
            $this->user(),
        );
        $this->controller()->edit(
            $this->post(
                'profile',
                $this->profileFields(['sloganEN' => 'The backbone, again']),
                'next',
                $session,
            ),
            $company,
            $this->user(),
        );
        $this->controller()->edit(
            $this->post(
                'contact',
                ['contactName' => 'Board Contact'],
                'next',
                $session,
            ),
            $company,
            $this->user(),
        );
        $response = $this->controller()->edit(
            $this->postLogo($session),
            $company,
            $this->user(),
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertSame(
            'The backbone, again',
            $draft->slogan->getValueEN(),
        );
        self::assertSame(
            'Board Contact',
            $draft->contactName,
        );
        self::assertNotNull($draft->squareLogo);
        self::assertNotNull($draft->bannerLogo);
        self::assertSame(
            $this->user()->getDisplayName(),
            $draft->getLastEditorDisplayName(),
        );
        self::assertSame(
            $live,
            $company->getLiveRevision(),
        );
    }

    public function testACompanyAnotherBoardMemberIsEditingIsNotHandedOver(): void
    {
        $this->signIn();
        $this->open();

        $company = $this->company('nexunt');
        $this->controller()->revise(
            $company,
            $this->user(),
        );
        self::getContainer()->get(EditLockService::class)->acquire(
            $company,
            $this->user(8026),
        );

        $response = $this->controller()->edit(
            $this->get(),
            $company,
            $this->user(),
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Company is being edited',
            (string) $response->getContent(),
        );
    }

    public function testOpeningTheEditorStartsARun(): void
    {
        $this->signIn();
        $this->open();

        $company = $this->company('nexunt');
        $this->controller()->revise(
            $company,
            $this->user(),
        );

        $request = new Request();
        $request->attributes->set(
            '_route',
            'admin/career/companies/edit',
        );
        $request->attributes->set(
            '_route_params',
            ['company' => $company->id],
        );

        $response = $this->controller()->edit(
            $request,
            $company,
            $this->user(),
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

    public function testACompanyWithoutACurrentRevisionHasNothingToEdit(): void
    {
        $this->signIn();
        $this->open();

        $company = $this->company('nexunt');
        $company->setCurrentRevision(null);
        $this->entityManager->flush();

        $this->expectException(NotFoundHttpException::class);
        $this->controller()->edit(
            $this->get(),
            $company,
            $this->user(),
        );
    }

    public function testTheCompanyVacancyCountIsShownForOneCompany(): void
    {
        $this->signIn();
        $this->open();

        $response = $this->controller()->vacancies($this->company('nexunt'));

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Nexunt Systems',
            (string) $response->getContent(),
        );
    }

    public function testRevisingWhileTheCommitteeHasItIsRefused(): void
    {
        $this->signIn();
        $session = $this->open();

        $company = $this->company('nexunt');
        $company->getCurrentRevision()?->setStatus(RevisionStatus::Submitted);
        $this->entityManager->flush();

        $response = $this->controller()->revise(
            $company,
            $this->user(),
        );

        self::assertStringContainsString(
            '/admin/career/companies/' . $company->id,
            $response->headers->get('Location') ?? '',
        );
        self::assertNotEmpty($session->getFlashBag()->peek('warning'));
        self::assertSame(
            RevisionStatus::Submitted,
            $company->getCurrentRevision()?->getStatus(),
        );
    }

    public function testACompanyWithoutACurrentRevisionCannotBeRevised(): void
    {
        $this->signIn();
        $this->open();

        $company = $this->company('nexunt');
        $company->setCurrentRevision(null);
        $this->entityManager->flush();

        $this->expectException(NotFoundHttpException::class);
        $this->controller()->revise(
            $company,
            $this->user(),
        );
    }

    public function testTheBrowserKeepsAndDropsTheLock(): void
    {
        $this->signIn();
        $this->open();

        $company = $this->company('nexunt');
        $this->controller()->revise(
            $company,
            $this->user(),
        );
        self::getContainer()->get(EditLockService::class)->acquire(
            $company,
            $this->user(),
        );

        $ping = $this->controller()->editPing(
            $this->user(),
            $company,
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
            $company,
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
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function profileFields(array $overrides = []): array
    {
        return $overrides + [
            'languageEnglish' => '1',
            'sloganEN' => 'Building the backbone of tomorrow',
            'websiteEN' => 'https://example.com/nexunt',
            'descriptionEN' => 'A profile written by the board.',
        ];
    }

    private function postLogo(SessionInterface $session): Request
    {
        $square = $this->imageFile(
            320,
            320,
        );
        $banner = $this->imageFile(
            640,
            320,
        );

        $body = [
            'logo' => [],
            'finish' => '',
            '_csrf_token' => $this->token(),
        ];

        $request = new Request(
            query: ['flow' => self::RUN],
            request: ['company_profile_flow' => $body],
            files: [
                'company_profile_flow' => [
                    'logo' => [
                        'squareLogoFile' => $square,
                        'bannerLogoFile' => $banner,
                    ],
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

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    private function imageFile(
        int $width,
        int $height,
    ): UploadedFile {
        $path = tempnam(
            sys_get_temp_dir(),
            'career-logo-test',
        );
        self::assertIsString($path);

        $image = imagecreatetruecolor(
            $width,
            $height,
        );
        self::assertNotFalse($image);
        $colour = imagecolorallocate(
            $image,
            0x20,
            0x40,
            0x80,
        );
        self::assertNotFalse($colour);
        imagefilledrectangle(
            $image,
            0,
            0,
            $width,
            $height,
            $colour,
        );
        imagejpeg(
            $image,
            $path,
        );

        return new UploadedFile(
            $path,
            'logo.jpg',
            'image/jpeg',
            null,
            true,
        );
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

    private function controller(): AdminController
    {
        return self::getContainer()->get(AdminController::class);
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
