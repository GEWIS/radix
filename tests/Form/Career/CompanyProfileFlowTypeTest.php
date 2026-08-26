<?php

declare(strict_types=1);

namespace App\Tests\Form\Career;

use App\Entity\Career\Company;
use App\Entity\Career\CompanyRevision;
use App\Form\Career\CompanyProfile\CompanyProfileData;
use App\Form\Career\CompanyProfile\CompanyProfileFlowType;
use App\Form\Career\CompanyProfile\ContactStepType;
use App\Form\Career\CompanyProfile\IdentityStepType;
use App\Form\Career\CompanyProfile\LogoStepType;
use App\Form\Career\CompanyProfile\ProfileStepType;
use App\Util\Application\SlugRule;
use Override;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Flow\FormFlowInterface;
use Symfony\Component\Form\FormExtensionInterface;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Validation;

use function assert;

/**
 * One flow serves the board and the company itself. What separates them is not a permission check inside the form but
 * which steps exist at all: how a company is identified and whether it is shown is the board's call, so a
 * representative's flow is simply not built with that step.
 */
// TypeTestCase creates an unconfigured EventDispatcher mock internally; opt out of the no-expectations notice.
#[AllowMockObjectsWithoutExpectations]
final class CompanyProfileFlowTypeTest extends TypeTestCase
{
    /**
     * @return list<FormExtensionInterface>
     */
    #[Override]
    protected function getExtensions(): array
    {
        return [
            new ValidatorExtension(Validation::createValidator()),
        ];
    }

    /**
     * @return list<CompanyProfileFlowType|IdentityStepType|ProfileStepType|ContactStepType|LogoStepType>
     */
    #[Override]
    protected function getTypes(): array
    {
        return [
            new CompanyProfileFlowType(new RequestStack()),
            new IdentityStepType(),
            new ProfileStepType(),
            new ContactStepType(),
            new LogoStepType(),
        ];
    }

    public function testTheBoardIsAskedForTheNameTheSlugAndWhetherItIsShown(): void
    {
        $flow = $this->flow(['admin' => true]);

        self::assertSame(
            CompanyProfileData::STEP_IDENTITY,
            $flow->getCursor()->getFirstStep(),
        );

        foreach (
            [
                'name',
                'slugName',
                'published',
            ] as $field
        ) {
            self::assertTrue(
                $flow->get(CompanyProfileData::STEP_IDENTITY)->has($field),
                $field,
            );
        }
    }

    public function testACompanyEditingItselfNeverReachesThatStep(): void
    {
        $flow = $this->flow();

        self::assertNotContains(
            CompanyProfileData::STEP_IDENTITY,
            $flow->getCursor()->getSteps(),
        );
        self::assertSame(
            CompanyProfileData::STEP_PROFILE,
            $flow->getCursor()->getFirstStep(),
        );
    }

    public function testTheContentAndContactStepsAreOfferedToBothAudiences(): void
    {
        $steps = $this->flow()->getCursor()->getSteps();

        foreach (
            [
                CompanyProfileData::STEP_PROFILE,
                CompanyProfileData::STEP_CONTACT,
                CompanyProfileData::STEP_LOGO,
            ] as $step
        ) {
            self::assertContains(
                $step,
                $steps,
            );
        }
    }

    public function testBothLogosAreDemandedUntilTheProfileCarriesThem(): void
    {
        foreach (
            [
                'squareLogoFile',
                'bannerLogoFile',
            ] as $field
        ) {
            self::assertTrue(
                $this->logoIsRequired(
                    $field,
                    false,
                ),
                $field,
            );
            self::assertFalse(
                $this->logoIsRequired(
                    $field,
                    true,
                ),
                $field,
            );
        }
    }

    private function logoIsRequired(
        string $field,
        bool $stored,
    ): bool {
        $data = new CompanyProfileData();
        $data->step = CompanyProfileData::STEP_LOGO;

        return $this->flow(
            [
                'has_square_logo' => $stored,
                'has_banner_logo' => $stored,
            ],
            $data,
        )->get(CompanyProfileData::STEP_LOGO)->get($field)->getConfig()->getRequired();
    }

    /**
     * @param array<string, mixed> $options
     */
    private function flow(
        array $options = [],
        ?CompanyProfileData $data = null,
    ): FormFlowInterface {
        $flow = $this->factory->create(
            CompanyProfileFlowType::class,
            $data ?? new CompanyProfileData(),
            $options,
        );
        assert($flow instanceof FormFlowInterface);

        return $flow;
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function slugs(): iterable
    {
        yield 'a plain slug' => [
            'nexunt',
            true,
        ];

        yield 'with a hyphen and a digit' => [
            'delta-robotics-2',
            true,
        ];

        yield 'with a capital' => [
            'Nexunt',
            false,
        ];

        yield 'with a space' => [
            'delta robotics',
            false,
        ];

        yield 'starting with a digit' => [
            '3m',
            false,
        ];
    }

    /**
     * The slug carries the same rule the routes and the migration use, so a company cannot be given a name it could
     * never be reached under.
     */
    #[DataProvider('slugs')]
    public function testTheSlugEnforcesTheSlugRule(
        string $slug,
        bool $acceptable,
    ): void {
        $regex = null;

        foreach (
            new ReflectionProperty(
                CompanyProfileData::class,
                'slugName',
            )->getAttributes() as $attribute
        ) {
            if (Regex::class !== $attribute->getName()) {
                continue;
            }

            $instance = $attribute->newInstance();
            assert($instance instanceof Regex);
            $regex = $instance;
        }

        self::assertNotNull($regex);
        self::assertSame(
            SlugRule::PATTERN,
            $regex->pattern,
        );
        self::assertSame(
            $acceptable,
            SlugRule::matches($slug),
        );
    }

    public function testTheProfileIsBuiltFromTheCompanyAndItsRevision(): void
    {
        $company = new Company();
        $company->setName('Nexunt');
        $company->setSlugName('nexunt');
        $company->setPublished(true);

        $revision = new CompanyRevision();
        $revision->getSlogan()->updateValues(
            'We build things',
            null,
        );

        $data = CompanyProfileData::fromCompany(
            $company,
            $revision,
        );

        self::assertSame(
            'Nexunt',
            $data->name,
        );
        self::assertSame(
            'We build things',
            $data->sloganEN,
        );
        self::assertTrue($data->languageEnglish);
        self::assertFalse($data->languageDutch);
    }
}
