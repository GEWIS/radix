<?php

declare(strict_types=1);

namespace App\Validator\Application;

use Override;
use RuntimeException;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Transliterator;

use function array_diff;
use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function count_chars;
use function implode;
use function in_array;
use function is_string;
use function levenshtein;
use function min;
use function preg_split;
use function str_ends_with;
use function strlen;
use function substr;
use function trim;

use const PREG_SPLIT_NO_EMPTY;

class NotLikeCategoryValidator extends ConstraintValidator
{
    /**
     * Articles and conjunctions do not distinguish one name from another, so they are ignored.
     */
    private const array FILLER = [
        'a',
        'an',
        'and',
        'the',
        'de',
        'een',
        'en',
        'het',
    ];

    /**
     * Building the ruleset costs twenty times what transliterating a name does, and every label name is compared
     * against every category in every language.
     */
    private ?Transliterator $transliterator = null;

    /**
     * The categories are the same on every submit, so their words are kept rather than split again per name.
     *
     * @var array<string, list<string>>
     */
    private array $categoryWords = [];

    /**
     * @param string[] $supportedLocales
     */
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly array $supportedLocales,
    ) {
    }

    #[Override]
    public function validate(
        mixed $value,
        Constraint $constraint,
    ): void {
        if (!$constraint instanceof NotLikeCategory) {
            throw new UnexpectedTypeException(
                $constraint,
                NotLikeCategory::class,
            );
        }

        if (null === $value) {
            return;
        }

        if (!is_string($value)) {
            throw new UnexpectedValueException(
                $value,
                'string',
            );
        }

        if ('' === trim($value)) {
            return;
        }

        $words = $this->words($value);

        if ([] === $words) {
            return;
        }

        foreach ($constraint->categories as $category) {
            foreach ($this->supportedLocales as $locale) {
                $name = $category->trans(
                    $this->translator,
                    $locale,
                );

                if (
                    !self::resemble(
                        $words,
                        $this->categoryWords[$name] ??= $this->words($name),
                    )
                ) {
                    continue;
                }

                $this->context->buildViolation($constraint->message)
                    ->setParameter(
                        '%category%',
                        $name,
                    )
                    ->addViolation();

                return;
            }
        }
    }

    /**
     * Whether a label name is too similar to a category name: the same words, a typo or a plural of them, or a part
     * of them ("Social" for "Social drink"). A name that adds a word to a category ("Sports day") is not, because
     * the added word is what the label says.
     *
     * @param list<string> $label
     * @param list<string> $category
     */
    private static function resemble(
        array $label,
        array $category,
    ): bool {
        if ([] === $category) {
            return false;
        }

        $labelText = implode(
            '',
            $label,
        );
        $categoryText = implode(
            '',
            $category,
        );

        if ($labelText === $categoryText) {
            return true;
        }

        if (
            self::typo(
                $labelText,
                $categoryText,
            )
        ) {
            return true;
        }

        return [] === array_diff(
            $label,
            $category,
        );
    }

    /**
     * Two edits on a six-letter name change a third of it, which makes a different word rather than a typo ("Intern"
     * against "Extern"), so two edits count only where they are the same letters in another order.
     */
    private static function typo(
        string $label,
        string $category,
    ): bool {
        if (
            min(
                strlen($label),
                strlen($category),
            ) < 6
        ) {
            return false;
        }

        $distance = levenshtein(
            $label,
            $category,
        );

        if (1 === $distance) {
            return true;
        }

        return 2 === $distance
            && count_chars(
                $label,
                1,
            ) === count_chars(
                $category,
                1,
            );
    }

    /**
     * The words of a name in a form that ignores case, accents, punctuation and plurals, so "Feestjes!" and "feest"
     * compare equal.
     *
     * @return list<string>
     */
    private function words(string $name): array
    {
        $transliterated = $this->transliterator()->transliterate($name);
        $words = preg_split(
            '/[^a-z0-9]+/',
            false === $transliterated ? '' : $transliterated,
            -1,
            PREG_SPLIT_NO_EMPTY,
        );

        $words = array_filter(
            false === $words ? [] : $words,
            static fn (string $word): bool => !in_array(
                $word,
                self::FILLER,
                true,
            ),
        );

        return array_values(array_unique(array_map(
            self::singular(...),
            $words,
        )));
    }

    /**
     * Without a Dutch plural or diminutive ending, or an English plural one. A plural is stripped before a
     * diminutive, so "feestjes" reaches "feest" rather than stopping at "feestje".
     */
    private static function singular(string $word): string
    {
        if (
            strlen($word) > 4
            && str_ends_with(
                $word,
                'ies',
            )
        ) {
            return substr(
                $word,
                0,
                -3,
            ) . 'y';
        }

        if (
            strlen($word) > 3
            && str_ends_with(
                $word,
                's',
            )
        ) {
            $word = substr(
                $word,
                0,
                -1,
            );
        }

        foreach (['en', 'je'] as $ending) {
            if (
                strlen($word) > 4
                && str_ends_with(
                    $word,
                    $ending,
                )
            ) {
                return substr(
                    $word,
                    0,
                    -2,
                );
            }
        }

        return $word;
    }

    /**
     * Without transliteration nothing can be compared, so a missing ruleset is a broken build rather than a name that
     * resembles no category.
     */
    private function transliterator(): Transliterator
    {
        return $this->transliterator ??= Transliterator::create('Any-Latin; Latin-ASCII; Lower')
            ?? throw new RuntimeException('The ICU transliteration ruleset is unavailable.');
    }
}
