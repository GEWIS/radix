<?php

declare(strict_types=1);

namespace App\Form\Activity;

use App\Entity\Activity\Enums\AllocationMethod;
use App\Entity\Activity\Enums\CohortTier;
use App\Entity\Activity\Enums\DrawCutoffRule;
use App\Entity\Activity\Enums\MembershipPriorityMode;
use App\Entity\Activity\Enums\MembershipTier;
use App\Entity\Activity\SignupList;
use App\Entity\Activity\SignupRole;
use App\Entity\Application\PriorityTierInterface;
use App\Entity\Database\Enums\ProgramType;
use App\Form\Application\LocalisedTextType;
use App\Form\DisablesFieldsTrait;
use DateTime;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Url;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

use function array_keys;
use function array_map;
use function array_pad;
use function array_sum;
use function explode;
use function implode;
use function strval;
use function Symfony\Component\Translation\t;
use function trim;

/**
 * A sign-up list ({@see SignupList}) with its custom fields. Once the list has sign-ups its structure and allocation
 * method are frozen: everything except the safe metadata (name, closing date, capacity, visibility of the counter,
 * promotion) is disabled, so an already-committed list can never be structurally changed, the way places are allocated
 * cannot be rewritten under the people who already signed up, and existing sign-ups stay valid.
 *
 * @extends AbstractType<SignupList>
 */
class SignupListType extends AbstractType
{
    use DisablesFieldsTrait;

    /**
     * Fields disabled once a list has sign-ups.
     */
    private const array FROZEN_FIELDS = [
        'openDate',
        'onlyGEWIS',
        'limitedCapacity',
        'fields',
    ];

    /**
     * The allocation method and its per-method settings, frozen once the list has sign-ups: changing how places are
     * allocated after people have committed would rewrite the deal they signed up under. `capacity` is deliberately
     * excluded — it is not per-method and may still need adjusting (e.g. adding seats) while the list is open; it is
     * frozen separately once the draw has been performed (see {@see self::freezeWhenDrawn()}, which locks the draw's
     * exact settings so the carried draw lock cannot go stale).
     */
    private const array METHOD_FIELDS = [
        'allocationMethod',
        'drawCutoffRule',
        'drawCutoffAt',
        'drawAfterDurationHours',
        'externalPolicyUrl',
        'externalForceOrdering',
        'externalPaymentByExternal',
        'customMethodDescription',
        'membershipTierOrder',
        'membershipPriorityMode',
        'cohortTierOrder',
        'programTypeOrder',
        'organisingCommitteeSeats',
        'roles',
    ];

