<?php

declare(strict_types=1);

namespace App\Tests\Service\Activity;

use App\Entity\Activity\ActivityLocalisedText;
use App\Entity\Activity\ActivityRevision;
use App\Entity\Activity\Enums\AllocationMethod;
use App\Entity\Activity\Enums\DrawCutoffRule;
use App\Entity\Activity\Enums\MembershipPriorityMode;
use App\Entity\Activity\Enums\SignupFieldTypes;
use App\Entity\Activity\SignupField;
use App\Entity\Activity\SignupList;
use App\Entity\Activity\SignupOption;
use App\Service\Activity\SignupListSections;
use App\ViewModel\Application\Review\RevisionChangeKind;
use App\ViewModel\Application\Review\RevisionEntryOption;
use App\ViewModel\Application\Review\RevisionField;
use App\ViewModel\Application\Review\RevisionSection;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Stringable;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_map;

/**
 * The review screen's view of an activity's sign-up lists. A list is matched to the one it descends from by lineage
 * rather than by its place in the revision, and its questions by what they say first and their place second, which is
 * what tells a moved question from a rewritten one.
 */
final class SignupListSectionsTest extends TestCase
{
    public function testAListWithNoCounterpartIsAddedTogetherWithItsThreeSections(): void
    {
        $sections = $this->describe(
            $this->revision($this->list(Uuid::v4())),
            null,
        );
        $translator = $this->translator();

        self::assertCount(
            3,
            $sections,
        );
        self::assertSame(
            [
                'Basics',
                'Allocation',
                'Questions',
            ],
            array_map(
                static fn (RevisionSection $section): string => $section->heading->trans($translator),
                $sections,
            ),
        );
        self::assertSame(
            RevisionChangeKind::Added,
            $sections[0]->group?->kind,
        );
    }

    public function testAListThatIsGoneIsReportedAsRemoved(): void
    {
        $lineage = Uuid::v4();
        $sections = $this->describe(
            $this->revision(),
            $this->revision($this->list($lineage)),
        );

        self::assertCount(
            3,
            $sections,
        );
        self::assertSame(
            RevisionChangeKind::Removed,
            $sections[0]->group?->kind,
        );
    }

    public function testAnUnchangedListIsTheSameInEverySection(): void
    {
        $lineage = Uuid::v4();
        $previous = $this->revision($this->list($lineage));
        $revision = $this->revision($this->list($lineage));

        $sections = $this->describe(
            $revision,
            $previous,
        );

        self::assertSame(
            RevisionChangeKind::Same,
            $sections[0]->group?->kind,
        );
        foreach ($sections[0]->fields as $field) {
            self::assertSame(
                RevisionChangeKind::Same,
                $field->changeKind(),
            );
        }
    }

    public function testARenamedListCountsAsChanged(): void
    {
        $lineage = Uuid::v4();
        $previous = $this->revision($this->list($lineage, 'Dinner'));
        $revision = $this->revision($this->list($lineage, 'Late dinner'));

        $sections = $this->describe(
            $revision,
            $previous,
        );

        self::assertSame(
            RevisionChangeKind::Changed,
            $sections[0]->group?->kind,
        );
    }

    /**
     * Without a predecessor there is nothing to compare against, so everything reads as new rather than as unchanged.
     */
    public function testWithoutAComparisonEverythingIsNew(): void
    {
        $lineage = Uuid::v4();
        $sections = $this->describe(
            $this->revision($this->list($lineage)),
            $this->revision($this->list($lineage)),
        );

        self::assertSame(
            RevisionChangeKind::Same,
            $sections[0]->group?->kind,
        );

        $uncomparable = $this->describe(
            $this->revision($this->list($lineage)),
            $this->revision($this->list($lineage)),
            false,
        );
        self::assertSame(
            RevisionChangeKind::Added,
            $uncomparable[0]->group?->kind,
        );
    }

    public function testAQuestionThatIsTheSameAtTheSamePlaceIsUnchanged(): void
    {
        $lineage = Uuid::v4();
        $previous = $this->revision($this->question($this->list($lineage), 'Vegetarian?'));
        $revision = $this->revision($this->question($this->list($lineage), 'Vegetarian?'));

        $entry = $this->describe(
            $revision,
            $previous,
        )[2]->entries[0];

        self::assertSame(
            RevisionChangeKind::Same,
            $entry->kind,
        );
        self::assertSame(
            'Vegetarian?',
            $entry->title,
        );
        self::assertSame(
            1,
            $entry->position,
        );
    }

