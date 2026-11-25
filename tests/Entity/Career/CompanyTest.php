<?php

declare(strict_types=1);

namespace App\Tests\Entity\Career;

use App\Entity\Activity\ActivityRevision;
use App\Entity\Career\Company;
use App\Entity\Career\CompanyBannerPackage;
use App\Entity\Career\CompanyFeaturedPackage;
use App\Entity\Career\CompanyHighlightPackage;
use App\Entity\Career\CompanyJobPackage;
use App\Entity\Career\CompanyPackage;
use App\Entity\Career\CompanyRevision;
use App\Entity\Career\Vacancy;
use App\Entity\User\CompanyUser;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * What a company derives about itself: which revision is shown, whether it is visible at all, and what its packages add
 * up to. A company is live only through one of its revisions and shown only while one of its packages runs, so both
 * directions are tested for refusal and acceptance.
 */
#[CoversClass(Company::class)]
final class CompanyTest extends TestCase
{
    public function testACompanyCanOnlyBeMadeLiveByItsOwnRevision(): void
    {
        $company = $this->company();

        $this->expectException(RuntimeException::class);

        $company->markRevisionLive(new ActivityRevision());
    }

    public function testMakingOneOfItsOwnRevisionsLiveSaysWhichIsShown(): void
    {
        $company = $this->company();
        $revision = $this->revision('Shown');
        $company->addRevision($revision);

        $company->markRevisionLive($revision);

        self::assertSame(
            $revision,
            $company->getLiveRevision(),
        );
    }

    public function testRestoringPutsTheLiveRevisionBackAtTheWorkingHead(): void
    {
        $company = $this->company();
        $working = $this->revision('Working');
        $live = $this->revision('Live');
        $company->addRevision($working);
        $company->addRevision($live);
        $company->setCurrentRevision($working);
        $company->setLiveRevision($live);

        $company->restoreLiveRevision();

        self::assertSame(
            $live,
            $company->getCurrentRevision(),
        );

        $company->detachRevisions();

        self::assertNull($company->getCurrentRevision());
        self::assertNull($company->getLiveRevision());
    }

    public function testACompanyWithoutRevisionsHasNothingToShow(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Company has no revision to display.');

        $this->company()->getContactName();
    }

    public function testTheLiveRevisionIsShownRatherThanTheWorkingHead(): void
    {
        $company = $this->company();
        $working = $this->revision('Working');
        $company->addRevision($working);
        $company->setCurrentRevision($working);

        self::assertSame(
            'Working',
            $company->getContactName(),
        );

        $live = $this->revision('Live');
        $company->addRevision($live);
        $company->setLiveRevision($live);

        self::assertSame(
            'Live',
            $company->getContactName(),
        );
    }

    public function testAPrimaryContactMustBeOneOfTheCompanysOwnPeople(): void
    {
        $company = $this->company();

        // Nothing chosen is not a mistake: the board does not have to name anybody.
        $company->assertPrimaryContactBelongsToCompany();

        $own = new CompanyUser();
        $own->company = $company;
        $company->primaryContact = $own;
        $company->assertPrimaryContactBelongsToCompany();

        $company->primaryContact = new CompanyUser();
        $company->primaryContact->company = $this->company();

        $this->expectException(RuntimeException::class);
        $company->assertPrimaryContactBelongsToCompany();
    }

    public function testACompanyIsOnlyShownWhileSomethingOfTheirsRuns(): void
    {
        $company = $this->company();

        // Nothing approved yet: never shown.
        self::assertTrue($company->isHidden());

        $company->setLiveRevision($this->revision('Live'));
        $company->published = true;
        $company->getPackages()->add($this->package(
            new CompanyHighlightPackage(),
            '-1 month',
            '+1 month',
        ));

        self::assertFalse($company->isHidden());

        // Switched off by the board.
        $company->published = false;
        self::assertTrue($company->isHidden());
        $company->published = true;

        // Only an expired package left.
        $expired = $this->company();
        $expired->setLiveRevision($this->revision('Live'));
        $expired->published = true;
        $expired->getPackages()->add($this->package(
            new CompanyHighlightPackage(),
            '-2 months',
            '-1 month',
        ));

        self::assertTrue($expired->isHidden());
    }

    public function testThePackagesAreCountedAcrossTheirKinds(): void
    {
        $company = $this->company();

        $jobs = $this->package(
            new CompanyJobPackage(),
            '-1 month',
            '+1 month',
        );
        self::assertInstanceOf(
            CompanyJobPackage::class,
            $jobs,
        );
        $jobs->addVacancy(new Vacancy());
        $jobs->addVacancy(new Vacancy());

        $expiredFeatured = $this->package(
            new CompanyFeaturedPackage(),
            '-2 months',
            '-1 day',
        );

        $company->getPackages()->add($jobs);
        $company->getPackages()->add($expiredFeatured);

        self::assertSame(
            2,
            $company->getNumberOfPackages(),
        );
        self::assertSame(
            2,
            $company->getNumberOfJobs(),
        );
        // Neither vacancy has been approved, so neither is being offered.
        self::assertSame(
            0,
            $company->getNumberOfActiveJobs(),
        );
        self::assertSame(
            [],
            $company->getActiveVacancies(),
        );
        self::assertSame(
            1,
            $company->getNumberOfExpiredPackages(),
        );
        self::assertFalse($company->isFeatured());

        $featured = $this->package(
            new CompanyFeaturedPackage(),
            '-1 day',
            '+1 month',
        );
        $company->getPackages()->add($featured);

        self::assertTrue($company->isFeatured());
        self::assertSame(
            $featured,
            $company->getFeaturedPackage(),
        );
        self::assertFalse($company->isBannerActive());

        $company->getPackages()->add($this->package(
            new CompanyBannerPackage(),
            '-1 day',
            '+1 month',
        ));

        self::assertTrue($company->isBannerActive());
    }

    public function testTheResourceIdIsTheCompanysOwn(): void
    {
        self::assertSame(
            'company',
            $this->company()->getResourceId(),
        );
    }

    private function company(): Company
    {
        return new Company();
    }

    private function revision(string $contactName): CompanyRevision
    {
        $revision = new CompanyRevision();
        $revision->contactName = $contactName;

        return $revision;
    }

    /**
     * @template T of CompanyPackage
     *
     * @param T $package
     *
     * @return T
     */
    private function package(
        CompanyPackage $package,
        string $starts,
        string $expires,
    ): CompanyPackage {
        $package->setStartingDate(new DateTimeImmutable($starts));
        $package->setExpirationDate(new DateTimeImmutable($expires));
        $package->published = true;

        return $package;
    }
}