    #[Override]
    public function buildForm(
        FormBuilderInterface $builder,
        array $options,
    ): void {
        $builder
            ->add(
                'name',
                LocalisedTextType::class,
                ['label' => t('Name')],
            )
            ->add(
                'openDate',
                DateTimeType::class,
                [
                    'label' => t('Opens'),
                    'widget' => 'single_text',
                    'constraints' => [new NotBlank(message: 'Enter an opening date and time.')],
                    // The entity setter is non-nullable; an empty submission maps to null and would TypeError during
                    // data mapping (before NotBlank runs). Skip the write when empty so NotBlank reports it instead.
                    'setter' => static function (SignupList $list, ?DateTime $value): void {
                        if (null === $value) {
                            return;
                        }

                        $list->setOpenDate($value);
                    },
                ],
            )
            ->add(
                'closeDate',
                DateTimeType::class,
                [
                    'label' => t('Closes'),
                    'widget' => 'single_text',
                    'constraints' => [new NotBlank(message: 'Enter a closing date and time.')],
                    'setter' => static function (SignupList $list, ?DateTime $value): void {
                        if (null === $value) {
                            return;
                        }

                        $list->setCloseDate($value);
                    },
                ],
            )
            ->add(
                'onlyGEWIS',
                CheckboxType::class,
                [
                    'label' => t('Members only'),
                    'required' => false,
                ],
            )
            ->add(
                'displaySubscribedNumber',
                CheckboxType::class,
                [
                    'label' => t('Show the number of sign-ups to logged-out visitors'),
                    'required' => false,
                ],
            )
            ->add(
                'limitedCapacity',
                CheckboxType::class,
                [
                    'label' => t('Limited capacity'),
                    'required' => false,
                ],
            )
            // The capacity is required (and validated) only when "limited capacity" is checked; see the form-level
            // Callback in configureOptions(). Deliberately not in FROZEN_FIELDS so the number stays editable in a
            // revision even after the list has sign-ups.
            ->add(
                'capacity',
                IntegerType::class,
                [
                    'label' => t('Capacity'),
                    'required' => false,
                ],
            )
            // Allocation method + its per-method settings; only meaningful when limited, and validated conditionally
            // by the form-level Callback. Frozen once the list has sign-ups (see METHOD_FIELDS): the capacity may still
            // be adjusted, but how places are allocated cannot change once people have committed.
            ->add(
                'allocationMethod',
                EnumType::class,
                [
                    'label' => t('Allocation method'),
                    'class' => AllocationMethod::class,
                ],
            )
            ->add(
                'drawCutoffRule',
                EnumType::class,
                [
                    'label' => t('When to draw'),
                    'class' => DrawCutoffRule::class,
                    'required' => false,
                    'placeholder' => t('Choose when the draw happens'),
                ],
            )
            ->add(
                'drawCutoffAt',
                DateTimeType::class,
                [
                    'label' => t('Draw cutoff moment'),
                    'widget' => 'single_text',
                    'required' => false,
                ],
            )
            ->add(
                'drawAfterDurationHours',
                IntegerType::class,
                [
                    'label' => t('Draw after being open for (hours)'),
                    'required' => false,
                ],
            )
            ->add(
                'externalPolicyUrl',
                UrlType::class,
                [
                    'label' => t('External party policy URL'),
                    'required' => false,
                    'constraints' => [
                        new Url(
                            message: 'Enter a valid URL (starting with http:// or https://).',
                            protocols: [
                                'http',
                                'https',
                            ],
                        ),
                    ],
                ],
            )
            ->add(
                'externalForceOrdering',
                CheckboxType::class,
                [
                    'label' => t('The external party dictates the order of admissions'),
                    'required' => false,
                ],
            )
            ->add(
                'externalPaymentByExternal',
                CheckboxType::class,
                [
                    'label' => t('Payment is collected by the external party'),
                    'required' => false,
                ],
            )
            ->add(
                'customMethodDescription',
                TextareaType::class,
                [
                    'label' => t('Describe the allocation method'),
                    'required' => false,
                ],
            )
            ->add(
                'membershipTierOrder',
                HiddenType::class,
                [
                    'label' => false,
                    'required' => false,
                    'getter' => static fn (SignupList $list): string => self::membershipAsString($list),
                    'setter' => static function (
                        SignupList $list,
                        ?string $value,
                    ): void {
                        self::membershipFromString(
                            $list,
                            $value,
                        );
                    },
                ],
            )
            ->add(
                'membershipPriorityMode',
                EnumType::class,
                [
                    'label' => t('How the membership order is applied'),
                    'class' => MembershipPriorityMode::class,
                    'required' => false,
                    'placeholder' => t('Choose how the tiers are served'),
                ],
            )
            ->add(
                'cohortTierOrder',
                HiddenType::class,
                [
                    'label' => false,
                    'required' => false,
                    'getter' => static fn (SignupList $list): string => self::orderAsString(
                        $list->getCohortTierOrder(),
                    ),
                    'setter' => static function (
                        SignupList $list,
                        ?string $value,
                    ): void {
                        /** @var ?list<list<CohortTier>> $order */
                        $order = self::orderFromString(
                            $value,
                            CohortTier::class,
                        );
                        $list->setCohortTierOrder($order);
                    },
                ],
            )
            ->add(
                'programTypeOrder',
                HiddenType::class,
                [
                    'label' => false,
                    'required' => false,
                    'getter' => static fn (SignupList $list): string => self::orderAsString(
                        $list->getProgramTypeOrder(),
                    ),
                    'setter' => static function (
                        SignupList $list,
                        ?string $value,
                    ): void {
                        /** @var ?list<list<ProgramType>> $order */
                        $order = self::orderFromString(
                            $value,
                            ProgramType::class,
                        );
                        $list->setProgramTypeOrder($order);
                    },
                ],
            )
            ->add(
                'organisingCommitteeSeats',
                IntegerType::class,
                [
                    'label' => t('Seats held for the organising body'),
                    'required' => false,
                ],
            )
            ->add(
                'roles',
                CollectionType::class,
                [
                    'label' => false,
                    'entry_type' => SignupRoleType::class,
                    'entry_options' => ['label' => false],
                    'allow_add' => true,
                    'allow_delete' => true,
                    'by_reference' => false,
                    'prototype' => true,
                    'prototype_name' => '__role__',
                    'block_prefix' => 'signup_role_collection',
                ],
            )
            ->add(
                'promoted',
                CheckboxType::class,
                [
                    'label' => t('Promoted'),
                    'required' => false,
                ],
            )
            ->add(
                'fields',
                CollectionType::class,
                [
                    'label' => false,
                    'entry_type' => SignupFieldType::class,
                    'entry_options' => ['label' => false],
                    'allow_add' => true,
                    'allow_delete' => true,
                    'by_reference' => false,
                    'prototype' => true,
                    'prototype_name' => '__field__',
                    // Render each question as a collapsible panel (see the `signup_field_collection` form theme).
                    'block_prefix' => 'signup_field_collection',
                ],
            );

        $builder->addEventListener(
            FormEvents::POST_SET_DATA,
            $this->freezeWhenActivityStarted(...),
        );
        $builder->addEventListener(
            FormEvents::POST_SET_DATA,
            $this->freezeWhenSubscribed(...),
        );
        $builder->addEventListener(
            FormEvents::POST_SET_DATA,
            $this->freezeWhenDrawn(...),
        );
        $builder->addEventListener(
            FormEvents::POST_SET_DATA,
            $this->disableOpenDateWhenOpened(...),
        );
        $builder->addEventListener(
            FormEvents::POST_SET_DATA,
            $this->disableCloseDateWhenClosed(...),
        );
        // After binding, drop any per-method settings that do not apply to the chosen method (or to an unlimited
        // list), so hidden inputs cannot persist stale config that the cloner would carry into future revisions.
        $builder->addEventListener(
            FormEvents::POST_SUBMIT,
            $this->clearInapplicableAllocation(...),
        );
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SignupList::class,
            // A limited-capacity list must carry a positive capacity; validated at the object level so the rule can
            // depend on the limitedCapacity flag.
            'constraints' => [new Callback($this->validateCapacity(...))],
        ]);
    }

    public function validateCapacity(
        mixed $list,
        ExecutionContextInterface $context,
    ): void {
        if (
            !$list instanceof SignupList
            || !$list->getLimitedCapacity()
        ) {
            return;
        }

        $capacity = $list->getCapacity();
        if (
            null === $capacity
            || $capacity < 1
        ) {
            $context->buildViolation(t(
                'Enter a capacity of at least 1 for a limited-capacity list.',
                [],
                'validators',
            )->getMessage())
                ->atPath('capacity')
                ->addViolation();
        }

        $this->validateAllocationMethod(
            $list,
            $context,
        );
        $this->validatePriorityModifiers(
            $list,
            $context,
        );
    }

    private function validatePriorityModifiers(
        SignupList $list,
        ExecutionContextInterface $context,
    ): void {
        if ($list->getAllocationMethod()->isManual()) {
            return;
        }

        $capacity = $list->getCapacity() ?? 0;

        if (
            null !== $list->getMembershipTierOrder()
            && null === $list->getMembershipPriorityMode()
        ) {
            $context->buildViolation(t(
                'Choose how the membership tiers are served.',
                [],
                'validators',
            )->getMessage())
                ->atPath('membershipPriorityMode')
                ->addViolation();
        }

        $reserved = 0;
        if (MembershipPriorityMode::ReservedSeats === $list->getMembershipPriorityMode()) {
            foreach ($list->getMembershipSeats() as $seats) {
                if ($seats >= 0) {
                    $reserved += $seats;

                    continue;
                }

                $context->buildViolation(t(
                    'Enter zero or more seats.',
                    [],
                    'validators',
                )->getMessage())
                    ->atPath('membershipTierOrder')
                    ->addViolation();
            }
        }

        $committee = $list->getOrganisingCommitteeSeats();
        if (null !== $committee) {
            if ($committee < 1) {
                $context->buildViolation(t(
                    'Hold at least one seat for the organising body, or hold none at all.',
                    [],
                    'validators',
                )->getMessage())
                    ->atPath('organisingCommitteeSeats')
                    ->addViolation();
            } else {
                $reserved += $committee;
            }
        }

        if (
            $capacity >= 1
            && $reserved > $capacity
        ) {
            $context->buildViolation(t(
                'More seats are held than the list has to give out.',
                [],
                'validators',
            )->getMessage())
                ->atPath('capacity')
                ->addViolation();
        }

        $guaranteed = array_sum(array_map(
            static fn (SignupRole $role): int => $role->getMinimum(),
            $list->getRoles()->toArray(),
        ));
        if (
            $capacity < 1
            || $guaranteed <= $capacity
        ) {
            return;
        }

        $context->buildViolation(t(
            'The roles together guarantee more seats than the list has.',
            [],
            'validators',
        )->getMessage())
            ->atPath('roles')
            ->addViolation();
    }

    /**
     * The membership order as the control holds it: the ranks in turn, the tiers of a rank joined, and the seats
     * held for a rank written behind it. The seats belong to the rank rather than to a tier, because the tiers of a
     * rank are admitted together and share what is held for them.
     */
    private static function membershipAsString(SignupList $list): string
    {
        $ranks = [];
        foreach ($list->getMembershipTierOrder() ?? [] as $rank) {
            $tiers = self::orderAsString([$rank]);
            $seats = $list->getMembershipSeatsForRank($rank);

            $ranks[] = null === $seats
                ? $tiers
                : $tiers . ':' . $seats;
        }

        return implode(
            ',',
            $ranks,
        );
    }

    private static function membershipFromString(
        SignupList $list,
        ?string $value,
    ): void {
        $order = [];
        $seats = [];

        foreach (
            explode(
                ',',
                $value ?? '',
            ) as $part
        ) {
            [
                $names, $held
            ] = array_pad(
                explode(
                    ':',
                    $part,
                    2,
                ),
                2,
                null,
            );

            /** @var ?list<list<MembershipTier>> $rank */
            $rank = self::orderFromString(
                $names,
                MembershipTier::class,
            );

            if (null === $rank) {
                continue;
            }

            $order[] = $rank[0];

            if (
                null === $held
                || '' === trim($held)
            ) {
                continue;
            }

            $seats[SignupList::rankKey($rank[0])] = (int) trim($held);
        }

        $list->setMembershipTierOrder([] === $order ? null : $order);
        $list->setHeldMembershipSeats([] === $seats ? null : $seats);
    }

    /**
     * @param ?list<list<PriorityTierInterface>> $order
     */
    private static function orderAsString(?array $order): string
    {
        if (null === $order) {
            return '';
        }

        return implode(
            ',',
            array_map(
                static fn (array $rank): string => implode(
                    '+',
                    array_map(
                        static fn (PriorityTierInterface $tier): string => strval($tier->value),
                        $rank,
                    ),
                ),
                $order,
            ),
        );
    }

    /**
     * @param class-string<PriorityTierInterface> $tier
     *
     * @return ?list<list<PriorityTierInterface>>
     */
    private static function orderFromString(
        ?string $value,
        string $tier,
    ): ?array {
        $order = [];
        foreach (
            explode(
                ',',
                $value ?? '',
            ) as $rank
        ) {
            $tiers = [];
            foreach (
                explode(
                    '+',
                    $rank,
                ) as $name
            ) {
                $case = $tier::tryFrom(trim($name));
                if (null === $case) {
                    continue;
                }

                $tiers[] = $case;
            }

            if ([] === $tiers) {
                continue;
            }

            $order[] = $tiers;
        }

        return [] === $order
            ? null
            : $order;
    }

    /**
     * Require the settings the selected allocation method needs (only reached for a limited-capacity list).
     */
    private function validateAllocationMethod(
        SignupList $list,
        ExecutionContextInterface $context,
    ): void {
        switch ($list->getAllocationMethod()) {
            case AllocationMethod::ConditionalDraw:
                $rule = $list->getDrawCutoffRule();
                if (null === $rule) {
                    $context->buildViolation(t(
                        'Choose when the draw happens.',
                        [],
                        'validators',
                    )->getMessage())
                        ->atPath('drawCutoffRule')
                        ->addViolation();

                    break;
                }

                if (
                    DrawCutoffRule::IfFullBefore === $rule
                    && null === $list->getDrawCutoffAt()
                ) {
                    $context->buildViolation(t(
                        'Enter the moment the list must be full by.',
                        [],
                        'validators',
                    )->getMessage())
                        ->atPath('drawCutoffAt')
                        ->addViolation();
                }

                if (
                    DrawCutoffRule::AfterDurationOpen === $rule
                    && (
                        null === $list->getDrawAfterDurationHours()
                        || $list->getDrawAfterDurationHours() < 1
                    )
                ) {
                    $context->buildViolation(t(
                        'Enter a positive number of hours.',
                        [],
                        'validators',
                    )->getMessage())
                        ->atPath('drawAfterDurationHours')
                        ->addViolation();
                }

                break;
            case AllocationMethod::ExternalParty:
                if ('' === trim($list->getExternalPolicyUrl() ?? '')) {
                    $context->buildViolation(t(
                        'Enter the external party policy URL.',
                        [],
                        'validators',
                    )->getMessage())
                        ->atPath('externalPolicyUrl')
                        ->addViolation();
                }

                break;
            case AllocationMethod::Custom:
                if ('' === trim($list->getCustomMethodDescription() ?? '')) {
                    $context->buildViolation(t(
                        'Describe the allocation method.',
                        [],
                        'validators',
                    )->getMessage())
                        ->atPath('customMethodDescription')
                        ->addViolation();
                }

                break;
            case AllocationMethod::FirstComeFirstServed:
                break;
        }
    }

    /**
     * Expose whether the list is structurally frozen (it, or its live lineage counterpart, has sign-ups) so the
     * collection theme can hide the "remove" button: a list with sign-ups must never be deleted.
     *
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildView(
        FormView $view,
        FormInterface $form,
        array $options,
    ): void {
        $list = $form->getData();
        $view->vars['frozen'] = $this->hasLiveSignUps($list)
            || $this->activityStarted($list);

        foreach (self::allocationVars($list) as $name => $value) {
            $view->vars[$name] = $value;
        }
    }

    /**
     * What the allocation section shows for a list, or for the collection prototype, which has no bound list.
     *
     * @return array<string, mixed>
     */
    private static function allocationVars(?SignupList $list): array
    {
        return [
            'membershipTierOrderTiers' => null === $list
                ? MembershipTier::defaultRanks()
                : $list->getMembershipTierOrder() ?? self::ranksOf($list->membershipTiers()),
            'cohortTierOrderTiers' => $list?->getCohortTierOrder() ?? CohortTier::defaultRanks(),
            'programTypeOrderTiers' => $list?->getProgramTypeOrder() ?? ProgramType::defaultRanks(),
            'onlyGEWIS' => $list?->getOnlyGEWIS() ?? true,
            // The seats held for each rank of the membership order, which the control asks for on the rank itself.
            'membershipSeats' => $list?->getHeldMembershipSeats() ?? [],
        ];
    }

    /**
     * @param list<PriorityTierInterface> $tiers
     *
     * @return list<list<PriorityTierInterface>>
     */
    private static function ranksOf(array $tiers): array
    {
        return array_map(
            static fn (PriorityTierInterface $tier): array => [$tier],
            $tiers,
        );
    }

    /**
     * Re-add every field as `disabled` once the activity has started, so a started activity's sign-up lists can no
     * longer be changed in any way (the `fields` collection too, which cascades to its nested questions/options). A
     * brand-new list, or one whose activity is still upcoming, stays editable.
     */
    private function freezeWhenActivityStarted(FormEvent $event): void
    {
        $list = $event->getData();
        if (
            !$list instanceof SignupList
            || !$this->activityStarted($list)
        ) {
            return;
        }

        $form = $event->getForm();
        foreach (array_keys($form->all()) as $name) {
            $this->disableField(
                $form,
                strval($name),
            );
        }
    }

    /**
     * Whether the live activity this list belongs to has already started. "Started" is read from the *live* revision
     * (the real schedule): a brand-new list whose activity has never been published has no live revision and so is
     * never considered started, keeping it editable so a stale draft can be re-dated. Mirrors
     * {@see \App\Form\Activity\ActivityFlow\GeneralStepType} locking the start once the live activity has begun.
     */
    private function activityStarted(?SignupList $list): bool
    {
        if (
            !$list instanceof SignupList
            || !$list->hasRevision()
        ) {
            return false;
        }

        $live = $list->getRevision()->getActivity()->getLiveRevision();
        if (null === $live) {
            return false;
        }

        $begin = $live->getBeginTime();

        return null !== $begin && $begin <= new DateTime();
    }

    /**
     * Re-add the structural fields and the allocation method (with its per-method settings) as `disabled` for a list
     * that already has sign-ups, so they render read-only and are ignored on submit. `capacity` stays editable so seats
     * can still be adjusted; {@see self::freezeWhenDrawn()} locks it too once the draw has run.
     */
    private function freezeWhenSubscribed(FormEvent $event): void
    {
        $list = $event->getData();
        if (
            !$list instanceof SignupList
            || !$this->hasLiveSignUps($list)
        ) {
            return;
        }

        $form = $event->getForm();
        foreach ([...self::FROZEN_FIELDS, ...self::METHOD_FIELDS] as $name) {
            $this->disableField(
                $form,
                $name,
            );
        }
    }

    /**
     * Whether this list, or (when it is a draft clone) the live revision's list it descends from, has sign-ups. A
     * draft clone's own sign-ups are always empty (sign-ups live on the live revision until approval migrates them),
     * so the structural freeze must look through the lineage to the live counterpart. The collection prototype has no
     * bound list (`null`), in which case nothing is frozen.
     */
    private function hasLiveSignUps(?SignupList $list): bool
    {
        return $this->holdsForLiveLineage(
            $list,
            static fn (SignupList $candidate): bool => $candidate->hasSignUps(),
        );
    }

    /**
     * The shared lineage walk behind {@see self::hasLiveSignUps()} and {@see self::isLiveDrawn()}: whether a predicate
     * holds for this list, or (when it is a draft clone whose own state is still empty because sign-ups/draws live
     * on the live revision until approval) for the live revision's list it descends from (matched by
     * {@see SignupList::getLineageId()}). The collection prototype has no bound list (`null`), for which this is false.
     *
     * @param callable(SignupList): bool $predicate
     */
    private function holdsForLiveLineage(
        ?SignupList $list,
        callable $predicate,
    ): bool {
        if (!$list instanceof SignupList) {
            return false;
        }

        if ($predicate($list)) {
            return true;
        }

        if (!$list->hasRevision()) {
            return false;
        }

        $live = $list->getRevision()->getActivity()->getLiveRevision();
        if (null === $live) {
            return false;
        }

        foreach ($live->getSignupLists() as $liveList) {
            if (
                $liveList->getLineageId()->equals($list->getLineageId())
                && $predicate($liveList)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Re-add the allocation fields as `disabled` once the list's draw has been performed, so an already-drawn list's
     * method/capacity/settings can no longer be changed (which would leave the carried draw lock stale).
     */
    private function freezeWhenDrawn(FormEvent $event): void
    {
        $list = $event->getData();
        if (
            !$list instanceof SignupList
            || !$this->isLiveDrawn($list)
        ) {
            return;
        }

        $form = $event->getForm();
        foreach ([...self::METHOD_FIELDS, 'capacity'] as $name) {
            $this->disableField(
                $form,
                $name,
            );
        }
    }

    /**
     * Whether this list, or its live lineage counterpart, has had its draw performed (and locked). Like
     * {@see self::hasLiveSignUps()} a draft clone carries the lock forward, so the same lineage walk is used.
     */
    private function isLiveDrawn(?SignupList $list): bool
    {
        return $this->holdsForLiveLineage(
            $list,
            static fn (SignupList $candidate): bool => $candidate->isDrawLocked(),
        );
    }

    /**
     * Null out the per-method settings that do not apply to the bound list's final state, so an unlimited list (or one
     * whose method changed) cannot persist stale allocation config that could later reappear or be cloned. Skipped
     * for an already-drawn list, whose allocation fields are frozen (not submitted) and must keep their values.
     */
    private function clearInapplicableAllocation(FormEvent $event): void
    {
        $list = $event->getData();
        if (
            !$list instanceof SignupList
            || $this->isLiveDrawn($list)
        ) {
            return;
        }

        if (!$list->getLimitedCapacity()) {
            $list->setCapacity(null);
            $list->setAllocationMethod(AllocationMethod::FirstComeFirstServed);
        }

        $method = $list->getAllocationMethod();

        if (
            AllocationMethod::ConditionalDraw !== $method
            || DrawCutoffRule::IfFullBefore !== $list->getDrawCutoffRule()
        ) {
            $list->setDrawCutoffAt(null);
        }

        if (
            AllocationMethod::ConditionalDraw !== $method
            || DrawCutoffRule::AfterDurationOpen !== $list->getDrawCutoffRule()
        ) {
            $list->setDrawAfterDurationHours(null);
        }

        if (AllocationMethod::ConditionalDraw !== $method) {
            $list->setDrawCutoffRule(null);
        }

        if (AllocationMethod::ExternalParty !== $method) {
            $list->setExternalPolicyUrl(null);
            $list->setExternalForceOrdering(false);
            $list->setExternalPaymentByExternal(false);
        }

        if (AllocationMethod::Custom !== $method) {
            $list->setCustomMethodDescription(null);
        }

        $this->clearInapplicablePriority($list);
    }

    /**
     * Drop the priority modifiers a list cannot act on: all of them where admission is not decided here, the held
     * seats where the membership order is not applied by holding them, and the study phase and the cohort on a list
     * anybody may sign up for.
     */
    private function clearInapplicablePriority(SignupList $list): void
    {
        if (
            !$list->getLimitedCapacity()
            || $list->getAllocationMethod()->isManual()
        ) {
            $list->setMembershipTierOrder(null);
            $list->setCohortTierOrder(null);
            $list->setProgramTypeOrder(null);
            $list->setOrganisingCommitteeSeats(null);

            foreach ($list->getRoles()->toArray() as $role) {
                $list->removeRole($role);
            }
        }

        if (null === $list->getMembershipTierOrder()) {
            $list->setMembershipPriorityMode(null);
        }

        if (MembershipPriorityMode::ReservedSeats !== $list->getMembershipPriorityMode()) {
            $list->setHeldMembershipSeats(null);

            return;
        }

        // A number held for a rank the order no longer has is a guarantee nothing shows, and the cloner would carry
        // it into every future revision.
        $held = [];
        foreach ($list->getMembershipTierOrder() ?? [] as $rank) {
            $seats = $list->getMembershipSeatsForRank($rank);
            if (null === $seats) {
                continue;
            }

            $held[SignupList::rankKey($rank)] = $seats;
        }

        $list->setHeldMembershipSeats([] === $held ? null : $held);
    }

    /**
     * Re-add `openDate` as `disabled` once a (persisted) sign-up list has opened, so a passed opening date can no
     * longer be moved; only a newly-set opening date must be in the future. A brand-new list (no id yet) is always
     * editable.
     */
    private function disableOpenDateWhenOpened(FormEvent $event): void
    {
        $list = $event->getData();
        if (
            !$list instanceof SignupList
            || !$list->hasRevision()
            || $list->getOpenDate() > new DateTime()
        ) {
            return;
        }

        $this->disableField(
            $event->getForm(),
            'openDate',
        );
    }

    /**
     * Re-add `closeDate` as `disabled` once a (persisted) sign-up list has closed, so a passed closing date can no
     * longer be moved. While the list is still open or upcoming the closing date stays editable, so it can be
     * extended; a brand-new list (no id yet) is always editable.
     */
    private function disableCloseDateWhenClosed(FormEvent $event): void
    {
        $list = $event->getData();
        if (
            !$list instanceof SignupList
            || !$list->hasRevision()
            || $list->getCloseDate() > new DateTime()
        ) {
            return;
        }

        $this->disableField(
            $event->getForm(),
            'closeDate',
        );
    }
}
