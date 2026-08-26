<?php

declare(strict_types=1);

namespace App\Tests\Integration\Form\Career;

use App\Entity\Career\Company;
use App\Entity\Career\CompanyJobPackage;
use App\Entity\Career\Enums\VacancyCategories;
use App\Entity\Career\Vacancy;
use App\Form\Career\VacancyProfile\VacancyData;
use App\Form\Career\VacancyProfile\VacancyFlowType;
use App\Repository\Career\CompanyRepository;
use App\Repository\Career\VacancyRepository;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\Form\Flow\DataStorage\NullDataStorage;
use Symfony\Component\Form\Flow\FormFlowInterface;
use Symfony\Component\Form\FormFactoryInterface;

use function count;

final class VacancyFlowTypeTest extends DatabaseTestCase
{
    public function testACompleteGeneralStepIsAccepted(): void
    {
        $flow = $this->submitGeneral();

        self::assertTrue(
            $flow->isValid(),
            (string) $flow->getErrors(true),
        );
    }

    public function testTheBoardDecidesWhetherAVacancyIsShown(): void
    {
        self::assertTrue($this->build(admin: true)->get(VacancyData::STEP_GENERAL)->has('published'));
        self::assertFalse($this->build()->get(VacancyData::STEP_GENERAL)->has('published'));
    }

    public function testAVacancyCannotCloseBeforeItOpens(): void
    {
        $flow = $this->submitGeneral([
            'startDate' => '2027-06-01',
            'endDate' => '2027-05-01',
        ]);

        self::assertFalse($flow->isValid());
        self::assertCount(
            1,
            $flow->get(VacancyData::STEP_GENERAL)->get('endDate')->getErrors(),
        );
    }

    /**
     * A vacancy is invisible once its package expires whatever its own window says, so a window that runs past the
     * package would promise something it cannot keep.
     */
    public function testAVacancyCannotStayOpenPastItsJobPackage(): void
    {
        $flow = $this->submitGeneral(['endDate' => '2200-01-01']);

        self::assertFalse($flow->isValid());
        self::assertCount(
            1,
            $flow->get(VacancyData::STEP_GENERAL)->get('endDate')->getErrors(),
        );
    }

    /**
     * A package is already gone on the day it expires, while a vacancy is still shown on its closing day, so the two
     * dates being the same would advertise a vacancy that cannot be opened.
     */
    public function testAVacancyCannotCloseOnTheDayItsJobPackageExpires(): void
    {
        $flow = $this->submitGeneral(['endDate' => '2100-01-01']);

        self::assertFalse($flow->isValid());
        self::assertCount(
            1,
            $flow->get(VacancyData::STEP_GENERAL)->get('endDate')->getErrors(),
        );
    }

    /**
     * Two vacancies of one company sharing a slug within a category would share a public address, and only the older
     * one would ever be reached.
     */
    public function testASlugAlreadyUsedInTheSameCategoryIsRejected(): void
    {
        $flow = $this->submitGeneral(['slugName' => 'backend-engineer']);

        self::assertFalse($flow->isValid());
        self::assertCount(
            1,
            $flow->get(VacancyData::STEP_GENERAL)->get('slugName')->getErrors(),
        );
    }

    /**
     * The same slug in another category is a different address, so nothing is in the way.
     */
    public function testTheSameSlugInAnotherCategoryIsFree(): void
    {
        $flow = $this->submitGeneral([
            'slugName' => 'backend-engineer',
            'category' => VacancyCategories::Internships->value,
        ]);

        self::assertTrue(
            $flow->isValid(),
            (string) $flow->getErrors(true),
        );
    }

    public function testTheClosingDayIsRequired(): void
    {
        $flow = $this->submitGeneral(['endDate' => '']);

        self::assertFalse($flow->isValid());
    }

    public function testAMalformedSlugIsRejected(): void
    {
        $flow = $this->submitGeneral(['slugName' => 'Not A Slug']);

        self::assertFalse($flow->isValid());
        self::assertNotCount(
            0,
            $flow->get(VacancyData::STEP_GENERAL)->get('slugName')->getErrors(),
        );
    }

    public function testAnEnabledLanguageMustBeFilledIn(): void
    {
        $flow = $this->submitDetails([
            'languageEnglish' => '1',
            'nameEN' => 'Backend Engineer',
        ]);

        self::assertFalse($flow->isValid());
        self::assertNotCount(
            0,
            $flow->get(VacancyData::STEP_DETAILS)->get('locationEN')->getErrors(),
        );
    }

