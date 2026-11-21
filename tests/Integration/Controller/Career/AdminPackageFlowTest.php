<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Career;

use App\Controller\Career\AdminPackageController;
use App\Entity\Career\Company;
use App\Entity\Career\CompanyAuditLog;
use App\Entity\Career\CompanyBannerPackage;
use App\Entity\Career\CompanyJobPackage;
use App\Entity\Career\CompanyPackage;
use App\Entity\Career\Enums\CompanyAuditVerbs;
use App\Entity\Career\Enums\CompanyBannerFormats;
use App\Entity\User\User;
use App\Repository\Career\CompanyAuditLogRepository;
use App\Repository\Career\CompanyPackageRepository;
use App\Repository\Career\CompanyRepository;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

use function strtolower;

/**
 * The packages a company bought: the board adding one, changing its window, and the overview that warns what is
 * about to begin or run out.
 */
final class AdminPackageFlowTest extends DatabaseTestCase
{
    private SessionInterface $session;

    public function testTheOverviewShowsWhatExpiresAndWhatStarts(): void
    {
        $this->signIn();
        $this->open();
        $company = $this->company('nexunt');

        $this->package(
            $company,
            '+10 days',
            '+10 days',
        );
        $this->package(
            $company,
            '+20 days',
            '+20 days',
        );
        $this->package(
            $company,
            '2020-01-01',
            '+10 days',
        );
        $this->package(
            $company,
            '2020-01-01',
            '+20 days',
        );

        $response = $this->controller()->index();

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        $content = (string) $response->getContent();
        self::assertStringContainsString(
            'Nexunt Systems',
            $content,
        );
        self::assertStringContainsString(
            'expiring',
            strtolower($content),
        );
    }

    public function testOneCompanysPackagesAreListed(): void
    {
        $this->signIn();
        $this->open();

        $response = $this->controller()->forCompany($this->company('nexunt'));

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Nexunt Systems',
            (string) $response->getContent(),
        );
    }

    public function testAPackageIsAddedWithItsOwnWindow(): void
    {
        $this->signIn();
        $this->open();
        $company = $this->company('nexunt');

        $response = $this->controller()->create(
            $this->post(
                [
                    'contractNumber' => 'NEW-1',
                    'startingDate' => '2026-01-01',
                    'expirationDate' => '2026-12-31',
                    'published' => '1',
                ],
                'job',
            ),
            $company,
            'job',
            $this->user(),
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            '/admin/career/packages/company/' . $company->id,
            $response->headers->get('Location') ?? '',
        );

        $package = $this->packageByContract('NEW-1');
        self::assertInstanceOf(
            CompanyJobPackage::class,
            $package,
        );
        self::assertSame(
            '2026-12-31',
            $package->getExpirationDate()->format('Y-m-d'),
        );
        self::assertSame(
            CompanyAuditVerbs::PackageCreated,
            $this->timeline($company)[0]->verb,
        );
    }

    public function testABannerPackageIsCreatedWithItsFormat(): void
    {
        $this->signIn();
        $this->open();
        $company = $this->company('nexunt');

        $this->controller()->create(
            $this->post(
                [
                    'contractNumber' => 'BANNER-1',
                    'startingDate' => '2026-01-01',
                    'expirationDate' => '2026-12-31',
                    'published' => '1',
                    'format' => CompanyBannerFormats::Leaderboard->value,
                ],
                'banner',
            ),
            $company,
            'banner',
            $this->user(),
        );

        $package = $this->packageByContract('BANNER-1');
        self::assertInstanceOf(
            CompanyBannerPackage::class,
            $package,
        );
        self::assertSame(
            CompanyBannerFormats::Leaderboard,
            $package->format,
        );
    }

    public function testAPackageThatWouldExpireBeforeItStartsIsRefused(): void
    {
        $this->signIn();
        $this->open();
        $company = $this->company('nexunt');

        $response = $this->controller()->create(
            $this->post(
                [
                    'contractNumber' => 'BROKEN-1',
                    'startingDate' => '2026-12-31',
                    'expirationDate' => '2026-01-01',
                ],
                'job',
            ),
            $company,
            'job',
            $this->user(),
        );

        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'A package cannot expire before it starts.',
            (string) $response->getContent(),
        );
        self::assertNull($this->packageByContract('BROKEN-1'));
    }

    public function testEditingAPackageMovesItsExpiry(): void
    {
        $this->signIn();
        $this->open();
        $package = $this->seededJobPackage();
        self::assertInstanceOf(
            CompanyJobPackage::class,
            $package,
        );

        $response = $this->controller()->edit(
            $this->post(
                [
                    'contractNumber' => $package->contractNumber,
                    'startingDate' => '2020-01-01',
                    'expirationDate' => '2098-12-31',
                    'published' => '1',
                ],
                'job',
            ),
            $package,
            $this->user(),
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertSame(
            '2098-12-31',
            $package->getExpirationDate()->format('Y-m-d'),
        );
        self::assertSame(
            CompanyAuditVerbs::PackageUpdated,
            $this->timeline($package->company)[0]->verb,
        );
    }

    public function testAnEditThatDoesNotHoldComesBackWithWhatWasWrong(): void
    {
        $this->signIn();
        $this->open();
        $package = $this->seededJobPackage();

        $response = $this->controller()->edit(
            $this->post(
                [
                    'startingDate' => '2026-12-31',
                    'expirationDate' => '2026-01-01',
                ],
                'job',
            ),
            $package,
            $this->user(),
        );

        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'A package cannot expire before it starts.',
            (string) $response->getContent(),
        );
    }

    private function package(
        Company $company,
        string $starts,
        string $expires,
    ): CompanyJobPackage {
        $package = new CompanyJobPackage();
        $package->company = $company;
        $package->setStartingDate(new DateTimeImmutable($starts));
        $package->setExpirationDate(new DateTimeImmutable($expires));
        $package->published = true;
        $this->entityManager->persist($package);
        $this->entityManager->flush();

        return $package;
    }

    private function seededJobPackage(): CompanyPackage
    {
        foreach ($this->company('nexunt')->getPackages() as $package) {
            if ($package instanceof CompanyJobPackage) {
                return $package;
            }
        }

        self::fail('The seed is expected to give Nexunt Systems a job package.');
    }

    private function packageByContract(string $contractNumber): ?CompanyPackage
    {
        return self::getContainer()->get(CompanyPackageRepository::class)
            ->findOneBy(['contractNumber' => $contractNumber]);
    }

    /**
     * @return list<CompanyAuditLog>
     */
    private function timeline(Company $company): array
    {
        return self::getContainer()->get(CompanyAuditLogRepository::class)
            ->findRecentForCompany($company);
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

    private function controller(): AdminPackageController
    {
        return self::getContainer()->get(AdminPackageController::class);
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

    private function open(): void
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );
        $this->session = $session;

        $request = new Request();
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function post(
        array $fields,
        string $type,
    ): Request {
        $fields['_csrf_token'] = self::getContainer()->get(CsrfTokenManagerInterface::class)
            ->getToken('submit')
            ->getValue();

        $request = new Request(
            request: ['company_package' => $fields],
            server: [
                'REQUEST_METHOD' => 'POST',
                'HTTP_SEC_FETCH_SITE' => 'same-origin',
            ],
        );
        $request->setMethod(Request::METHOD_POST);
        $request->attributes->set(
            'type',
            $type,
        );
        $request->setSession($this->session);
        self::getContainer()->get('request_stack')->push($request);

        return $request;
    }
}
