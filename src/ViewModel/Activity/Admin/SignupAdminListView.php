<?php

declare(strict_types=1);

namespace App\ViewModel\Activity\Admin;

use App\Entity\Activity\Enums\AllocationMethod;
use App\Entity\Activity\Enums\SignupFilter;
use App\Entity\Activity\ExternalSignup;
use App\Entity\Activity\Signup;
use App\Entity\Activity\SignupList;
use App\Entity\Activity\UserSignup;
use App\Entity\Application\Enums\Languages;
use App\Util\Activity\SignupTiers;
use DateTime;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_key_exists;
use function count;
use function in_array;
use function mb_stripos;
use function min;
use function sprintf;
use function trim;

/**
 * Read-model of one {@see SignupList} for the admin sign-ups page. The privileged counterpart of
 * {@see \App\ViewModel\Activity\SignupListView}: it exposes every subscriber with their contact details, membership
 * type, attendance/admission flags and all field answers (no sensitivity masking), so the Twig stays free of
 * permission/`instanceof`/formatting logic.
 *
 * @phpstan-type FieldColumn array{id: int, name: string, hidden: bool}
 * @phpstan-type RolePlaces array{id: int, name: string, minimum: int, taken: int}
 * @phpstan-type Membership array{listId: int, name: string, waiting: bool}
 */
