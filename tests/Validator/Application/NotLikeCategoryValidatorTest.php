<?php

declare(strict_types=1);

namespace App\Tests\Validator\Application;

use App\Validator\Application\NotLikeCategory;
use App\Validator\Application\NotLikeCategoryValidator;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Translation\IdentityTranslator;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @extends ConstraintValidatorTestCase<NotLikeCategoryValidator>
 */
#[CoversClass(NotLikeCategoryValidator::class)]
#[CoversClass(NotLikeCategory::class)]
final class NotLikeCategoryValidatorTest extends ConstraintValidatorTestCase
{
    private const string MESSAGE = 'Too close to %category%.';

    #[Override]
    protected function createValidator(): NotLikeCategoryValidator
    {
        return new NotLikeCategoryValidator(
            new IdentityTranslator(),
            [
                'en',
                'nl',
            ],
        );
    }

    #[DataProvider('categoryLikeNames')]
    public function testANameSimilarToACategoryIsRefused(
        string $name,
        string $category,
    ): void {
        $this->validate(
            $name,
            $this->constraint(),
        );

        $this->buildViolation(self::MESSAGE)
            ->setParameter(
                '%category%',
                $category,
            )
            ->assertRaised();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function categoryLikeNames(): array
    {
        return [
            'the category itself' => [
                'Party',
                'Party',
            ],
            'in the other language' => [
                'Feest',
                'Feest',
            ],
            'other case and punctuation' => [
                'PARTY!',
                'Party',
            ],
            'a plural' => [
                'Sports',
                'Sport',
            ],
            'a Dutch plural' => [
                'Vergaderingen',
                'Vergadering',
            ],
            'a diminutive' => [
                'Feestje',
                'Feest',
            ],
            'a plural diminutive' => [
                'Feestjes',
                'Feest',
            ],
            'an English -ies plural' => [
                'Parties',
                'Party',
            ],
            'a typo' => [
                'Wrokshop',
                'Workshop',
            ],
            'part of a category' => [
                'Social',
                'Social drink',
            ],
            'a category with a filler word' => [
                'Save date',
                'Save the date',
            ],
            'a category with an accent' => [
                'Carrière',
                'Carrière',
            ],
            'a category without its accent' => [
                'Carriere',
                'Carrière',
            ],
        ];
    }

    #[DataProvider('labelNames')]
    public function testANameThatIsNoCategoryIsAccepted(string $name): void
    {
        $this->validate(
            $name,
            $this->constraint(),
        );

        $this->assertNoViolation();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function labelNames(): array
    {
        return [
            'an audience' => ['Useful for first-year students'],
            'a language' => ['Dutch-only'],
            'a category word in a longer description' => ['Open to externals'],
            'a short word that is no category' => ['Free'],
            'a short word close to a short category' => ['Parts'],
            'a category with a word added' => ['Big party'],
            'a category with a word added, in a name of its own' => ['Career event'],
            'a word that is two edits from a category' => ['Intern'],
            'nothing' => [''],
        ];
    }

    private function constraint(): NotLikeCategory
    {
        return new NotLikeCategory(
            categories: [
                self::category(
                    'Party',
                    'Feest',
                ),
                self::category(
                    'Sport',
                    'Sport',
                ),
                self::category(
                    'Meeting',
                    'Vergadering',
                ),
                self::category(
                    'Workshop',
                    'Workshop',
                ),
                self::category(
                    'Social drink',
                    'Borrel',
                ),
                self::category(
                    'Save the date',
                    'Save the date',
                ),
                self::category(
                    'Career',
                    'Carrière',
                ),
                self::category(
                    'External',
                    'Extern',
                ),
            ],
            message: self::MESSAGE,
        );
    }

    private static function category(
        string $en,
        string $nl,
    ): TranslatableInterface {
        return new class ($en, $nl) implements TranslatableInterface {
            public function __construct(
                private readonly string $en,
                private readonly string $nl,
            ) {
            }

            #[Override]
            public function trans(
                TranslatorInterface $translator,
                ?string $locale = null,
            ): string {
                return 'nl' === $locale
                    ? $this->nl
                    : $this->en;
            }
        };
    }
}
