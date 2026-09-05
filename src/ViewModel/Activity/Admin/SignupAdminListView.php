<?php

declare(strict_types=1);

namespace App\ViewModel\Activity\Admin;

use App\Entity\Activity\Enums\AllocationMethod;
use App\Entity\Activity\ExternalSignup;
use App\Entity\Activity\Signup;
use App\Entity\Activity\SignupList;
use App\Entity\Activity\UserSignup;
use App\Entity\Application\Enums\Languages;
use App\Util\Activity\SignupTiers;
use DateTime;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_key_exists;
use function in_array;
use function mb_stripos;
use function sprintf;
use function trim;

/**
 * Read-model of one {@see SignupList} for the admin sign-ups page. The privileged counterpart of
 * {@see \App\ViewModel\Activity\SignupListView}: it exposes every subscriber with their contact details, membership
 * type, attendance/admission flags and all field answers (no sensitivity masking), so the Twig stays free of
 * permission/`instanceof`/formatting logic.
 */
final readonly class SignupAdminListView
{
    /**
     * @param list<array{id: int, name: string, hidden: bool}> $fieldColumns      one per sign-up field, in field
     *                                                                             order, with its (localised) header
     *                                                                             and whether the organiser hid the
     *                                                                             column; the row cells stay aligned
     *                                                                             to this full list by index
     * @param int                                              $visibleFieldCount number of non-hidden field columns
     * @param SignupAdminRow[]                                 $rows              the subscribers, optionally narrowed
     *                                                                            by the quick filter
     * @param list<array{id: int, name: string, minimum: int}> $roles             the parts this activity cannot go
     *                                                                           ahead without, in the order the draw
     *                                                                           makes up their shortfall
     * @param list<string>                                     $priorityOrders    the orders this list admits in, best
     *                                                                           first, one sentence each
     * @param list<string>                                     $reservedSeats     the seats held back, one per thing
     *                                                                           they are held for
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
        public int $selectedCount,
        public array $fieldColumns,
        public int $visibleFieldCount,
        public array $rows,
        public array $roles = [],
        public bool $canAssignRoles = false,
        public bool $hasPriority = false,
        public array $priorityOrders = [],
        public array $reservedSeats = [],
    ) {
    }

    /**
     * @param string $filter         case-insensitive name/email substring; empty matches everyone
     * @param int[]  $selectedIds    signup ids the organiser ticked; drives each list's selected count
     * @param int[]  $hiddenFieldIds ids of the sign-up fields whose column the organiser hid
     */
    public static function fromSignupList(
        SignupList $signupList,
        TranslatorInterface $translator,
        string $filter = '',
        array $selectedIds = [],
        array $hiddenFieldIds = [],
    ): self {
        $language = Languages::current();

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

        $roles = [];
        foreach ($signupList->getRoles() as $role) {
            $roles[] = [
                'id' => $role->getId() ?? 0,
                'name' => $role->getName(),
                'minimum' => $role->getMinimum(),
            ];
        }

        $committee = null === $signupList->getOrganisingCommitteeSeats()
            ? []
            : SignupTiers::organisingCommittee($signupList);
        $ranksOn = [
            'membership' => null !== $signupList->getMembershipTierOrder(),
            'program' => null !== $signupList->getProgramTypeOrder(),
            'cohort' => null !== $signupList->getCohortTierOrder(),
        ];

        $rows = [];
        $position = 1;
        $subscriberCount = 0;
        $presentCount = 0;
        $admittedCount = 0;
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

            // Count selections for THIS list (over all its sign-ups, not just the filtered rows).
            if (
                in_array(
                    $signup->getId(),
                    $selectedIds,
                    true,
                )
            ) {
                ++$selectedCount;
            }

            // The position numbers the full sign-up order; the filter only narrows which rows are shown.
            $currentPosition = $position++;

            if (
                '' !== $needle
                && false === mb_stripos(
                    $signup->getFullName(),
                    $needle,
                )
                && false === mb_stripos(
                    $signup->getEmail() ?? '',
                    $needle,
                )
            ) {
                continue;
            }

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

            if ($signup instanceof UserSignup) {
                $member = $signup->getUser();
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

            $rows[] = new SignupAdminRow(
                signupId: $signup->getId() ?? 0,
                position: $currentPosition,
                fullName: $signup->getFullName(),
                membershipTypeLabel: $membershipTypeLabel,
                generation: $generation,
                external: $external,
                signedUpAt: $signup->getCreatedAt(),
                present: $signup->isPresent(),
                drawn: $signup->isDrawn(),
                cells: $cells,
                priority: self::priorityLabels(
                    $ranksOn,
                    $signup,
                    $translator,
                ),
                roleId: $signup->getRole()?->getId(),
                organisingBody: $signup instanceof UserSignup
                    && array_key_exists(
                        $signup->getUser()->getLidnr(),
                        $committee,
                    ),
            );
        }

        return new self(
            listId: $signupList->getId() ?? 0,
            name: $signupList->getName()->getText($language) ?? '',
            openDate: $signupList->getOpenDate(),
            closeDate: $signupList->getCloseDate(),
            onlyGEWIS: $signupList->getOnlyGEWIS(),
            displaySubscribedNumber: $signupList->getDisplaySubscribedNumber(),
            limitedCapacity: $signupList->getLimitedCapacity(),
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
            selectedCount: $selectedCount,
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
            reservedSeats: self::reservedSeats(
                $signupList,
                $translator,
            ),
        );
    }

    /**
     * The seats this list holds back before it admits anybody: a number for a membership tier, for the organising
     * body, or for a role the activity cannot go ahead without.
     *
     * @return list<string>
     */
    private static function reservedSeats(
        SignupList $signupList,
        TranslatorInterface $translator,
    ): array {
        $held = [];

        foreach ($signupList->getMembershipTierOrder() ?? [] as $rank) {
            $seats = $signupList->getMembershipSeats()[SignupList::rankKey($rank)] ?? 0;
            if ($seats < 1) {
                continue;
            }

            $held[] = sprintf(
                '%s: %d',
                SignupTiers::orderText(
                    [$rank],
                    $translator,
                ) ?? '',
                $seats,
            );
        }

        $committee = $signupList->getOrganisingCommitteeSeats();
        if (null !== $committee) {
            $held[] = sprintf(
                '%s: %d',
                $translator->trans('Organising body'),
                $committee,
            );
        }

        foreach ($signupList->getRoles() as $role) {
            $held[] = sprintf(
                '%s: %d',
                $role->getName(),
                $role->getMinimum(),
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