final readonly class SignupAdminListView
{
    /**
     * @param list<FieldColumn> $fieldColumns      one per sign-up field, in field order, with its (localised)
     *                                             header and whether the organiser hid the column; the row cells
     *                                             stay aligned to this full list by index
     * @param int               $visibleFieldCount number of non-hidden field columns
     * @param SignupAdminRow[]  $rows              the subscribers, narrowed by the search and the quick filter
     * @param list<RolePlaces>  $roles             the parts this activity cannot go ahead without, in the order the
     *                                            draw makes up their shortfall, with how many people hold each
     * @param list<string>      $priorityOrders    the orders this list admits in, best first, one sentence each
     * @param list<string>      $reservedPlaces    the places held back, one per thing they are held for
     */
    public function __construct(
        public int $listId,
        public string $name,
        public ?DateTime $openDate,
        public ?DateTime $closeDate,
        public bool $onlyGEWIS,
        public bool $displaySubscribedNumber,
        public bool $limitedCapacity,
        public ?int $capacity,
        public AllocationMethod $allocationMethod,
        public bool $promoted,
        public bool $presenceTaken,
        public bool $drawLocked,
        public ?DateTime $drawnAt,
        public ?string $drawnByName,
        public ?DateTime $autoDrawAt,
        public bool $autoDrawDue,
        public bool $drawnByHand,
        public bool $isOpen,
        public bool $isClosed,
        // The activity is cancelled or unpublished: all sign-up interaction (draws included) is frozen.
        public bool $frozen,
        public int $subscriberCount,
        public int $presentCount,
        public int $admittedCount,
        public int $waitingCount,
        public int $externalCount,
        public int $multiCount,
        public int $selectedCount,
        public int $shownCount,
        public array $fieldColumns,
        public int $visibleFieldCount,
        public array $rows,
        public array $roles = [],
        public bool $canAssignRoles = false,
        public bool $hasPriority = false,
        public array $priorityOrders = [],
        public array $reservedPlaces = [],
    ) {
    }

    /**
     * How full the list is, as a share of its capacity, for the bar on its card.
     */
    public function admittedShare(): int
    {
        if (
            !$this->limitedCapacity
            || null === $this->capacity
            || $this->capacity < 1
        ) {
            return 100;
        }

        return (int) min(
            100,
            $this->admittedCount / $this->capacity * 100,
        );
    }

    /**
     * How much of the capacity the waiting list would take on top, for the same bar.
     */
    public function waitingShare(): int
    {
        if (
            !$this->limitedCapacity
            || null === $this->capacity
            || $this->capacity < 1
        ) {
            return 0;
        }

        return (int) min(
            100 - $this->admittedShare(),
            $this->waitingCount / $this->capacity * 100,
        );
    }

    /**
     * How many places are still to be given out, or null when there is no limit.
     */
    public function placesLeft(): ?int
    {
        if (
            !$this->limitedCapacity
            || null === $this->capacity
        ) {
            return null;
        }

        return $this->capacity - $this->admittedCount;
    }

    /**
     * @param string                          $filter         case-insensitive substring of a name, email, membership
     *                                                        type or answer; empty matches everyone
     * @param int[]                           $selectedIds    signup ids the organiser ticked
     * @param int[]                           $hiddenFieldIds ids of the sign-up fields whose column the organiser hid
     * @param array<string, list<Membership>> $memberships    per person (see {@see Signup::personKey()}), every
     *                                                        list of the activity they are in
     */
    public static function fromSignupList(
        SignupList $signupList,
        TranslatorInterface $translator,
        string $filter = '',
        array $selectedIds = [],
        array $hiddenFieldIds = [],
        SignupFilter $quickFilter = SignupFilter::All,
        array $memberships = [],
    ): self {
        $language = Languages::current();
        $listId = $signupList->getId() ?? 0;

        $fields = $signupList->getFields()->toArray();
        $fieldColumns = [];
        $visibleFieldCount = 0;
        foreach ($fields as $field) {
            $fieldId = $field->getId() ?? 0;
            $hidden = in_array(
                $fieldId,
                $hiddenFieldIds,
                true,
            );
            $fieldColumns[] = [
                'id' => $fieldId,
                'name' => $field->getName()->getText($language) ?? '',
                'hidden' => $hidden,
            ];

            if ($hidden) {
                continue;
            }

            ++$visibleFieldCount;
        }

        $needle = trim($filter);

        $roleTaken = [];
        foreach ($signupList->getRoles() as $role) {
            $roleTaken[$role->getId() ?? 0] = 0;
        }

        $committee = null === $signupList->getOrganisingCommitteePlaces()
            ? []
            : SignupTiers::organisingCommittee($signupList);
        $ranksOn = [
            'membership' => null !== $signupList->getMembershipTierOrder(),
            'program' => null !== $signupList->getProgramTypeOrder(),
            'cohort' => null !== $signupList->getCohortTierOrder(),
        ];
        $limited = $signupList->getLimitedCapacity();

        $rows = [];
        $position = 1;
        $subscriberCount = 0;
        $presentCount = 0;
        $admittedCount = 0;
        $externalCount = 0;
        $multiCount = 0;
        $selectedCount = 0;
        foreach ($signupList->getSignUpsInAdmissionOrder() as $signup) {
            // Hide externals that have not confirmed their email: not real subscribers, must not be counted or drawn. A
            // confirmed sign-up is exactly one with a set verification moment (manual entries have it set immediately).
            if (
                $signup instanceof ExternalSignup
                && null === $signup->getVerifiedAt()
            ) {
                continue;
            }

            ++$subscriberCount;

            if ($signup->isPresent()) {
                ++$presentCount;
            }

            if ($signup->isDrawn()) {
                ++$admittedCount;
            }

            $role = $signup->getRole();
            if (null !== $role) {
                $roleTaken[$role->getId() ?? 0] = ($roleTaken[$role->getId() ?? 0] ?? 0) + 1;
            }

            $selected = in_array(
                $signup->getId(),
                $selectedIds,
                true,
            );
            // Count selections for THIS list (over all its sign-ups, not just the filtered rows).
            if ($selected) {
                ++$selectedCount;
            }

            if ($signup instanceof UserSignup) {
                $member = $signup->getUser();
                $membershipTypeLabel = $translator->trans(
                    'User (%type%)',
                    ['%type%' => $member->getType()->trans($translator)],
                );
                $generation = $member->getGeneration();
                $external = false;
                $organisingBody = array_key_exists(
                    $member->getLidnr(),
                    $committee,
                );
            } else {
                $membershipTypeLabel = $translator->trans('External');
                $generation = null;
                $external = true;
                $organisingBody = false;
            }

            if ($external) {
                ++$externalCount;
            }

            $otherLists = [];
            foreach ($memberships[$signup->personKey()] ?? [] as $membership) {
                if ($membership['listId'] === $listId) {
                    continue;
                }

                $otherLists[] = [
                    'name' => $membership['name'],
                    'waiting' => $membership['waiting'],
                ];
            }

            if ([] !== $otherLists) {
                ++$multiCount;
            }

            // The position numbers the full sign-up order; the filters only narrow which rows are shown.
            $currentPosition = $position++;

            $cells = [];
            foreach ($fields as $field) {
                $cells[] = [
                    'value' => $signup->displayValueForField(
                        $field,
                        $translator,
                        $language,
                    ),
                ];
            }

            $kept = match ($quickFilter) {
                SignupFilter::All, SignupFilter::One => true,
                SignupFilter::Admitted => $signup->isDrawn(),
                SignupFilter::Waiting => $limited && !$signup->isDrawn(),
                SignupFilter::External => $external,
                SignupFilter::Multi => [] !== $otherLists,
            };

            if (
                !$kept
                || !self::matches(
                    $needle,
                    $signup,
                    $membershipTypeLabel,
                    $cells,
                )
            ) {
                continue;
            }

            $rows[] = new SignupAdminRow(
                signupId: $signup->getId() ?? 0,
                position: $currentPosition,
                fullName: $signup->getFullName(),
                membershipTypeLabel: $membershipTypeLabel,
                generation: $generation,
                external: $external,
                email: $signup->getEmail(),
                signedUpAt: $signup->getCreatedAt(),
                present: $signup->isPresent(),
                drawn: $signup->isDrawn(),
                cells: $cells,
                priority: self::priorityLabels(
                    $ranksOn,
                    $signup,
                    $translator,
                ),
                roleId: $role?->getId(),
                roleName: $role?->getName(),
                organisingBody: $organisingBody,
                otherLists: $otherLists,
                selected: $selected,
            );
        }

        $roles = [];
        foreach ($signupList->getRoles() as $role) {
            $roles[] = [
                'id' => $role->getId() ?? 0,
                'name' => $role->getName(),
                'minimum' => $role->getMinimum(),
                'taken' => $roleTaken[$role->getId() ?? 0] ?? 0,
            ];
        }

        return new self(
            listId: $listId,
            name: $signupList->getName()->getText($language) ?? '',
            openDate: $signupList->getOpenDate(),
            closeDate: $signupList->getCloseDate(),
            onlyGEWIS: $signupList->getOnlyGEWIS(),
            displaySubscribedNumber: $signupList->getDisplaySubscribedNumber(),
            limitedCapacity: $limited,
            capacity: $signupList->getCapacity(),
            allocationMethod: $signupList->getAllocationMethod(),
            promoted: $signupList->isPromoted(),
            presenceTaken: $signupList->isPresenceTaken(),
            drawLocked: $signupList->isDrawLocked(),
            drawnAt: $signupList->getDrawnAt(),
            drawnByName: $signupList->getDrawnBy()?->getFullName(),
            autoDrawAt: $signupList->getAutoDrawAt(),
            autoDrawDue: $signupList->isAutoDrawDue(),
            drawnByHand: $signupList->isDrawnByHand(),
            isOpen: $signupList->isOpen(),
            isClosed: $signupList->isClosed(),
            frozen: $signupList->getActivity()->isFrozen(),
            subscriberCount: $subscriberCount,
            presentCount: $presentCount,
            admittedCount: $admittedCount,
            waitingCount: $limited ? $subscriberCount - $admittedCount : 0,
            externalCount: $externalCount,
            multiCount: $multiCount,
            selectedCount: $selectedCount,
            shownCount: count($rows),
            fieldColumns: $fieldColumns,
            visibleFieldCount: $visibleFieldCount,
            rows: $rows,
            roles: $roles,
            canAssignRoles: [] !== $roles
                && $signupList->isClosed()
                && !$signupList->isDrawLocked()
                && !$signupList->getActivity()->isFrozen(),
            hasPriority: $signupList->hasPriorityModifiers(),
            priorityOrders: SignupTiers::orderTexts(
                $signupList,
                $translator,
            ),
            reservedPlaces: self::reservedPlaces(
                $signupList,
                $translator,
            ),
        );
    }

    /**
     * Whether the search finds this sign-up: in their name, their address, what they are, or anything they answered.
     *
     * @param list<array{value: string}> $cells
     */
    private static function matches(
        string $needle,
        Signup $signup,
        string $membershipTypeLabel,
        array $cells,
    ): bool {
        if ('' === $needle) {
            return true;
        }

        $haystacks = [
            $signup->getFullName(),
            $signup->getEmail() ?? '',
            $membershipTypeLabel,
        ];
        foreach ($cells as $cell) {
            $haystacks[] = $cell['value'];
        }

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

    /**
     * The places this list holds back before it admits anybody: a number for a membership tier or for the organising
     * body.
     *
     * @return list<string>
     */
    private static function reservedPlaces(
        SignupList $signupList,
        TranslatorInterface $translator,
    ): array {
        $held = [];

        foreach ($signupList->getMembershipTierOrder() ?? [] as $rank) {
            $places = $signupList->getMembershipPlaces()[SignupList::rankKey($rank)] ?? 0;
            if ($places < 1) {
                continue;
            }

            $held[] = sprintf(
                '%s: %d',
                SignupTiers::orderText(
                    [$rank],
                    $translator,
                ) ?? '',
                $places,
            );
        }

        $committee = $signupList->getOrganisingCommitteePlaces();
        if (null !== $committee) {
            $held[] = sprintf(
                '%s: %d',
                $translator->trans('Organising body'),
                $committee,
            );
        }

        return $held;
    }

    /**
     * @param array{membership: bool, program: bool, cohort: bool} $ranksOn
     *
     * @return list<string>
     */
    private static function priorityLabels(
        array $ranksOn,
        Signup $signup,
        TranslatorInterface $translator,
    ): array {
        $labels = [];

        if ($ranksOn['membership']) {
            $labels[] = SignupTiers::membership($signup)->trans($translator);
        }

        if ($ranksOn['program']) {
            $labels[] = SignupTiers::program($signup)->trans($translator);
        }

        if ($ranksOn['cohort']) {
            $labels[] = SignupTiers::cohort($signup)->trans($translator);
        }

        return $labels;
    }
}