    public function testWithNoLanguageEnabledNothingCanBeSaved(): void
    {
        $flow = $this->submitDetails([]);

        self::assertFalse($flow->isValid());
        self::assertNotCount(
            0,
            $flow->get(VacancyData::STEP_DETAILS)->get('languageDutch')->getErrors(),
        );
    }

    /**
     * A company only gets to choose among its own running job packages, so it cannot post under somebody else's
     * contract.
     */
    public function testTheCompanysFormOnlyOffersItsOwnRunningPackages(): void
    {
        $company = $this->company('nexunt');
        $wanted = [];

        foreach ($company->getPackages() as $package) {
            if (!$package instanceof CompanyJobPackage) {
                continue;
            }

            $wanted[] = (int) $package->getId();
        }

        $choices = $this->build(company: $company)
            ->get(VacancyData::STEP_GENERAL)
            ->get('packageId')
            ->createView()->vars['choices'];

        self::assertNotEmpty($choices);
        self::assertNotSame(
            0,
            count($wanted),
        );

        foreach ($choices as $choice) {
            self::assertContains(
                (int) $choice->value,
                $wanted,
            );
        }
    }

    /**
     * The package a vacancy is already sold under stays choosable, or the select comes up empty on edit and the only
     * way to save is to move the vacancy to somebody else's contract.
     */
    public function testThePackageAVacancyIsAlreadySoldUnderStaysChoosable(): void
    {
        $vacancy = $this->seededVacancy('backend-engineer');
        $current = (int) $vacancy->getPackage()->getId();

        $data = new VacancyData();
        $data->step = VacancyData::STEP_GENERAL;

        $choices = self::getContainer()->get(FormFactoryInterface::class)->create(
            VacancyFlowType::class,
            $data,
            [
                'csrf_protection' => false,
                'data_storage' => new NullDataStorage(),
                'admin' => true,
                'company' => $vacancy->getCompany(),
                'current_package_id' => $current,
            ],
        )->get(VacancyData::STEP_GENERAL)->get('packageId')->createView()->vars['choices'];

        $values = [];

        foreach ($choices as $choice) {
            $values[] = (int) $choice->value;
        }

        self::assertContains(
            $current,
            $values,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function submitGeneral(array $overrides = []): FormFlowInterface
    {
        $company = $this->company('nexunt');
        $flow = $this->build(admin: true);

        $general = $overrides + [
            'slugName' => 'a-new-role',
            'packageId' => (string) $this->jobPackage($company)->getId(),
            'published' => '1',
            'category' => VacancyCategories::Jobs->value,
            'labelIds' => [],
            'startDate' => '2026-09-01',
            'endDate' => '2027-01-31',
        ];

        $flow->submit([
            VacancyData::STEP_GENERAL => $general,
            'next' => '',
        ]);

        return $flow;
    }

    /**
     * @param array<string, mixed> $details
     */
    private function submitDetails(array $details): FormFlowInterface
    {
        $data = new VacancyData();
        $data->step = VacancyData::STEP_DETAILS;

        $flow = self::getContainer()->get(FormFactoryInterface::class)->create(
            VacancyFlowType::class,
            $data,
            [
                'csrf_protection' => false,
                'data_storage' => new NullDataStorage(),
                'admin' => true,
            ],
        );

        $flow->submit([
            VacancyData::STEP_DETAILS => $details,
            'next' => '',
        ]);

        return $flow;
    }

    private function build(
        bool $admin = false,
        ?Company $company = null,
    ): FormFlowInterface {
        return self::getContainer()->get(FormFactoryInterface::class)->create(
            VacancyFlowType::class,
            new VacancyData(),
            [
                'csrf_protection' => false,
                'data_storage' => new NullDataStorage(),
                'admin' => $admin,
                'company' => $company,
            ],
        );
    }

    private function jobPackage(Company $company): CompanyJobPackage
    {
        foreach ($company->getPackages() as $package) {
            if (!$package instanceof CompanyJobPackage) {
                continue;
            }

            return $package;
        }

        self::fail('The seed is expected to give every company a job package.');
    }

    private function seededVacancy(string $slug): Vacancy
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
}