    public function testAQuestionThatMovedIsReportedAsMovedAndSaysWhereItWas(): void
    {
        $lineage = Uuid::v4();
        $before = $this->list($lineage);
        $this->question(
            $before,
            'First?',
        );
        $this->question(
            $before,
            'Second?',
        );

        $after = $this->list($lineage);
        $this->question(
            $after,
            'Second?',
        );
        $this->question(
            $after,
            'First?',
        );

        $previous = $this->revision($before);
        $revision = $this->revision($after);

        $entries = $this->describe(
            $revision,
            $previous,
        )[2]->entries;

        self::assertSame(
            'Second?',
            $entries[0]->title,
        );
        self::assertSame(
            RevisionChangeKind::Moved,
            $entries[0]->kind,
        );
        self::assertSame(
            1,
            $entries[0]->position,
        );
        // It says which place it used to hold, so the reviewer can find it in the old revision.
        self::assertNotNull($entries[0]->note);
        self::assertSame(
            'First?',
            $entries[1]->title,
        );
        self::assertSame(
            RevisionChangeKind::Moved,
            $entries[1]->kind,
        );
        self::assertNotNull($entries[1]->note);
    }

    public function testARewrittenQuestionCountsAsChanged(): void
    {
        $lineage = Uuid::v4();
        $previous = $this->revision($this->question($this->list($lineage), 'Vegetarian?'));
        $revision = $this->revision($this->question($this->list($lineage), 'Allergies?'));

        $entries = $this->describe(
            $revision,
            $previous,
        )[2]->entries;

        self::assertSame(
            RevisionChangeKind::Changed,
            $entries[0]->kind,
        );
        self::assertSame(
            RevisionChangeKind::Changed,
            $this->describeResult(
                $revision,
                $previous,
            ),
        );
    }

    public function testAQuestionThatIsGoneIsReportedAsRemoved(): void
    {
        $lineage = Uuid::v4();
        $previous = $this->revision($this->question($this->list($lineage), 'Vegetarian?'));
        $revision = $this->revision($this->list($lineage));

        $entries = $this->describe(
            $revision,
            $previous,
        )[2]->entries;

        self::assertCount(
            1,
            $entries,
        );
        self::assertSame(
            RevisionChangeKind::Removed,
            $entries[0]->kind,
        );
        self::assertNull($entries[0]->position);
    }

    public function testAQuestionThatIsNewIsReportedAsAdded(): void
    {
        $lineage = Uuid::v4();
        $previous = $this->revision($this->list($lineage));
        $revision = $this->revision($this->question($this->list($lineage), 'Vegetarian?'));

        $entries = $this->describe(
            $revision,
            $previous,
        )[2]->entries;

        self::assertSame(
            RevisionChangeKind::Added,
            $entries[0]->kind,
        );
    }

    public function testAChoiceQuestionListsItsOptionsAndTheirOwnChanges(): void
    {
        $lineage = Uuid::v4();
        $shrinking = $this->question(
            $this->list($lineage),
            'Dish?',
            SignupFieldTypes::Choice,
            [
                'Pasta',
                'Soup',
            ],
        );
        $shrunk = $this->question(
            $this->list($lineage),
            'Dish?',
            SignupFieldTypes::Choice,
            ['Pasta'],
        );

        $entry = $this->describe(
            $this->revision($shrunk),
            $this->revision($shrinking),
        )[2]->entries[0];

        self::assertSame(
            RevisionChangeKind::Changed,
            $entry->kind,
        );
        self::assertSame(
            [
                RevisionChangeKind::Same,
                RevisionChangeKind::Removed,
            ],
            $this->optionKinds($entry->options),
        );

        $growing = $this->question(
            $this->list($lineage),
            'Dish?',
            SignupFieldTypes::Choice,
            ['Pasta'],
        );
        $grown = $this->question(
            $this->list($lineage),
            'Dish?',
            SignupFieldTypes::Choice,
            [
                'Pasta',
                'Curry',
            ],
        );

        $entry = $this->describe(
            $this->revision($grown),
            $this->revision($growing),
        )[2]->entries[0];

        self::assertSame(
            RevisionChangeKind::Changed,
            $entry->kind,
        );
        self::assertSame(
            [
                RevisionChangeKind::Same,
                RevisionChangeKind::Added,
            ],
            $this->optionKinds($entry->options),
        );
    }

    /**
     * A number question carries the range it accepts, which is only shown when either side is a number.
     */
    public function testANumberQuestionShowsItsRange(): void
    {
        $lineage = Uuid::v4();
        $list = $this->question(
            $this->list($lineage),
            'How many?',
            SignupFieldTypes::Number,
        );
        $field = $list->getFields()->first();
        self::assertInstanceOf(
            SignupField::class,
            $field,
        );
        $field->minimumValue = 1;
        $field->maximumValue = 4;

        $revision = $this->revision($list);
        $fields = $this->describe(
            $revision,
            null,
        )[2]->entries[0]->fields;

        self::assertContains(
            'Allowed range',
            $this->labels($fields),
        );

        $ranges = [];
        foreach ($fields as $described) {
            if ('Allowed range' !== $described->label->trans($this->translator())) {
                continue;
            }

            $ranges[] = $described->values[0]->new;
        }

        self::assertSame(
            ['[1, 4]'],
            $ranges,
        );
    }

