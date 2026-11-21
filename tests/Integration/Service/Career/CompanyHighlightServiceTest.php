<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Career;

use App\Entity\Career\Company;
use App\Entity\Career\Enums\CompanyAuditVerbs;
use App\Entity\User\CompanyUser;
use App\Repository\Career\CompanyAuditLogRepository;
use App\Repository\Career\CompanyRepository;
use App\Repository\User\CompanyUserRepository;
use App\Service\Career\CompanyHighlightService;
use App\Tests\Integration\DatabaseTestCase;

/**
 * Changing which vacancies are highlighted is a change to what the career landing page shows, so it leaves a line
 * on the company's timeline.
 */
final class CompanyHighlightServiceTest extends DatabaseTestCase
{
    public function testASavedSelectionReachesTheTimeline(): void
    {
        $company = $this->company('nexunt');
        $companyUser = $this->representative('recruitment@nexunt.example.com');

        self::getContainer()->get(CompanyHighlightService::class)->saveSelection(
            $company,
            $companyUser,
        );

        $timeline = self::getContainer()->get(CompanyAuditLogRepository::class)
            ->findRecentForCompany($company);
        self::assertNotEmpty($timeline);
        self::assertSame(
            CompanyAuditVerbs::HighlightSelectionChanged,
            $timeline[0]->verb,
        );
        self::assertSame(
            $companyUser,
            $timeline[0]->actorCompanyUser,
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

    private function company(string $slug): Company
    {
        $company = self::getContainer()->get(CompanyRepository::class)
            ->findOneBy(['slugName' => $slug]);
        self::assertInstanceOf(
            Company::class,
            $company,
        );

        return $company;
    }
}
