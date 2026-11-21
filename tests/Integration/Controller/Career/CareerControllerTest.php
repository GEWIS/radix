<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Career;

use App\Controller\Career\CareerController;
use App\Entity\Career\Enums\VacancyCategories;
use App\Entity\Career\Vacancy;
use App\Tests\Integration\DatabaseTestCase;
use Override;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The public career pages: the landing page, the two overviews the live components fill, and the detail pages a link
 * from the outside world may point at. Nothing here needs a signed-in visitor, so every refusal is a plain 404.
 */
final class CareerControllerTest extends DatabaseTestCase
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        // The layout reads the locale off the current request, which a controller invoked directly does not get.
        self::getContainer()->get('request_stack')->push(new Request());
    }

    public function testTheLandingPageIsOpenToVisitors(): void
    {
        $response = $this->controller()->index();

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
    }

    public function testTheCompaniesOverviewIsServed(): void
    {
        self::assertSame(
            Response::HTTP_OK,
            $this->controller()->companies()->getStatusCode(),
        );
    }

    public function testTheVacanciesOverviewIsServed(): void
    {
        self::assertSame(
            Response::HTTP_OK,
            $this->controller()->vacancies()->getStatusCode(),
        );
    }

    public function testAPublicCompanyPageNamesTheCompany(): void
    {
        $response = $this->controller()->company('nexunt');

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Nexunt Systems',
            (string) $response->getContent(),
        );
    }

    public function testAnUnknownCompanyIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->controller()->company('no-such-company');
    }

    public function testAPublicVacancyPageNamesTheVacancy(): void
    {
        $response = $this->controller()->vacancy(
            'nexunt',
            VacancyCategories::Jobs->value,
            'backend-engineer',
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

    /**
     * The category is part of the address, so a slug that does exist under a different category does not resolve.
     */
    public function testAVacancyIsNotReachableUnderAnotherCategory(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->controller()->vacancy(
            'nexunt',
            VacancyCategories::Traineeships->value,
            'backend-engineer',
        );
    }

    /**
     * A category that does not exist cannot name a vacancy, so it 404s before any query is made.
     */
    public function testAnUnknownCategoryIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->controller()->vacancy(
            'nexunt',
            'not-a-category',
            'backend-engineer',
        );
    }

    public function testAnUnknownVacancyIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->controller()->vacancy(
            'nexunt',
            VacancyCategories::Jobs->value,
            'no-such-vacancy',
        );
    }

    /**
     * A vacancy whose window has closed is approved and published, but the public page is gone all the same.
     */
    public function testAVacancyOutsideItsWindowIsNotFound(): void
    {
        $vacancy = $this->entityManager->getRepository(Vacancy::class)->findOneBy([
            'slugName' => 'platform-internship',
        ]);
        self::assertInstanceOf(
            Vacancy::class,
            $vacancy,
        );
        self::assertFalse($vacancy->isActive());

        $this->expectException(NotFoundHttpException::class);
        $this->controller()->vacancy(
            'nexunt',
            VacancyCategories::Internships->value,
            'platform-internship',
        );
    }

    private function controller(): CareerController
    {
        return self::getContainer()->get(CareerController::class);
    }
}
