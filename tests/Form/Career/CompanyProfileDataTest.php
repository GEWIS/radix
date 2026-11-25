<?php

declare(strict_types=1);

namespace App\Tests\Form\Career;

use App\Entity\Career\CareerLocalisedText;
use App\Entity\Career\Company;
use App\Entity\Career\CompanyRevision;
use App\Form\Career\CompanyProfile\CompanyProfileData;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The data object behind the company stepper: what it reads from a company, what it writes back, and the language
 * rules the profile step enforces without the browser.
 */
final class CompanyProfileDataTest extends TestCase
{
    public function testACompanyWithNoNameYetReadsAsEmptyRatherThanBlankStrings(): void
    {
        $company = new Company();
        $company->name = '';
        $company->slugName = '';
        $company->published = false;

        $data = CompanyProfileData::fromCompany(
            $company,
            new CompanyRevision(),
        );

        self::assertNull($data->name);
        self::assertNull($data->slugName);
        self::assertFalse($data->published);
        // A brand-new profile defaults to English, so the form is usable at once.
        self::assertTrue($data->languageEnglish);
        self::assertFalse($data->languageDutch);
    }

    public function testADutchProfileTurnsTheDutchToggleOnAndTheEnglishOneOff(): void
    {
        $company = new Company();
        $company->name = 'Nexunt';
        $company->slugName = 'nexunt';
        $company->published = true;

        $revision = new CompanyRevision();
        $revision->slogan = new CareerLocalisedText(
            '',
            'Wij bouwen dingen',
        );

        $data = CompanyProfileData::fromCompany(
            $company,
            $revision,
        );

        self::assertTrue($data->languageDutch);
        self::assertFalse($data->languageEnglish);
    }

    public function testWritingBackTouchesIdentityOnlyWhenThatStepWasThere(): void
    {
        $company = new Company();
        $company->name = 'Nexunt';
        $company->slugName = 'nexunt';
        $company->published = true;

        $revision = new CompanyRevision();
        $data = $this->filled();

        $data->applyTo(
            $company,
            $revision,
            false,
        );

        self::assertSame(
            'Nexunt',
            $company->name,
        );
        self::assertSame(
            'nexunt',
            $company->slugName,
        );
        self::assertTrue($company->published);
        self::assertSame(
            'New slogan',
            $revision->slogan->getValueEN(),
        );
        self::assertSame(
            'New website',
            $revision->website->getValueEN(),
        );
        self::assertSame(
            'New description',
            $revision->description->getValueEN(),
        );
        self::assertSame(
            'Contact Person',
            $revision->contactName,
        );
        self::assertSame(
            ['instagram' => 'contact'],
            $revision->getSocialHandles(),
        );
    }

    public function testTheBoardCanRewriteWhatIdentifiesTheCompany(): void
    {
        $company = new Company();
        $company->name = 'Old';
        $company->slugName = 'old';
        $company->published = false;

        $this->filled()->applyTo(
            $company,
            new CompanyRevision(),
            true,
        );

        self::assertSame(
            'A Company',
            $company->name,
        );
        self::assertSame(
            'a-company',
            $company->slugName,
        );
        self::assertTrue($company->published);
    }

    public function testAProfileMayNotHaveBothLanguagesOff(): void
    {
        $data = $this->filled();
        $data->languageDutch = false;
        $data->languageEnglish = false;

        $violations = $this->validate($data);

        self::assertCount(
            1,
            $violations,
        );
        self::assertSame(
            'languageDutch',
            $violations[0]->getPropertyPath(),
        );
        self::assertSame(
            'At least one language must be used.',
            $violations[0]->getMessage(),
        );
    }

    public function testADutchProfileHasToSaySomethingInDutch(): void
    {
        $data = $this->filled();
        $data->languageDutch = true;
        $data->languageEnglish = false;
        $data->sloganNL = '   ';
        $data->websiteNL = '';
        $data->descriptionNL = null;

        $violations = $this->validate($data);

        self::assertCount(
            3,
            $violations,
        );
        self::assertSame(
            [
                'sloganNL',
                'websiteNL',
                'descriptionNL',
            ],
            $this->paths($violations),
        );
        self::assertSame(
            'Fill in the Dutch text.',
            $violations[0]->getMessage(),
        );
    }

    public function testAnEnglishProfileHasToSaySomethingInEnglish(): void
    {
        $data = $this->filled();
        $data->languageDutch = false;
        $data->languageEnglish = true;
        $data->sloganEN = '';
        $data->websiteEN = '   ';
        $data->descriptionEN = null;

        $violations = $this->validate($data);

        self::assertCount(
            3,
            $violations,
        );
        self::assertSame(
            [
                'sloganEN',
                'websiteEN',
                'descriptionEN',
            ],
            $this->paths($violations),
        );
        self::assertSame(
            'Fill in the English text.',
            $violations[0]->getMessage(),
        );
    }

    public function testACompleteProfilePassesTheLanguageRules(): void
    {
        self::assertCount(
            0,
            $this->validate($this->filled()),
        );
    }

    private function filled(): CompanyProfileData
    {
        $data = new CompanyProfileData();
        $data->name = 'A Company';
        $data->slugName = 'a-company';
        $data->published = true;
        $data->languageEnglish = true;
        $data->sloganEN = 'New slogan';
        $data->websiteEN = 'New website';
        $data->descriptionEN = 'New description';
        $data->contactName = 'Contact Person';
        $data->socialLinks = ['instagram' => 'contact'];

        return $data;
    }

    /**
     * @return list<ConstraintViolationInterface>
     */
    private function validate(CompanyProfileData $data): array
    {
        $violations = $this->validator()->validate(
            $data,
            null,
            [CompanyProfileData::STEP_PROFILE],
        );

        $list = [];
        foreach ($violations as $violation) {
            $list[] = $violation;
        }

        return $list;
    }

    /**
     * @param list<ConstraintViolationInterface> $violations
     *
     * @return list<string>
     */
    private function paths(array $violations): array
    {
        $paths = [];
        foreach ($violations as $violation) {
            $paths[] = $violation->getPropertyPath();
        }

        return $paths;
    }

    private function validator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }
}
