<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Career;

use App\Entity\Career\Company;
use App\Entity\Career\CompanyRevision;
use App\Entity\Career\Vacancy;
use App\Entity\Career\VacancyRevision;
use App\Entity\User\CompanyUser;
use App\Service\Career\CompanyStaleRevisionPolicy;
use App\Tests\Integration\DatabaseTestCase;

/**
 * When an abandoned company profile may be cleaned up. Removing the company also removes its accounts and its
 * timeline, so anything that shows the arrangement became real must stop the cleanup.
 */
final class CompanyStaleRevisionPolicyTest extends DatabaseTestCase
{
    public function testThePolicysSubjectIsTheCompanyRevision(): void
    {
        self::assertSame(
            CompanyRevision::class,
            $this->policy()->revisionClass(),
        );
    }

    public function testACompanyNothingDependsOnIsNotBlocked(): void
    {
        $company = $this->company('empty-company');

        self::assertNull($this->policy()->deletionBlockedBy($company));
    }

    public function testACompanySoldAPackageIsKept(): void
    {
        $company = $this->getCompany('nexunt');

        $block = $this->policy()->deletionBlockedBy($company);

        self::assertNotNull($block);
        self::assertFalse($block->forceable);
        self::assertSame(
            'it has already been sold a package',
            $block->reason,
        );
    }

    public function testACompanySomebodyRepresentsIsKept(): void
    {
        $company = $this->company('represented-company');

        $representative = new CompanyUser();
        $representative->company = $company;
        $representative->name = 'A Representative';
        $representative->email = 'someone@represented.example.com';
        $representative->setPassword('hash');
        $this->entityManager->persist($representative);
        $this->entityManager->flush();

        $block = $this->policy()->deletionBlockedBy($company);

        self::assertNotNull($block);
        self::assertFalse($block->forceable);
        self::assertSame(
            'a representative still has an account for it',
            $block->reason,
        );
    }

    public function testThePolicyIgnoresSomethingThatIsNotACompany(): void
    {
        self::assertNull($this->policy()->deletionBlockedBy(new Vacancy()));
    }

    public function testOnlyTheLogosOfACompanyRevisionAreStoredPaths(): void
    {
        $revision = new CompanyRevision();
        $revision->squareLogo = 'career/1/images/aa/square.png';
        $revision->bannerLogo = null;

        self::assertSame(
            ['career/1/images/aa/square.png'],
            $this->policy()->storedPaths($revision),
        );
    }

    public function testARevisionOfAnotherDomainHasNoStoredPathsHere(): void
    {
        self::assertSame(
            [],
            $this->policy()->storedPaths(new VacancyRevision()),
        );
    }

    private function company(string $slug): Company
    {
        $company = new Company();
        $company->name = 'A Company';
        $company->slugName = $slug;
        $company->published = false;
        $this->entityManager->persist($company);
        $this->entityManager->flush();

        return $company;
    }

    private function getCompany(string $slug): Company
    {
        $company = $this->entityManager->getRepository(Company::class)
            ->findOneBy(['slugName' => $slug]);
        self::assertInstanceOf(
            Company::class,
            $company,
        );

        return $company;
    }

    private function policy(): CompanyStaleRevisionPolicy
    {
        return self::getContainer()->get(CompanyStaleRevisionPolicy::class);
    }
}
