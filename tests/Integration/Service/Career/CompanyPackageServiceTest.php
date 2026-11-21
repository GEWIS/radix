<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Career;

use App\Entity\Career\Company;
use App\Entity\Career\CompanyBannerPackage;
use App\Entity\Career\Enums\CompanyAuditVerbs;
use App\Entity\User\User;
use App\Repository\Career\CompanyAuditLogRepository;
use App\Repository\Career\CompanyRepository;
use App\Service\Career\CompanyPackageService;
use App\Tests\Integration\DatabaseTestCase;

use function assert;

/**
 * Settling a banner records the verdict even when the banner row itself was never persisted, which is the state a
 * brand-new package is in while the form is still being built.
 */
final class CompanyPackageServiceTest extends DatabaseTestCase
{
    public function testSettlingAnUnpersistedBannerStillRecordsWhoDecided(): void
    {
        $company = $this->company('orbit-analytics');
        $user = $this->user();

        $banner = new CompanyBannerPackage();
        $banner->company = $company;

        self::getContainer()->get(CompanyPackageService::class)->settleBanner(
            $banner,
            $user,
            CompanyAuditVerbs::BannerApproved,
            null,
        );

        $timeline = self::getContainer()->get(CompanyAuditLogRepository::class)
            ->findRecentForCompany($company);
        self::assertNotEmpty($timeline);
        self::assertSame(
            CompanyAuditVerbs::BannerApproved,
            $timeline[0]->verb,
        );
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

    private function user(): User
    {
        $user = $this->entityManager->getRepository(User::class)->find(8025);
        assert($user instanceof User);

        return $user;
    }
}
