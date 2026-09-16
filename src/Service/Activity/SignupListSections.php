<?php

declare(strict_types=1);

namespace App\Service\Activity;

use App\Entity\Activity\ActivityLocalisedText;
use App\Entity\Activity\ActivityRevision;
use App\Entity\Activity\Enums\AllocationMethod;
use App\Entity\Activity\Enums\DrawCutoffRule;
use App\Entity\Activity\Enums\MembershipPriorityMode;
use App\Entity\Activity\Enums\SignupFieldTypes;
use App\Entity\Activity\SignupField;
use App\Entity\Activity\SignupList;
use App\Entity\Application\Enums\Languages;
use App\Entity\Application\PriorityTierInterface;
use App\Form\Activity\Enums\SignupListSection;
use App\Service\Application\BuildsRevisionFieldsTrait;
use App\Util\Activity\SignupListRule;
use App\Util\Activity\SignupTiers;
use App\ViewModel\Application\Review\RevisionChangeKind;
use App\ViewModel\Application\Review\RevisionEntry;
use App\ViewModel\Application\Review\RevisionEntryOption;
use App\ViewModel\Application\Review\RevisionField;
use App\ViewModel\Application\Review\RevisionFieldKind;
use App\ViewModel\Application\Review\RevisionFlag;
use App\ViewModel\Application\Review\RevisionSection;
use App\ViewModel\Application\Review\RevisionSectionGroup;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_key_exists;
use function implode;
use function in_array;
use function sprintf;
use function strval;
use function Symfony\Component\Translation\t;
use function trim;

/**
 * A list is matched to the one it descends from by lineage rather than by its place in the revision, and its questions
 * by what they say first and their place second, which is what tells a moved question from a rewritten one.
 */
