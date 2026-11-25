<?php

declare(strict_types=1);

namespace App\Tests\Form\Career;

use App\Entity\Career\CompanyFeaturedPackage;
use App\Entity\Career\CompanyJobPackage;
use App\Entity\Career\Enums\CompanyPackageTypes;
use App\Form\Application\LocalisedTextType;
use App\Form\Career\CompanyPackageType;
use App\Form\Extension\ImmutableDateInputExtension;
use Override;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormExtensionInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Translation\IdentityTranslator;
use Symfony\Component\Validator\Validation;

/**
 * The article of a featured package is gated by the two language toggles. The toggles disable the variants of a
 * language that is off in the browser, but the rule has to hold for a submission that skipped the browser as well.
 */
#[CoversClass(CompanyPackageType::class)]
// TypeTestCase creates an unconfigured EventDispatcher mock internally; opt out of the no-expectations notice.
#[AllowMockObjectsWithoutExpectations]
final class CompanyPackageTypeLanguagesTest extends TypeTestCase
{
    /**
     * With both languages off nothing below is required, so a package with no article at all would save; the toggles
     * themselves have to leave one language on.
     */
    public function testAtLeastOneLanguageHasToStayOn(): void
    {
        $form = $this->submitFeatured([
            'languageDutch' => false,
            'languageEnglish' => false,
            'article' => [
                'valueNL' => '',
                'valueEN' => '',
            ],
        ]);

        self::assertFalse($form->isValid());
        self::assertNotCount(
            0,
            $form->get('languageDutch')->getErrors(),
        );
    }

    public function testAnEnabledDutchArticleHasToBeWritten(): void
    {
        $form = $this->submitFeatured([
            'languageDutch' => true,
            'languageEnglish' => false,
            'article' => [
                'valueNL' => '   ',
            ],
        ]);

        self::assertFalse($form->isValid());
        self::assertNotCount(
            0,
            $form->get('article')->get('valueNL')->getErrors(),
        );
        self::assertCount(
            0,
            $form->get('article')->get('valueEN')->getErrors(),
        );
    }

    public function testAnEnabledEnglishArticleHasToBeWritten(): void
    {
        $form = $this->submitFeatured([
            'languageDutch' => false,
            'languageEnglish' => true,
            'article' => [
                'valueEN' => '',
            ],
        ]);

        self::assertFalse($form->isValid());
        self::assertNotCount(
            0,
            $form->get('article')->get('valueEN')->getErrors(),
        );
        self::assertCount(
            0,
            $form->get('article')->get('valueNL')->getErrors(),
        );
    }

    public function testAnArticleInEveryEnabledLanguageSaves(): void
    {
        $form = $this->submitFeatured([
            'languageDutch' => true,
            'languageEnglish' => true,
            'article' => [
                'valueNL' => 'Wij bouwen dingen.',
                'valueEN' => 'We build things.',
            ],
        ]);

        self::assertTrue(
            $form->isValid(),
            (string) $form->getErrors(true),
        );
    }

    /**
     * A language that is off is disabled in the browser and not submitted, which must keep the stored text rather
     * than erase it; otherwise a toggle would drop half the article.
     */
    public function testSwitchingALanguageOffDoesNotEraseTheStoredHalf(): void
    {
        $package = $this->featuredPackage();
        $package->article->updateValues(
            'We build things.',
            'Wij bouwen dingen.',
        );

        $form = $this->factory->create(
            CompanyPackageType::class,
            $package,
            [
                'package_type' => CompanyPackageTypes::Featured,
            ],
        );

        $form->submit([
            'contractNumber' => 'C-1',
            'startingDate' => '2026-01-01',
            'expirationDate' => '2026-12-31',
            'languageDutch' => false,
            'languageEnglish' => true,
            'article' => ['valueEN' => 'We build even more things.'],
        ]);

        self::assertTrue(
            $form->isValid(),
            (string) $form->getErrors(true),
        );
        self::assertSame(
            'We build even more things.',
            $package->article->getValueEN(),
        );
        self::assertSame(
            'Wij bouwen dingen.',
            $package->article->getValueNL(),
        );
    }

    /**
     * A package cannot expire before it starts; the window is the same on every kind of package.
     */
    public function testAPackageCannotExpireBeforeItStarts(): void
    {
        $form = $this->submitFeatured([
            'startingDate' => '2026-12-31',
            'expirationDate' => '2026-01-01',
            'languageDutch' => true,
            'languageEnglish' => true,
            'article' => [
                'valueNL' => 'Artikel',
                'valueEN' => 'Article',
            ],
        ]);

        self::assertFalse($form->isValid());
        self::assertNotCount(
            0,
            $form->get('expirationDate')->getErrors(),
        );
    }

    /**
     * The article and its toggles belong to the featured package alone; another kind is asked for no text, so the
     * language rules cannot block it.
     */
    public function testAnotherPackageKindHasNoArticleToValidate(): void
    {
        $form = $this->factory->create(
            CompanyPackageType::class,
            new CompanyJobPackage(),
            [
                'package_type' => CompanyPackageTypes::Job,
            ],
        );

        self::assertFalse($form->has('article'));
        self::assertFalse($form->has('languageDutch'));

        $form->submit([
            'contractNumber' => 'C-2',
            'startingDate' => '2026-01-01',
            'expirationDate' => '2026-12-31',
        ]);

        self::assertTrue(
            $form->isValid(),
            (string) $form->getErrors(true),
        );
    }

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
     * @return list<ImmutableDateInputExtension>
     */
    #[Override]
    protected function getTypeExtensions(): array
    {
        return [new ImmutableDateInputExtension()];
    }

    /**
     * @return list<CompanyPackageType|LocalisedTextType>
     */
    #[Override]
    protected function getTypes(): array
    {
        return [
            new CompanyPackageType(new IdentityTranslator()),
            new LocalisedTextType(),
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return FormInterface<mixed>
     */
    private function submitFeatured(array $overrides): FormInterface
    {
        $form = $this->factory->create(
            CompanyPackageType::class,
            $this->featuredPackage(),
            [
                'package_type' => CompanyPackageTypes::Featured,
            ],
        );

        $form->submit($overrides + [
            'contractNumber' => 'C-1',
            'startingDate' => '2026-01-01',
            'expirationDate' => '2026-12-31',
            'languageDutch' => true,
            'languageEnglish' => true,
            'article' => [
                'valueNL' => 'Artikel',
                'valueEN' => 'Article',
            ],
        ]);

        return $form;
    }

    private function featuredPackage(): CompanyFeaturedPackage
    {
        return new CompanyFeaturedPackage();
    }
}
