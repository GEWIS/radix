<?php

declare(strict_types=1);

namespace App\Tests\Form\Activity;

use App\Entity\Activity\ActivityLocalisedText;
use App\Entity\Activity\Enums\SignupFieldTypes;
use App\Entity\Activity\SignupList;
use App\Form\Activity\ActivityFlow\ActivityData;
use App\Form\Activity\ActivityFlow\SignupListStepType;
use App\Form\Activity\Enums\SignupListSection;
use App\Form\Activity\SignupFieldType;
use App\Form\Activity\SignupListType;
use App\Form\Activity\SignupOptionType;
use App\Form\Activity\SignupRoleType;
use App\Form\Application\LocalisedTextType;
use App\Form\Extension\ImmutableDateInputExtension;
use DateTimeImmutable;
use Override;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormExtensionInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The checks a sign-up list step makes when it is handed in: the window a list opens and closes in, the name in every
 * language the activity is written in, and the one preselected answer a choice question may have. These are the rules
 * the editor enforces client-side, repeated where a tampered submission arrives.
 */
#[CoversClass(SignupListStepType::class)]
// TypeTestCase creates an unconfigured EventDispatcher mock internally; opt out of the no-expectations notice.
#[AllowMockObjectsWithoutExpectations]
final class SignupListStepTypeTest extends TypeTestCase
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
     * @return list<ImmutableDateInputExtension> the date fields carry the application's own immutability, without which
     *                                           the window checks would never see a date at all
     */
    #[Override]
    protected function getTypeExtensions(): array
    {
        return [
            new ImmutableDateInputExtension(),
        ];
    }

    /**
     * @return list<SignupListStepType|SignupListType|LocalisedTextType|SignupFieldType|SignupOptionType|SignupRoleType>
     */
    #[Override]
    protected function getTypes(): array
    {
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return [
            new SignupListStepType($translator),
            new SignupListType(),
            new LocalisedTextType(),
            new SignupFieldType(),
            new SignupOptionType(),
            new SignupRoleType(),
        ];
    }

    public function testAListThatWouldOpenInThePastIsRejected(): void
    {
        $root = $this->submit(
            SignupListSection::Basics,
            [
                'openDate' => '2020-01-01T12:00',
                'closeDate' => new DateTimeImmutable('+2 weeks')->format('Y-m-d\TH:i'),
            ],
        );

        self::assertSame(
            ['The sign-up list must open in the future.'],
            $this->messages($root->get('list')->get('openDate')),
        );
        self::assertSame(
            [],
            $this->messages($root->get('list')->get('closeDate')),
        );
    }

    public function testAListThatWouldCloseBeforeItOpensIsRejected(): void
    {
        $root = $this->submit(
            SignupListSection::Basics,
            [
                'openDate' => new DateTimeImmutable('+2 weeks')->format('Y-m-d\TH:i'),
                'closeDate' => new DateTimeImmutable('+1 week')->format('Y-m-d\TH:i'),
            ],
        );

        self::assertSame(
            ['The sign-up list must open before it closes.'],
            $this->messages($root->get('list')->get('closeDate')),
        );
    }

    public function testAListThatWouldCloseAfterTheActivityStartsIsRejected(): void
    {
        $root = $this->submit(
            SignupListSection::Basics,
            [
                'openDate' => new DateTimeImmutable('+1 week')->format('Y-m-d\TH:i'),
                'closeDate' => new DateTimeImmutable('+4 weeks')->format('Y-m-d\TH:i'),
            ],
            $this->activity('+3 weeks'),
        );

        self::assertSame(
            ['The sign-up list must close before the activity starts.'],
            $this->messages($root->get('list')->get('closeDate')),
        );
    }

    public function testAListWithoutANameInALanguageTheActivityIsWrittenInIsRejected(): void
    {
        $root = $this->submit(
            SignupListSection::Basics,
            [
                'name' => [
                    'valueNL' => 'Naam',
                    'valueEN' => '',
                ],
            ],
        );

        self::assertSame(
            [],
            $this->messages($root->get('list')->get('name')->get('valueNL')),
        );
        self::assertSame(
            ['Fill in the English text.'],
            $this->messages($root->get('list')->get('name')->get('valueEN')),
        );
    }

    /**
     * A language the activity is not written in is neither shown nor required, so leaving it empty is not a mistake.
     */
    public function testALanguageTheActivityIsNotWrittenInIsNotRequired(): void
    {
        $root = $this->submit(
            SignupListSection::Basics,
            [
                'name' => [
                    'valueNL' => 'Naam',
                    'valueEN' => '',
                ],
            ],
            $this->activity(
                null,
                english: false,
            ),
        );

        self::assertSame(
            [],
            $this->messages($root->get('list')->get('name')),
        );
    }

    public function testAListWithAWindowAndItsNameIsAccepted(): void
    {
        $root = $this->submit(
            SignupListSection::Basics,
            [],
        );

        self::assertSame(
            '',
            (string) $root->getErrors(true),
        );
    }

    public function testAChoiceQuestionMayPreselectOnlyOneAnswer(): void
    {
        $root = $this->submit(
            SignupListSection::Questions,
            [
                'fields' => [
                    $this->choiceField([
                        [
                            'value' => [
                                'valueNL' => 'A',
                                'valueEN' => 'A',
                            ],
                            'isDefault' => '1',
                        ],
                        [
                            'value' => [
                                'valueNL' => 'B',
                                'valueEN' => 'B',
                            ],
                            'isDefault' => '1',
                        ],
                    ]),
                ],
            ],
        );

        self::assertStringContainsString(
            'Only one option can be preselected as the default.',
            (string) $root->getErrors(true),
        );
    }

    public function testAChoiceQuestionWithOnePreselectedAnswerIsAccepted(): void
    {
        $root = $this->submit(
            SignupListSection::Questions,
            [
                'fields' => [
                    $this->choiceField([
                        [
                            'value' => [
                                'valueNL' => 'A',
                                'valueEN' => 'A',
                            ],
                            'isDefault' => '1',
                        ],
                        [
                            'value' => [
                                'valueNL' => 'B',
                                'valueEN' => 'B',
                            ],
                        ],
                    ]),
                ],
            ],
        );

        self::assertSame(
            '',
            (string) $root->getErrors(true),
        );
    }

    public function testAQuestionWithoutANameInALanguageTheActivityIsWrittenInIsRejected(): void
    {
        $root = $this->submit(
            SignupListSection::Questions,
            [
                'fields' => [
                    [
                        'name' => [
                            'valueNL' => '',
                            'valueEN' => 'Question',
                        ],
                        'type' => SignupFieldTypes::Text->value,
                        'options' => [],
                    ],
                ],
            ],
            $this->activity(dutch: true),
        );

        self::assertStringContainsString(
            'Fill in the Dutch text.',
            (string) $root->getErrors(true),
        );
    }

    /**
     * Only a choice question has answers to preselect; a number or a text question is not asked for any.
     */
    public function testAQuestionThatIsNotAChoiceIsNotAskedForAnswers(): void
    {
        $root = $this->submit(
            SignupListSection::Questions,
            [
                'fields' => [
                    [
                        'name' => [
                            'valueNL' => 'Vraag',
                            'valueEN' => 'Question',
                        ],
                        'type' => SignupFieldTypes::Number->value,
                        'minimumValue' => '1',
                        'maximumValue' => '10',
                        'options' => [],
                    ],
                ],
            ],
        );

        self::assertSame(
            '',
            (string) $root->getErrors(true),
        );
    }

    public function testTheStepTellsTheTemplateWhichLanguagesAndSectionItIsFor(): void
    {
        $begins = new DateTimeImmutable('+3 weeks');
        $root = $this->submit(
            SignupListSection::Basics,
            [],
            $this->activity($begins->format('Y-m-d H:i:s')),
        );

        $view = $root->get('list')->createView();

        self::assertSame(
            SignupListSection::Basics,
            $view->vars['section'],
        );
        self::assertSame(
            $begins->format('Y-m-d H:i:s'),
            $view->vars['activity_begins']->format('Y-m-d H:i:s'),
        );
        self::assertFalse($view->vars['language_dutch']);
        self::assertTrue($view->vars['language_english']);
    }

    /**
     * A form that is not for an activity has nothing to check the window or the names against, and says so by not
     * checking at all rather than by refusing everything.
     */
    public function testAFreshListWithoutAnActivityBehindItIsNotChecked(): void
    {
        $builder = $this->factory->createBuilder(
            FormType::class,
            new SignupList(),
        );
        $builder->add(
            'list',
            SignupListStepType::class,
            [
                'mapped' => false,
                'data' => $this->list(),
                'section' => SignupListSection::Basics,
            ],
        );

        $root = $builder->getForm();
        $root->submit([
            'list' => [
                'name' => [
                    'valueNL' => '',
                    'valueEN' => '',
                ],
                'openDate' => '2020-01-01T12:00',
                'closeDate' => '2019-01-01T12:00',
            ],
        ]);

        self::assertSame(
            [],
            $this->messages($root->get('list')),
        );
    }

    /**
     * Submits one step of the flow and returns the form as a whole: an error raised by these checks bubbles up,
     * so it is the whole form that has to be read for one.
     *
     * @param array<string, mixed> $data
     *
     * @return FormInterface<mixed>
     */
    private function submit(
        SignupListSection $section,
        array $data,
        ?ActivityData $activity = null,
    ): FormInterface {
        $builder = $this->factory->createBuilder(
            FormType::class,
            $activity ?? $this->activity('+3 weeks'),
        );
        $builder->add(
            'list',
            SignupListStepType::class,
            [
                'mapped' => false,
                'data' => $this->list(),
                'section' => $section,
            ],
        );

        $root = $builder->getForm();
        $root->submit(['list' => $data + $this->defaults($section)]);

        return $root;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(SignupListSection $section): array
    {
        return match ($section) {
            SignupListSection::Basics => [
                'name' => [
                    'valueNL' => 'Naam',
                    'valueEN' => 'Name',
                ],
                'openDate' => new DateTimeImmutable('+1 week')->format('Y-m-d\TH:i'),
                'closeDate' => new DateTimeImmutable('+2 weeks')->format('Y-m-d\TH:i'),
            ],
            SignupListSection::Allocation => [
                'allocationMethod' => 'first-come-first-served',
                'roles' => [],
            ],
            SignupListSection::Questions => ['fields' => []],
        };
    }

    /**
     * @param list<array<string, mixed>> $options
     *
     * @return array<string, mixed>
     */
    private function choiceField(array $options): array
    {
        return [
            'name' => [
                'valueNL' => 'Vraag',
                'valueEN' => 'Question',
            ],
            'type' => SignupFieldTypes::Choice->value,
            'options' => $options,
        ];
    }

    private function activity(
        ?string $beginTime = null,
        bool $dutch = false,
        bool $english = true,
    ): ActivityData {
        $activity = new ActivityData();
        $activity->beginTime = null === $beginTime
            ? null
            : new DateTimeImmutable($beginTime);
        $activity->languageDutch = $dutch;
        $activity->languageEnglish = $english;

        return $activity;
    }

    private function list(): SignupList
    {
        $list = new SignupList();
        $list->name = new ActivityLocalisedText();

        return $list;
    }

    /**
     * @param FormInterface<mixed> $form
     *
     * @return list<string>
     */
    private function messages(FormInterface $form): array
    {
        $messages = [];
        foreach ($form->getErrors() as $error) {
            $messages[] = $error->getMessage();
        }

        return $messages;
    }
}
