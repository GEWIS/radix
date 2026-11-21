<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Career;

use App\Entity\Career\CompanyJobPackage;
use App\Entity\Career\Vacancy;
use App\Entity\Career\VacancyRevision;
use App\Entity\User\CompanyUser;
use App\Entity\User\User;
use App\Repository\User\CompanyUserRepository;
use App\Service\Career\VacancyDraftService;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;

/**
 * Saving a draft and letting go of the edit lock are one operation: a save that commits but keeps the lock blocks the
 * author out of their own draft until it lapses, which is the bug this service exists to make impossible.
 */
final class VacancyDraftServiceTest extends DatabaseTestCase
{
    public function testANewVacancyAndItsFirstDraftArePersistedTogether(): void
    {
        $package = $this->entityManager->getRepository(CompanyJobPackage::class)->findOneBy([]);
        self::assertInstanceOf(
            CompanyJobPackage::class,
            $package,
        );

        $vacancy = new Vacancy();
        $vacancy->slugName = 'brand-new-vacancy';
        $vacancy->published = true;
        $vacancy->package = $package;
        $revision = new VacancyRevision();
        $revision->endDate = new DateTimeImmutable('+1 month');
        $vacancy->addRevision($revision);
        $vacancy->setCurrentRevision($revision);

        $this->service()->createDraft(
            $vacancy,
            $revision,
        );

        self::assertNotNull($vacancy->id);
        self::assertNotNull($revision->id);
        self::assertSame(
            $vacancy,
            $revision->vacancy,
        );
    }

    public function testSavingRecordsTheBoardMemberWhoEditedIt(): void
    {
        $vacancy = $this->vacancy();
        $revision = $this->revision($vacancy);
        $user = $this->user();

        $this->service()->saveDraft(
            $vacancy,
            $revision,
            $user,
        );

        self::assertSame(
            $user,
            $revision->lastEditedBy,
        );
        self::assertNull($revision->lastEditedByCompanyUser);
    }

    public function testSavingRecordsTheRepresentativeWhoEditedIt(): void
    {
        $vacancy = $this->vacancy();
        $revision = $this->revision($vacancy);
        $companyUser = $this->companyUser();

        $this->service()->saveDraft(
            $vacancy,
            $revision,
            $companyUser,
        );

        self::assertSame(
            $companyUser,
            $revision->lastEditedByCompanyUser,
        );
        self::assertNull($revision->lastEditedBy);
    }

    private function service(): VacancyDraftService
    {
        return self::getContainer()->get(VacancyDraftService::class);
    }

    private function vacancy(): Vacancy
    {
        $vacancy = $this->entityManager->getRepository(Vacancy::class)->findOneBy(['slugName' => 'backend-engineer']);
        self::assertInstanceOf(
            Vacancy::class,
            $vacancy,
        );

        return $vacancy;
    }

    private function revision(Vacancy $vacancy): VacancyRevision
    {
        $revision = $vacancy->getCurrentRevision();
        self::assertInstanceOf(
            VacancyRevision::class,
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
}