final readonly class SignupListSections
{
    use BuildsRevisionFieldsTrait;

    public function __construct(private TranslatorInterface $translator)
    {
    }

    /**
     * @return list<RevisionSection>
     */
    public function sections(
        ActivityRevision $revision,
        ?ActivityRevision $previous,
        bool $comparable,
    ): array {
        $before = $previous?->getSignupListsByLineage() ?? [];

        $sections = [];
        $position = 0;

        foreach ($revision->getSignupLists() as $list) {
            ++$position;
            $lineage = $list->lineageId->toRfc4122();
            $counterpart = $before[$lineage] ?? null;
            unset($before[$lineage]);

            foreach (
                $this->sectionsFor(
                    $counterpart,
                    $list,
                    $position,
                    $comparable,
                ) as $section
            ) {
                $sections[] = $section;
            }
        }

        foreach ($before as $list) {
            ++$position;

            foreach (
                $this->sectionsFor(
                    $list,
                    null,
                    $position,
                    $comparable,
                ) as $section
            ) {
                $sections[] = $section;
            }
        }

        return $sections;
    }

    /**
     * @return list<RevisionSection>
     */
    private function sectionsFor(
        ?SignupList $old,
        ?SignupList $new,
        int $position,
        bool $comparable,
    ): array {
        $named = $new ?? $old;

        if (null === $named) {
            return [];
        }

        $basics = $this->basics(
            $old,
            $new,
            $comparable,
        );
        $allocation = $this->allocation(
            $old,
            $new,
            $comparable,
        );
        $questions = $this->questions(
            $old,
            $new,
            $comparable,
        );

        $group = new RevisionSectionGroup(
            $named->lineageId->toRfc4122(),
            SignupListRule::label(
                $named,
                $position,
                $this->translator,
            ),
            t('Sign-up lists'),
            $this->listKind(
                $old,
                $new,
                $comparable,
                $basics,
                $allocation,
                $questions,
            ),
        );

        return [
            new RevisionSection(
                SignupListSection::Basics->keyFor($named),
                SignupListSection::Basics,
                $basics,
                group: $group,
                description: SignupListSection::Basics->description($this->translator),
            ),
            new RevisionSection(
                SignupListSection::Allocation->keyFor($named),
                SignupListSection::Allocation,
                $allocation,
                group: $group,
                description: SignupListSection::Allocation->description($this->translator),
            ),
            new RevisionSection(
                SignupListSection::Questions->keyFor($named),
                SignupListSection::Questions,
                [],
                entries: $questions,
                group: $group,
                description: SignupListSection::Questions->description($this->translator),
            ),
        ];
    }

    /**
     * @param list<RevisionField> $basics
     * @param list<RevisionField> $allocation
     * @param list<RevisionEntry> $questions
     */
    private function listKind(
        ?SignupList $old,
        ?SignupList $new,
        bool $comparable,
        array $basics,
        array $allocation,
        array $questions,
    ): RevisionChangeKind {
        if (
            null === $old
            || !$comparable
        ) {
            return RevisionChangeKind::Added;
        }

        if (null === $new) {
            return RevisionChangeKind::Removed;
        }

        foreach ([...$basics, ...$allocation] as $field) {
            if (!$field->changeKind()->isChange()) {
                continue;
            }

            return RevisionChangeKind::Changed;
        }

        foreach ($questions as $question) {
            if (!$question->kind->isChange()) {
                continue;
            }

            return RevisionChangeKind::Changed;
        }

        return RevisionChangeKind::Same;
    }

    /**
     * @return list<RevisionField>
     */
    private function basics(
        ?SignupList $old,
        ?SignupList $new,
        bool $comparable,
    ): array {
        return [
            $this->localisedField(
                t('Name'),
                $old?->name,
                $new?->name,
                $comparable,
            ),
            $this->field(
                t('Opens'),
                RevisionFieldKind::Moment,
                $old?->openDate,
                $new?->openDate,
                $comparable,
            ),
            $this->field(
                t('Closes'),
                RevisionFieldKind::Moment,
                $old?->closeDate,
                $new?->closeDate,
                $comparable,
            ),
            $this->flags(
                t('Settings'),
                [
                    [
                        t('Members only'),
                        $old?->onlyGEWIS,
                        $new?->onlyGEWIS,
                    ],
                    [
                        t('Show the number of sign-ups to logged-out visitors'),
                        $old?->displaySubscribedNumber,
                        $new?->displaySubscribedNumber,
                    ],
                    [
                        t('Promoted'),
                        $old?->promoted,
                        $new?->promoted,
                    ],
                ],
                $comparable,
                t('None'),
            ),
        ];
    }

    /**
     * @return list<RevisionField>
     */
    private function allocation(
        ?SignupList $old,
        ?SignupList $new,
        bool $comparable,
    ): array {
        $fields = [
            $this->field(
                t('Capacity'),
                RevisionFieldKind::Text,
                $this->capacity($old),
                $this->capacity($new),
                $comparable,
            ),
        ];

        $limited = true === $old?->limitedCapacity
            || true === $new?->limitedCapacity;

        if (!$limited) {
            return $fields;
        }

        $fields[] = $this->field(
            t('Allocation method'),
            RevisionFieldKind::Badge,
            $old?->allocationMethod,
            $new?->allocationMethod,
            $comparable,
        );

        if (
            $this->uses(
                $old,
                $new,
                static fn (SignupList $list): bool => AllocationMethod::ConditionalDraw
                    === $list->allocationMethod,
            )
        ) {
            $fields[] = $this->field(
                t('When to draw'),
                RevisionFieldKind::Badge,
                $old?->drawCutoffRule,
                $new?->drawCutoffRule,
                $comparable,
            );

            if (
                $this->uses(
                    $old,
                    $new,
                    static fn (SignupList $list): bool => DrawCutoffRule::IfFullBefore === $list->drawCutoffRule,
                )
            ) {
                $fields[] = $this->field(
                    t('Draw cutoff moment'),
                    RevisionFieldKind::Moment,
                    $old?->drawCutoffAt,
                    $new?->drawCutoffAt,
                    $comparable,
                );
            }

            if (
                $this->uses(
                    $old,
                    $new,
                    static fn (SignupList $list): bool => DrawCutoffRule::AfterDurationOpen
                        === $list->drawCutoffRule,
                )
            ) {
                $fields[] = $this->field(
                    t('Draw after being open for (hours)'),
                    RevisionFieldKind::Text,
                    $this->number($old?->drawAfterDurationHours),
                    $this->number($new?->drawAfterDurationHours),
                    $comparable,
                );
            }
        }

        if (
            $this->uses(
                $old,
                $new,
                static fn (SignupList $list): bool => AllocationMethod::ExternalParty === $list->allocationMethod,
            )
        ) {
            $fields[] = $this->field(
                t('External party policy URL'),
                RevisionFieldKind::Text,
                $old?->externalPolicyUrl,
                $new?->externalPolicyUrl,
                $comparable,
            );
            $fields[] = $this->flags(
                t('The external party'),
                [
                    [
                        t('Dictates the order of admissions'),
                        $old?->externalForceOrdering,
                        $new?->externalForceOrdering,
                    ],
                    [
                        t('Collects the payment'),
                        $old?->externalPaymentByExternal,
                        $new?->externalPaymentByExternal,
                    ],
                ],
                $comparable,
                t('Neither'),
            );
        }

        if (
            $this->uses(
                $old,
                $new,
                static fn (SignupList $list): bool => AllocationMethod::Custom === $list->allocationMethod,
            )
        ) {
            $fields[] = $this->field(
                t('Describe the allocation method'),
                RevisionFieldKind::LongText,
                $old?->customMethodDescription,
                $new?->customMethodDescription,
                $comparable,
            );
        }

        return [
            ...$fields,
            ...$this->priority(
                $old,
                $new,
                $comparable,
            ),
        ];
    }

    /**
     * @return list<RevisionField>
     */
    private function priority(
        ?SignupList $old,
        ?SignupList $new,
        bool $comparable,
    ): array {
        $fields = [];

        $membership = [
            $this->tierOrder($old?->getMembershipTierOrder()),
            $this->tierOrder($new?->getMembershipTierOrder()),
        ];

        if ($this->said($membership)) {
            $fields[] = $this->field(
                t('Membership priority'),
                RevisionFieldKind::Text,
                $membership[0],
                $membership[1],
                $comparable,
            );
            $fields[] = $this->field(
                t('How the membership order is applied'),
                RevisionFieldKind::Badge,
                $old?->membershipPriorityMode,
                $new?->membershipPriorityMode,
                $comparable,
            );
        }

        if (
            $this->uses(
                $old,
                $new,
                static fn (SignupList $list): bool => MembershipPriorityMode::ReservedPlaces
                === $list->membershipPriorityMode,
            )
        ) {
            // The places are reserved for a rank of the order, so they are read back against the tiers that share them.
            $ranks = [];
            foreach (
                [
                    ...$old?->getMembershipTierOrder() ??
                [],
                    ...$new?->getMembershipTierOrder() ??
                [],
                ] as $rank
            ) {
                $ranks[SignupList::rankKey($rank)] = $rank;
            }

            foreach ($ranks as $rank) {
                $held = [
                    $this->number($old?->getMembershipPlacesForRank($rank)),
                    $this->number($new?->getMembershipPlacesForRank($rank)),
                ];

                if (!$this->said($held)) {
                    continue;
                }

                $fields[] = $this->field(
                    t(
                        'Places held for %tier%',
                        [
                            '%tier%' => SignupTiers::orderText(
                                [$rank],
                                $this->translator,
                            ),
                        ],
                    ),
                    RevisionFieldKind::Text,
                    $held[0],
                    $held[1],
                    $comparable,
                );
            }
        }

        $program = [
            $this->tierOrder($old?->getProgramTypeOrder()),
            $this->tierOrder($new?->getProgramTypeOrder()),
        ];

        if ($this->said($program)) {
            $fields[] = $this->field(
                t('Study phase priority'),
                RevisionFieldKind::Text,
                $program[0],
                $program[1],
                $comparable,
            );
        }

        $cohort = [
            $this->tierOrder($old?->getCohortTierOrder()),
            $this->tierOrder($new?->getCohortTierOrder()),
        ];

        if ($this->said($cohort)) {
            $fields[] = $this->field(
                t('Cohort priority'),
                RevisionFieldKind::Text,
                $cohort[0],
                $cohort[1],
                $comparable,
            );
        }

        $places = [
            $this->number($old?->organisingCommitteePlaces),
            $this->number($new?->organisingCommitteePlaces),
        ];

        if ($this->said($places)) {
            $fields[] = $this->field(
                t('Places held for the organising body'),
                RevisionFieldKind::Text,
                $places[0],
                $places[1],
                $comparable,
            );
        }

        $roles = [
            $this->roles($old),
            $this->roles($new),
        ];

        if ($this->said($roles)) {
            $fields[] = $this->field(
                t('Guaranteed roles'),
                RevisionFieldKind::Text,
                $roles[0],
                $roles[1],
                $comparable,
            );
        }

        return $fields;
    }

    /**
     * @return list<RevisionEntry>
     */
    private function questions(
        ?SignupList $old,
        ?SignupList $new,
        bool $comparable,
    ): array {
        $before = $comparable
            ? $this->fieldsOf($old)
            : [];
        $after = $this->fieldsOf($new);

        $pairs = $this->pairQuestions(
            $before,
            $after,
        );

        $entries = [];
        foreach ($after as $place => $question) {
            $from = $pairs[$place] ?? null;

            $entries[] = $this->question(
                null === $from ? null : $before[$from],
                $question,
                $place + 1,
                null === $from ? null : $from + 1,
                $comparable,
            );
        }

        $taken = [];
        foreach ($pairs as $from) {
            $taken[$from] = true;
        }

        foreach ($before as $place => $question) {
            if (
                array_key_exists(
                    $place,
                    $taken,
                )
            ) {
                continue;
            }

            $entries[] = $this->question(
                $question,
                null,
                null,
                $place + 1,
                $comparable,
            );
        }

        return $entries;
    }

    /**
     * @param list<SignupField> $before
     * @param list<SignupField> $after
     *
     * @return array<int, int>
     */
    private function pairQuestions(
        array $before,
        array $after,
    ): array {
        return self::pair(
            $before,
            $after,
            fn (SignupField $candidate, SignupField $question): bool => $this->questionSignature($candidate)
                === $this->questionSignature($question),
        );
    }

    /**
     * Which thing before each thing after descends from: the first unclaimed one that reads the same, and failing that
     * whatever stood in the same place, so long as nothing has claimed it. Nothing before is claimed twice.
     *
     * @template T
     *
     * @param list<T>              $before
     * @param list<T>              $after
     * @param callable(T, T): bool $same
     *
     * @return array<int, int> the place before each place after descends from
     */
    private static function pair(
        array $before,
        array $after,
        callable $same,
    ): array {
        $pairs = [];
        $taken = [];

        foreach ($after as $place => $item) {
            foreach ($before as $from => $candidate) {
                if (
                    array_key_exists(
                        $from,
                        $taken,
                    )
                    || !$same(
                        $candidate,
                        $item,
                    )
                ) {
                    continue;
                }

                $pairs[$place] = $from;
                $taken[$from] = true;

                break;
            }
        }

        foreach ($after as $place => $item) {
            if (
                array_key_exists(
                    $place,
                    $pairs,
                )
                || !array_key_exists(
                    $place,
                    $before,
                )
                || array_key_exists(
                    $place,
                    $taken,
                )
            ) {
                continue;
            }

            $pairs[$place] = $place;
            $taken[$place] = true;
        }

        return $pairs;
    }

    private function questionSignature(SignupField $question): string
    {
        return implode(
            "\x1f",
            [
                trim($question->name->getValueNL() ?? ''),
                trim($question->name->getValueEN() ?? ''),
                $question->type->value,
            ],
        );
    }

    private function question(
        ?SignupField $old,
        ?SignupField $new,
        ?int $position,
        ?int $wasAt,
        bool $comparable,
    ): RevisionEntry {
        $named = $new ?? $old;
        $fields = [
            $this->localisedField(
                t('Question'),
                $old?->name,
                $new?->name,
                $comparable,
            ),
            $this->field(
                t('Answer type'),
                RevisionFieldKind::Badge,
                $old?->type,
                $new?->type,
                $comparable,
            ),
            $this->flags(
                t('Answers'),
                [
                    [
                        t('Only visible to the board and organiser'),
                        $old?->isSensitive,
                        $new?->isSensitive,
                    ],
                ],
                $comparable,
                t('Visible to whoever may see the sign-ups'),
            ),
        ];

        if (
            $this->uses(
                $old,
                $new,
                static fn (SignupField $question): bool => SignupFieldTypes::Number === $question->type,
            )
        ) {
            $fields[] = $this->field(
                t('Allowed range'),
                RevisionFieldKind::Text,
                $this->range($old),
                $this->range($new),
                $comparable,
            );
        }

        $options = $this->options(
            $old,
            $new,
            $comparable,
        );

        return new RevisionEntry(
            $this->questionKind(
                $old,
                $new,
                $comparable,
                $position,
                $wasAt,
                $fields,
                $options,
            ),
            $position,
            $this->questionTitle($named),
            $fields,
            $options,
            $named?->type,
            null === $wasAt || $wasAt === $position ? null : t(
                'Was question %number%.',
                ['%number%' => $wasAt],
            ),
        );
    }

    /**
     * @param list<RevisionField>       $fields
     * @param list<RevisionEntryOption> $options
     */
    private function questionKind(
        ?SignupField $old,
        ?SignupField $new,
        bool $comparable,
        ?int $position,
        ?int $wasAt,
        array $fields,
        array $options,
    ): RevisionChangeKind {
        if (null === $new) {
            return RevisionChangeKind::Removed;
        }

        if (
            null === $old
            || !$comparable
        ) {
            return RevisionChangeKind::Added;
        }

        foreach ($fields as $field) {
            if (!$field->changeKind()->isChange()) {
                continue;
            }

            return RevisionChangeKind::Changed;
        }

        foreach ($options as $option) {
            if (!$option->kind->isChange()) {
                continue;
            }

            return RevisionChangeKind::Changed;
        }

        return $wasAt === $position
            ? RevisionChangeKind::Same
            : RevisionChangeKind::Moved;
    }

    /**
     * @return list<RevisionEntryOption>
     */
    private function options(
        ?SignupField $old,
        ?SignupField $new,
        bool $comparable,
    ): array {
        $before = [];
        foreach ($comparable ? $old?->getOptions() ?? [] : [] as $option) {
            $before[] = $option->value;
        }

        $after = [];
        foreach ($new?->getOptions() ?? [] as $option) {
            $after[] = $option->value;
        }

        if (
            [] === $before
            && [] === $after
        ) {
            return [];
        }

        $pairs = self::pair(
            $before,
            $after,
            static fn (
                ActivityLocalisedText $candidate,
                ActivityLocalisedText $value,
            ): bool => $candidate->getValueNL() === $value->getValueNL()
                && $candidate->getValueEN() === $value->getValueEN(),
        );

        $options = [];
        foreach ($after as $place => $value) {
            $from = $pairs[$place] ?? null;

            $field = $this->localisedField(
                t('Answer'),
                null === $from ? null : $before[$from],
                $value,
                $comparable,
            );

            $options[] = new RevisionEntryOption(
                match (true) {
                    null === $from || !$comparable => RevisionChangeKind::Added,
                    $field->changeKind()->isChange() => RevisionChangeKind::Changed,
                    default => RevisionChangeKind::Same,
                },
                $field,
            );
        }

        foreach ($before as $index => $value) {
            if (
                in_array(
                    $index,
                    $pairs,
                    true,
                )
            ) {
                continue;
            }

            $options[] = new RevisionEntryOption(
                RevisionChangeKind::Removed,
                $this->localisedField(
                    t('Answer'),
                    $value,
                    null,
                    $comparable,
                ),
            );
        }

        return $options;
    }

    private function questionTitle(?SignupField $question): string
    {
        if (null === $question) {
            return '';
        }

        $name = trim($question->name->getText(Languages::current()) ?? '');

        return '' === $name
            ? $this->translator->trans('Unnamed question')
            : $name;
    }

    private function range(?SignupField $question): ?string
    {
        if (
            null === $question
            || SignupFieldTypes::Number !== $question->type
        ) {
            return null;
        }

        return sprintf(
            '[%s, %s]',
            $question->minimumValue ?? '…',
            $question->maximumValue ?? '…',
        );
    }

    /**
     * @return list<SignupField>
     */
    private function fieldsOf(?SignupList $list): array
    {
        return $list?->getFields()->getValues() ?? [];
    }

    /**
     * @param ?list<list<PriorityTierInterface>> $order
     */
    private function tierOrder(?array $order): ?string
    {
        return SignupTiers::orderText(
            $order,
            $this->translator,
        );
    }

    private function roles(?SignupList $list): ?string
    {
        if (
            null === $list
            || $list->getRoles()->isEmpty()
        ) {
            return null;
        }

        $roles = [];
        foreach ($list->getRoles() as $role) {
            $roles[] = sprintf(
                '%s (%d)',
                $role->name,
                $role->minimum,
            );
        }

        return implode(
            ', ',
            $roles,
        );
    }

    private function number(?int $value): ?string
    {
        return null === $value
            ? null
            : strval($value);
    }

    /**
     * @param array{0: ?string, 1: ?string} $sides
     */
    private function said(array $sides): bool
    {
        return null !== $sides[0]
            || null !== $sides[1];
    }

    /**
     * @template T of object
     *
     * @param T|null            $old
     * @param T|null            $new
     * @param callable(T): bool $uses
     */
    private function uses(
        ?object $old,
        ?object $new,
        callable $uses,
    ): bool {
        return null !== $old && $uses($old)
            || (null !== $new && $uses($new));
    }

    /**
     * Toggles read best as the things that are on, so a set of them is one field of flags: a flag that went on is
     * drawn as new, one that went off as removed, and a list that is gone has every one of them off.
     *
     * @param list<array{TranslatableInterface, ?bool, ?bool}> $toggles the label, the old and the new state
     */
    private function flags(
        TranslatableInterface $label,
        array $toggles,
        bool $comparable,
        TranslatableInterface $emptyLabel,
    ): RevisionField {
        $flags = [];
        foreach ($toggles as [$name, $old, $new]) {
            $flags[] = new RevisionFlag(
                $name,
                $old,
                true === $new,
            );
        }

        return $this->field(
            $label,
            RevisionFieldKind::Flag,
            null,
            $flags,
            $comparable,
            emptyLabel: $emptyLabel,
        );
    }

    /**
     * What a list can take, which is a number only once it is limited.
     */
    private function capacity(?SignupList $list): ?string
    {
        if (null === $list) {
            return null;
        }

        if (!$list->limitedCapacity) {
            return $this->translator->trans('Unlimited');
        }

        return $this->number($list->capacity);
    }
}
