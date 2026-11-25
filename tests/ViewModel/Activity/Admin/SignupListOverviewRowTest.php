<?php

declare(strict_types=1);

namespace App\Tests\ViewModel\Activity\Admin;

use App\Entity\Activity\ActivityLocalisedText;
use App\Entity\Activity\Enums\AllocationMethod;
use App\Entity\Activity\SignupField;
use App\Entity\Activity\SignupList;
use App\Entity\Application\Enums\Languages;
use App\Form\Activity\Enums\SignupListSection;
use App\ViewModel\Activity\Admin\SignupListOverviewRow;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

use function explode;
use function json_encode;
use function str_contains;
use function strtr;

#[CoversClass(SignupListOverviewRow::class)]
final class SignupListOverviewRowTest extends TestCase
{
    private const array LANGUAGES = [
        Languages::Dutch,
        Languages::English,
    ];

    /**
     * A list without a name falls back to its position in the overview rather than showing an empty row. The row also
     * says which sections are still open.
     */
    public function testAnUnnamedListFallsBackToItsPosition(): void
    {
        $list = new SignupList();
        $list->onlyGEWIS = false;

        $row = SignupListOverviewRow::fromSignupList(
            $list,
            2,
            false,
            self::LANGUAGES,
            $this->translator(),
        );

        self::assertSame(
            0,
            $row->id,
        );
        self::assertSame(
            2,
            $row->position,
        );
        self::assertSame(
            'Sign-up list 2',
            $row->name,
        );
        self::assertFalse($row->locked);
        self::assertSame(
            [],
            $row->chips,
        );

        self::assertSame(
            [
                [
                    'step' => SignupListSection::Basics->keyFor($list),
                    'label' => 'Basics',
                    'done' => false,
                ],
                [
                    'step' => SignupListSection::Allocation->keyFor($list),
                    'label' => 'Allocation',
                    'done' => true,
                ],
                [
                    'step' => SignupListSection::Questions->keyFor($list),
                    'label' => 'Questions',
                    'done' => true,
                ],
            ],
            $row->steps,
        );
    }

    /**
     * The overview is a summary of what the list is, so it names the choices that change how signing up works rather
     * than repeating the list's name.
     */
    public function testTheChipsSummariseWhatTheListIs(): void
    {
        $list = new SignupList();
        $list->name->updateValues(
            'Eten',
            'Food',
        );
        $list->onlyGEWIS = true;
        $list->limitedCapacity = true;
        $list->capacity = 12;
        $list->allocationMethod = AllocationMethod::ConditionalDraw;
        $list->organisingCommitteePlaces = 2;

        $list->addField($this->question('Dieet'));
        $list->addField($this->question('Allergieën'));

        $row = SignupListOverviewRow::fromSignupList(
            $list,
            1,
            true,
            self::LANGUAGES,
            $this->translator(),
        );

        self::assertSame(
            [
                'Members only',
                'Limited capacity (12)',
                'Conditional draw',
                '2 questions',
                'Priority',
            ],
            $row->chips,
        );
        self::assertTrue($row->locked);
        self::assertSame(
            'Eten',
            $row->name,
        );
    }

    /**
     * Capacity is optional on a limited list, and a list without one is still limited; the chip says so rather than
     * inventing a number.
     */
    public function testALimitedListWithoutACapacitySaysSoWithoutANumber(): void
    {
        $list = new SignupList();
        $list->onlyGEWIS = false;
        $list->limitedCapacity = true;
        $list->capacity = null;
        $list->allocationMethod = AllocationMethod::FirstComeFirstServed;

        $row = SignupListOverviewRow::fromSignupList(
            $list,
            1,
            false,
            self::LANGUAGES,
            $this->translator(),
        );

        self::assertSame(
            [
                'Limited capacity',
                'First-come-first-served',
            ],
            $row->chips,
        );
    }

    /**
     * The list is complete once it has a name in every language the activity is written in and a window; a list with
     * only one language named is not.
     */
    public function testTheBasicsAreDoneOnlyWhenNamedInEveryLanguageAndDated(): void
    {
        $list = new SignupList();
        $list->name->updateValues(
            'Eten',
            'Food',
        );
        $list->openDate = new DateTimeImmutable('-1 day');
        $list->closeDate = new DateTimeImmutable('+1 day');

        $row = SignupListOverviewRow::fromSignupList(
            $list,
            1,
            false,
            self::LANGUAGES,
            $this->translator(),
        );

        self::assertTrue($row->steps[0]['done']);

        $list->name->updateValues(
            'Eten',
            null,
        );

        $row = SignupListOverviewRow::fromSignupList(
            $list,
            1,
            false,
            self::LANGUAGES,
            $this->translator(),
        );

        self::assertFalse($row->steps[0]['done']);
    }

    /**
     * A limited list is not finished until it has at least one place; unlimited lists have no capacity to fill in.
     */
    public function testTheAllocationSectionIsDoneOnceThereIsAPlace(): void
    {
        foreach (
            [
                [
                    true,
                    null,
                    false,
                ],
                [
                    true,
                    0,
                    false,
                ],
                [
                    true,
                    1,
                    true,
                ],
                [
                    false,
                    null,
                    true,
                ],
            ] as [$limited, $capacity, $done]
        ) {
            $list = new SignupList();
            $list->limitedCapacity = $limited;
            $list->capacity = $capacity;

            $row = SignupListOverviewRow::fromSignupList(
                $list,
                1,
                false,
                self::LANGUAGES,
                $this->translator(),
            );

            self::assertSame(
                $done,
                $row->steps[1]['done'],
                (string) json_encode([$limited, $capacity]),
            );
        }
    }

    private function question(string $name): SignupField
    {
        $field = new SignupField();
        $field->name = new ActivityLocalisedText(
            $name,
            $name,
        );

        return $field;
    }

    private function translator(): TranslatorInterface
    {
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static function (string $id, array $parameters = []): string {
                if (
                    str_contains(
                        $id,
                        '|',
                    )
                ) {
                    [
                        $singular, $plural
                    ] = explode(
                        '|',
                        $id,
                    );
                    $id = 1 === ($parameters['%count%'] ?? null)
                        ? $singular
                        : $plural;
                }

                return strtr(
                    $id,
                    $parameters,
                );
            },
        );

        return $translator;
    }
}
