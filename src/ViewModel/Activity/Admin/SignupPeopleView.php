<?php

declare(strict_types=1);

namespace App\ViewModel\Activity\Admin;

use App\Entity\Activity\Enums\SignupFilter;
use App\Entity\Activity\ExternalSignup;
use App\Entity\Activity\Signup;
use App\Entity\Activity\SignupField;
use App\Entity\Activity\SignupList;
use App\Entity\Activity\UserSignup;
use App\Entity\Application\Enums\Languages;
use App\Util\Activity\SignupTiers;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_key_exists;
use function count;
use function in_array;
use function mb_stripos;
use function trim;
use function uasort;

/**
 * Everybody on an activity, one row per person and one column per list: the reading that shows who is in two lists,
 * who is stuck on a waiting list and who only joined one part.
 *
 * @phpstan-type ListSummary array{listId: int, name: string, limited: bool}
 * @phpstan-type AnswerColumn array{key: string, label: string, fieldId: int, hidden: bool}
 * @phpstan-type Membership array{
 *     signup: Signup,
 *     listId: int,
 *     limited: bool,
 *     fields: list<SignupField>,
 *     committee: array<int, true>,
 * }
 */
final readonly class SignupPeopleView
{
    /**
     * @param list<ListSummary>     $lists         the activity's lists, in order
     * @param list<AnswerColumn>    $answerColumns every question of every list, keyed "listId:fieldId"
     * @param list<SignupPersonRow> $rows          the people, narrowed by the search and the quick filter
     */
    public function __construct(
        public array $lists,
        public array $answerColumns,
        public int $visibleAnswerCount,
        public array $rows,
        public int $peopleCount,
        public int $signupCount,
        public int $multiCount,
        public int $waitingCount,
        public int $externalCount,
        public int $selectedCount,
    ) {
    }

    /**
     * @param list<SignupList> $signupLists
     * @param int[]            $selectedIds
     * @param int[]            $hiddenFieldIds
     */
    public static function fromLists(
        array $signupLists,
        TranslatorInterface $translator,
        string $filter = '',
        array $selectedIds = [],
        array $hiddenFieldIds = [],
        SignupFilter $quickFilter = SignupFilter::All,
    ): self {
        $language = Languages::current();
        $needle = trim($filter);

        $lists = [];
        $answerColumns = [];
        $visibleAnswerCount = 0;
        $groups = [];
        $signupCount = 0;

        foreach ($signupLists as $signupList) {
            $listId = $signupList->getId() ?? 0;
            $listName = $signupList->getName()->getText($language) ?? '';
            $limited = $signupList->getLimitedCapacity();
            $lists[] = [
                'listId' => $listId,
                'name' => $listName,
                'limited' => $limited,
            ];

            $fields = $signupList->getFields()->getValues();
            foreach ($fields as $field) {
                $fieldId = $field->getId() ?? 0;
                $hidden = in_array(
                    $fieldId,
                    $hiddenFieldIds,
                    true,
                );
                $answerColumns[] = [
                    'key' => $listId . ':' . $fieldId,
                    'label' => $listName . ' · ' . ($field->getName()->getText($language) ?? ''),
                    'fieldId' => $fieldId,
                    'hidden' => $hidden,
                ];

                if ($hidden) {
                    continue;
                }

                ++$visibleAnswerCount;
            }

            $committee = null === $signupList->getOrganisingCommitteePlaces()
                ? []
                : SignupTiers::organisingCommittee($signupList);

            foreach ($signupList->getSignUpsInAdmissionOrder() as $signup) {
                if (
                    $signup instanceof ExternalSignup
                    && null === $signup->getVerifiedAt()
                ) {
                    continue;
                }

                ++$signupCount;
                $groups[$signup->personKey()][] = [
                    'signup' => $signup,
                    'listId' => $listId,
                    'limited' => $limited,
                    'fields' => $fields,
                    'committee' => $committee,
                ];
            }
        }

        uasort(
            $groups,
            static fn (array $a, array $b): int => [
                count($b),
                $a[0]['signup']->getCreatedAt(),
            ]
                <=> [
                    count($a),
                    $b[0]['signup']->getCreatedAt(),
                ],
        );

        $rows = [];
        $position = 0;
        $multiCount = 0;
        $waitingCount = 0;
        $externalCount = 0;
        $selectedCount = 0;
        foreach ($groups as $key => $group) {
            $row = self::person(
                (string) $key,
                ++$position,
                $group,
                $selectedIds,
                $translator,
                $language,
            );

            if ($row->listCount > 1) {
                ++$multiCount;
            }

            if ($row->waitingSomewhere) {
                ++$waitingCount;
            }

            if ($row->external) {
                ++$externalCount;
            }

            if ($row->selected) {
                ++$selectedCount;
            }

            $kept = match ($quickFilter) {
                SignupFilter::All, SignupFilter::Admitted => true,
                SignupFilter::Multi => $row->listCount > 1,
                SignupFilter::One => 1 === $row->listCount,
                SignupFilter::External => $row->external,
                SignupFilter::Waiting => $row->waitingSomewhere,
            };

            if (
                !$kept
                || !self::matches(
                    $needle,
                    $row,
                )
            ) {
                continue;
            }

            $rows[] = $row;
        }

        return new self(
            lists: $lists,
            answerColumns: $answerColumns,
            visibleAnswerCount: $visibleAnswerCount,
            rows: $rows,
            peopleCount: count($groups),
            signupCount: $signupCount,
            multiCount: $multiCount,
            waitingCount: $waitingCount,
            externalCount: $externalCount,
            selectedCount: $selectedCount,
        );
    }

    /**
     * One person, read off every sign-up of theirs on the activity.
     *
     * @param non-empty-list<Membership> $group
     * @param int[]                      $selectedIds
     */
    private static function person(
        string $key,
        int $position,
        array $group,
        array $selectedIds,
        TranslatorInterface $translator,
        Languages $language,
    ): SignupPersonRow {
        $first = $group[0]['signup'];

        if ($first instanceof UserSignup) {
            $member = $first->getUser();
            $membershipTypeLabel = $translator->trans(
                'User (%type%)',
                ['%type%' => $member->getType()->trans($translator)],
            );
            $generation = $member->getGeneration();
            $external = false;
        } else {
            $membershipTypeLabel = $translator->trans('External');
            $generation = null;
            $external = true;
        }

        $organisingBody = false;
        $statuses = [];
        $signupIds = [];
        $answers = [];
        $selected = false;
        foreach ($group as $membership) {
            $signup = $membership['signup'];

            if (
                $signup instanceof UserSignup
                && array_key_exists(
                    $signup->getUser()->getLidnr(),
                    $membership['committee'],
                )
            ) {
                $organisingBody = true;
            }

            $statuses[$membership['listId']] = !$membership['limited']
                ? 'signed'
                : ($signup->isDrawn() ? 'admitted' : 'waiting');
            $signupIds[] = $signup->getId() ?? 0;

            if (
                in_array(
                    $signup->getId(),
                    $selectedIds,
                    true,
                )
            ) {
                $selected = true;
            }

            foreach ($membership['fields'] as $field) {
                $answers[$membership['listId'] . ':' . ($field->getId() ?? 0)] = $signup->displayValueForField(
                    $field,
                    $translator,
                    $language,
                );
            }
        }

        return new SignupPersonRow(
            key: $key,
            position: $position,
            fullName: $first->getFullName(),
            membershipTypeLabel: $membershipTypeLabel,
            generation: $generation,
            external: $external,
            organisingBody: $organisingBody,
            email: $first->getEmail(),
            statuses: $statuses,
            listCount: count($statuses),
            signupIds: $signupIds,
            answers: $answers,
            waitingSomewhere: in_array(
                'waiting',
                $statuses,
                true,
            ),
            selected: $selected,
        );
    }

    /**
     * Whether the search finds this person: in their name, their address, what they are, or anything they answered.
     */
    private static function matches(
        string $needle,
        SignupPersonRow $person,
    ): bool {
        if ('' === $needle) {
            return true;
        }

        $haystacks = [
            $person->fullName,
            $person->email ?? '',
            $person->membershipTypeLabel,
            ...$person->answers,
        ];

        foreach ($haystacks as $haystack) {
            if (
                false !== mb_stripos(
                    $haystack,
                    $needle,
                )
            ) {
                return true;
            }
        }

        return false;
    }
}
