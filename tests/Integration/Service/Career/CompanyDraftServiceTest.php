<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Career;

use App\Entity\Career\Company;
use App\Entity\Career\CompanyRevision;
use App\Entity\User\CompanyUser;
use App\Entity\User\User;
use App\Repository\Career\CompanyAuditLogRepository;
use App\Repository\User\CompanyUserRepository;
use App\Service\Career\CompanyDraftService;
use App\Tests\Integration\DatabaseTestCase;
use Override;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function file_put_contents;
use function imagecreatetruecolor;
use function imagepng;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * A save stores the logos, records who edited, commits and releases the edit lock as one operation, and a logo that
 * could not be stored costs the author neither their text nor the logo that was already there.
 */
final class CompanyDraftServiceTest extends DatabaseTestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    #[Override]
    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        $this->tempFiles = [];

        parent::tearDown();
    }

    public function testANewCompanyAndItsFirstDraftArePersistedTogether(): void
    {
        $company = $this->newCompany();

        $stored = $this->service()->create(
            $company,
            $revision = $this->firstRevision($company),
            $this->user(),
            null,
            null,
        );

        self::assertTrue($stored);
        self::assertNotNull($company->id);
        self::assertNotNull($revision->id);
        self::assertSame(
            $company,
            $revision->company,
        );
        // The auditor's entry is part of the same commit.
        self::assertNotEmpty(
            self::getContainer()->get(CompanyAuditLogRepository::class)
                ->findRecentForCompany($company),
        );
    }

    public function testTheLogosAHiringCompanyUploadedAreStoredOnTheRevision(): void
    {
        $company = $this->newCompany();

        $stored = $this->service()->create(
            $company,
            $revision = $this->firstRevision($company),
            $this->user(),
            $this->png(),
            null,
        );

        self::assertTrue($stored);
        self::assertNotNull($revision->squareLogo);
    }

    /**
     * A refused upload costs the author neither their text nor the logo already in place: the save succeeds, the old
     * logo stays and the caller is told.
     */
    public function testALogoThatCannotBeStoredIsReportedAndLeavesTheOldOne(): void
    {
        $company = $this->newCompany();
        $revision = $this->firstRevision($company);
        $revision->squareLogo = 'career/company/1/previous-logo.png';
        $this->entityManager->persist($company);
        $this->entityManager->persist($revision);
        $this->entityManager->flush();

        $stored = $this->service()->saveDraft(
            $company,
            $revision,
            $this->user(),
            $this->notAnImage(),
            null,
        );

        self::assertFalse($stored);
        self::assertSame(
            'career/company/1/previous-logo.png',
            $revision->squareLogo,
        );
    }

    public function testSavingRecordsTheRepresentativeWhoEditedIt(): void
    {
        $company = $this->company();
        $revision = $this->currentRevision($company);
        $companyUser = $this->companyUser();

        $this->service()->saveDraft(
            $company,
            $revision,
            $companyUser,
            null,
            null,
        );

        self::assertSame(
            $companyUser,
            $revision->lastEditedByCompanyUser,
        );
        self::assertNull($revision->lastEditedBy);
    }

    private function service(): CompanyDraftService
    {
        return self::getContainer()->get(CompanyDraftService::class);
    }

    private function newCompany(): Company
    {
        $company = new Company();
        $company->name = 'Testworks';
        $company->slugName = 'testworks';
        $company->published = false;

        return $company;
    }

    private function firstRevision(Company $company): CompanyRevision
    {
        $revision = new CompanyRevision();
        $company->addRevision($revision);
        $company->setCurrentRevision($revision);

        return $revision;
    }

    private function company(): Company
    {
        $company = $this->entityManager->getRepository(Company::class)->findOneBy(['slugName' => 'nexunt']);
        self::assertInstanceOf(
            Company::class,
            $company,
        );

        return $company;
    }

    private function currentRevision(Company $company): CompanyRevision
    {
        $revision = $company->getCurrentRevision();
        self::assertInstanceOf(
            CompanyRevision::class,
            $revision,
        );

        return $revision;
    }

    private function user(): User
    {
        $user = $this->entityManager->getRepository(User::class)->find(8000);
        self::assertInstanceOf(
            User::class,
            $user,
        );

        return $user;
    }

    private function companyUser(): CompanyUser
    {
        $companyUser = self::getContainer()->get(CompanyUserRepository::class)
            ->loadUserByIdentifier('recruitment@nexunt.example.com');
        self::assertInstanceOf(
            CompanyUser::class,
            $companyUser,
        );

        return $companyUser;
    }

    private function png(): UploadedFile
    {
        $path = $this->tempFile();
        $image = imagecreatetruecolor(
            120,
            120,
        );
        self::assertNotFalse($image);
        imagepng(
            $image,
            $path,
        );

        return new UploadedFile(
            $path,
            'logo.png',
            'image/png',
            null,
            true,
        );
    }

    private function notAnImage(): UploadedFile
    {
        $path = $this->tempFile();
        file_put_contents(
            $path,
            'this is not an image',
        );

        return new UploadedFile(
            $path,
            'logo.png',
            'image/png',
            null,
            true,
        );
    }

    private function tempFile(): string
    {
        $path = tempnam(
            sys_get_temp_dir(),
            'company-logo-',
        );
        self::assertIsString($path);
        $this->tempFiles[] = $path;

        return $path;
    }
}