    /**
     * The allocation section only shows what the chosen method actually uses.
     */
    public function testTheAllocationSectionFollowsTheChosenMethod(): void
    {
        $lineage = Uuid::v4();
        $list = $this->list($lineage);
        $list->limitedCapacity = true;
        $list->capacity = 20;
        $list->allocationMethod = AllocationMethod::ConditionalDraw;
        $list->drawCutoffRule = DrawCutoffRule::IfFullBefore;
        $list->drawCutoffAt = new DateTimeImmutable('2030-01-01 12:00');
        $list->membershipPriorityMode = MembershipPriorityMode::ReservedPlaces;

        $labels = $this->labels($this->describe($this->revision($list), null)[1]->fields);

        self::assertContains(
            'Capacity',
            $labels,
        );
        self::assertContains(
            'Allocation method',
            $labels,
        );
        self::assertContains(
            'When to draw',
            $labels,
        );
        self::assertContains(
            'Draw cutoff moment',
            $labels,
        );
    }

    public function testTheExternalPartyMethodBringsItsOwnFields(): void
    {
        $lineage = Uuid::v4();
        $list = $this->list($lineage);
        $list->limitedCapacity = true;
        $list->allocationMethod = AllocationMethod::ExternalParty;
        $list->externalPolicyUrl = 'https://example.com/policy';

        $labels = $this->labels($this->describe($this->revision($list), null)[1]->fields);

        self::assertContains(
            'External party policy URL',
            $labels,
        );
        self::assertContains(
            'The external party',
            $labels,
        );
    }

    public function testTheCustomMethodDescribesItself(): void
    {
        $lineage = Uuid::v4();
        $list = $this->list($lineage);
        $list->limitedCapacity = true;
        $list->allocationMethod = AllocationMethod::Custom;
        $list->customMethodDescription = 'By lottery';

        $labels = $this->labels($this->describe($this->revision($list), null)[1]->fields);

        self::assertContains(
            'Describe the allocation method',
            $labels,
        );
    }

    public function testAnUnlimitedListShowsThatItIsUnlimited(): void
    {
        $labels = $this->labels($this->describe(
            $this->revision($this->list(Uuid::v4())),
            null,
        )[1]->fields);

        self::assertSame(
            ['Capacity'],
            $labels,
        );
    }

    /**
     * @param list<RevisionEntryOption> $options
     *
     * @return list<RevisionChangeKind>
     */
    private function optionKinds(array $options): array
    {
        return array_map(
            static fn (RevisionEntryOption $option): RevisionChangeKind => $option->kind,
            $options,
        );
    }

    /**
     * @return list<RevisionSection>
     */
    private function describe(
        ActivityRevision $revision,
        ?ActivityRevision $previous,
        bool $comparable = true,
    ): array {
        return new SignupListSections($this->translator())->sections(
            $revision,
            $previous,
            $comparable,
        );
    }

    private function describeResult(
        ActivityRevision $revision,
        ActivityRevision $previous,
    ): RevisionChangeKind {
        return $this->describe(
            $revision,
            $previous,
        )[0]->group->kind ?? RevisionChangeKind::Same;
    }

    /**
     * @param list<RevisionField> $fields
     *
     * @return list<string>
     */
    private function labels(array $fields): array
    {
        return array_map(
            fn ($field): string => (string) $field->label->trans($this->translator()),
            $fields,
        );
    }

    private function translator(): TranslatorInterface
    {
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string|Stringable $id, array $parameters = []): string => (string) $id,
        );

        return $translator;
    }

    private function revision(SignupList ...$lists): ActivityRevision
    {
        $revision = new ActivityRevision();
        foreach ($lists as $list) {
            $revision->addSignupList($list);
        }

        return $revision;
    }

    private function list(
        Uuid $lineage,
        string $name = 'Dinner',
    ): SignupList {
        $list = new SignupList();
        $list->lineageId = $lineage;
        $list->name = new ActivityLocalisedText(
            $name,
            $name,
        );

        return $list;
    }

    /**
     * @param list<string> $options
     */
    private function question(
        SignupList $list,
        string $name,
        SignupFieldTypes $type = SignupFieldTypes::Text,
        array $options = [],
    ): SignupList {
        $field = new SignupField();
        $field->name = new ActivityLocalisedText(
            $name,
            $name,
        );
        $field->type = $type;
        $list->addField($field);

        foreach ($options as $option) {
            $signupOption = new SignupOption();
            $signupOption->value = new ActivityLocalisedText(
                $option,
                $option,
            );
            $field->addOption($signupOption);
        }

        return $list;
    }
}
