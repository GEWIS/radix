<?php

declare(strict_types=1);

namespace App\Tests\Form\Activity;

use App\Entity\Activity\ActivityLocalisedText;
use App\Entity\Activity\Enums\AllocationMethod;
use App\Entity\Activity\Enums\CohortTier;
use App\Entity\Activity\Enums\DrawCutoffRule;
use App\Entity\Activity\Enums\MembershipPriorityMode;
use App\Entity\Activity\Enums\MembershipTier;
use App\Entity\Activity\Enums\SignupFieldTypes;
use App\Entity\Activity\ExternalSignup;
use App\Entity\Activity\SignupList;
use App\Entity\Database\Enums\ProgramType;
use App\Form\Activity\SignupFieldType;
use App\Form\Activity\SignupListType;
use App\Form\Activity\SignupOptionType;
use App\Form\Activity\SignupRoleType;
use App\Form\Application\LocalisedTextType;
use Override;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormExtensionInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\Validation;

use function array_map;
use function sprintf;

/**
 * Once a sign-up list has sign-ups the way places are allocated must not change under the people who already committed:
 * the allocation method and its per-method settings are frozen (rendered read-only, ignored on submit). The capacity is
 * deliberately left editable so seats can still be adjusted while the list is open. These tests pin both.
 */
// TypeTestCase creates an unconfigured EventDispatcher mock internally; opt out of the no-expectations notice.
#[AllowMockObjectsWithoutExpectations]
final class SignupListTypeTest extends TypeTestCase
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
     * @return list<SignupListType|LocalisedTextType|SignupFieldType|SignupOptionType|SignupRoleType>
     */
    #[Override]
    protected function getTypes(): array
    {
        return [
            new SignupListType(),
            new LocalisedTextType(),
            new SignupFieldType(),
            new SignupOptionType(),
            new SignupRoleType(),
        ];
    }

    public function testAllocationMethodIsFrozenOnceTheListHasSignUps(): void
    {
        $form = $this->factory->create(
            SignupListType::class,
            $this->listWithSignUp(),
        );

        // The allocation method and every per-method setting are disabled: the deal can no longer be rewritten.
        foreach (
            [
                'allocationMethod',
                'drawCutoffRule',
                'drawCutoffAt',
                'drawAfterDurationHours',
                'externalPolicyUrl',
                'externalForceOrdering',
                'externalPaymentByExternal',
                'customMethodDescription',
            ] as $name
        ) {
            self::assertTrue(
                $this->isDisabled(
                    $form,
                    $name,
                ),
                sprintf(
                    'Expected "%s" to be frozen once the list has sign-ups.',
                    $name,
                ),
            );
        }

        // The capacity stays editable so seats can still be adjusted; likewise the safe metadata.
        foreach (
            [
                'capacity',
                'closeDate',
                'displaySubscribedNumber',
                'promoted',
            ] as $name
        ) {
            self::assertFalse(
                $this->isDisabled(
                    $form,
                    $name,
                ),
                sprintf(
                    'Expected "%s" to stay editable once the list has sign-ups.',
                    $name,
                ),
            );
        }
    }

    public function testAllocationMethodStaysEditableWhileTheListHasNoSignUps(): void
    {
        $form = $this->factory->create(
            SignupListType::class,
            $this->list(),
        );

        self::assertFalse(
            $this->isDisabled(
                $form,
                'allocationMethod',
            ),
            'A list without sign-ups must keep its allocation method editable.',
        );
    }

    public function testSubmittedFieldAndOptionOrderAndDefaultAreMappedOntoTheEntities(): void
    {
        $list = $this->list();
        $form = $this->factory->create(
            SignupListType::class,
            $list,
        );

        // Submit only the fields collection (clearMissing = false keeps the list's other values); the hidden position
        // inputs carry the dragged order as strings and the default marker is a checkbox on one option.
        $form->submit(
            [
                'fields' => [
                    [
                        'name' => [
                            'valueNL' => 'Vraag',
                            'valueEN' => 'Question',
                        ],
                        'type' => SignupFieldTypes::Choice->value,
                        'position' => '2',
                        'options' => [
                            [
                                'value' => [
                                    'valueNL' => 'A',
                                    'valueEN' => 'A',
                                ],
                                'position' => '5',
                                'isDefault' => '1',
                            ],
                            [
                                'value' => [
                                    'valueNL' => 'B',
                                    'valueEN' => 'B',
                                ],
                                'position' => '3',
                            ],
                        ],
                    ],
                ],
            ],
            false,
        );

        $fields = $list->getFields()->getValues();
        self::assertCount(
            1,
            $fields,
        );
        // The string position round-trips to the entity's int property (the HiddenType model transformer).
        self::assertSame(
            2,
            $fields[0]->getPosition(),
        );

        $options = $fields[0]->getOptions()->getValues();
        self::assertSame(
            5,
            $options[0]->getPosition(),
        );
        self::assertTrue($options[0]->isDefault());
        self::assertSame(
            3,
            $options[1]->getPosition(),
        );
        // An unchecked "default" checkbox is simply absent from the submission and maps to false.
        self::assertFalse($options[1]->isDefault());
    }

    /**
     * The settings an allocation method needs are required only for the method that needs them, which is what the
     * editor draws by revealing a block at a time. These pin the same rule on the server, where a submission that
     * skipped the editor also lands.
     */
    public function testAConditionalDrawMustSayWhenItIsDrawn(): void
    {
        $form = $this->submitList(['allocationMethod' => AllocationMethod::ConditionalDraw->value]);

        self::assertFalse($form->isValid());
        self::assertNotCount(
            0,
            $form->get('drawCutoffRule')->getErrors(),
        );
    }

    public function testADrawOnAFullListMustSayWhatItMustBeFullBy(): void
    {
        $form = $this->submitList([
            'allocationMethod' => AllocationMethod::ConditionalDraw->value,
            'drawCutoffRule' => DrawCutoffRule::IfFullBefore->value,
        ]);

        self::assertFalse($form->isValid());
        self::assertNotCount(
            0,
            $form->get('drawCutoffAt')->getErrors(),
        );
    }

    public function testADrawAfterATimeOpenMustSayHowLong(): void
    {
        $form = $this->submitList([
            'allocationMethod' => AllocationMethod::ConditionalDraw->value,
            'drawCutoffRule' => DrawCutoffRule::AfterDurationOpen->value,
        ]);

        self::assertFalse($form->isValid());
        self::assertNotCount(
            0,
            $form->get('drawAfterDurationHours')->getErrors(),
        );
    }

    public function testAnExternalPartyMustLinkItsPolicy(): void
    {
        $form = $this->submitList(['allocationMethod' => AllocationMethod::ExternalParty->value]);

        self::assertFalse($form->isValid());
        self::assertNotCount(
            0,
            $form->get('externalPolicyUrl')->getErrors(),
        );
    }

    public function testACustomMethodMustBeDescribed(): void
    {
        $form = $this->submitList(['allocationMethod' => AllocationMethod::Custom->value]);

        self::assertFalse($form->isValid());
        self::assertNotCount(
            0,
            $form->get('customMethodDescription')->getErrors(),
        );
    }

    /**
     * A method asks only for its own settings: an unlimited list is held to none of them.
     */
    public function testAnUnlimitedListIsAskedForNoAllocationSettings(): void
    {
        $form = $this->submitList([
            'limitedCapacity' => null,
            'capacity' => '',
            'allocationMethod' => AllocationMethod::ConditionalDraw->value,
        ]);

        self::assertTrue(
            $form->isValid(),
            (string) $form->getErrors(true),
        );
    }

    public function testADrawThatSaysWhenItHappensIsAccepted(): void
    {
        $form = $this->submitList([
            'allocationMethod' => AllocationMethod::ConditionalDraw->value,
            'drawCutoffRule' => DrawCutoffRule::IfFullBefore->value,
            'drawCutoffAt' => '2030-01-15T12:00',
        ]);

        self::assertTrue(
            $form->isValid(),
            (string) $form->getErrors(true),
        );
    }

    public function testAMembershipOrderIsStoredAsTiersAndAsksHowItIsApplied(): void
    {
        $list = $this->list();
        $form = $this->submitList(
            ['membershipTierOrder' => 'non-member,ordinary,graduate'],
            $list,
        );

        self::assertSame(
            [
                [MembershipTier::NonMember],
                [MembershipTier::Ordinary],
                [MembershipTier::Graduate],
                [
                    MembershipTier::External,
                    MembershipTier::Honorary,
                ],
            ],
            $list->getMembershipTierOrder(),
        );
        self::assertFalse($form->isValid());
        self::assertNotCount(
            0,
            $form->get('membershipPriorityMode')->getErrors(),
        );
    }

    public function testTiersJoinedByAPlusShareARank(): void
    {
        $list = $this->list();
        $form = $this->submitList(
            [
                'membershipTierOrder' => 'ordinary+external+honorary+graduate,non-member',
                'membershipPriorityMode' => MembershipPriorityMode::Ordering->value,
            ],
            $list,
        );

        self::assertSame(
            [
                [
                    MembershipTier::Ordinary,
                    MembershipTier::External,
                    MembershipTier::Honorary,
                    MembershipTier::Graduate,
                ],
                [MembershipTier::NonMember],
            ],
            $list->getMembershipTierOrder(),
        );
        self::assertSame(
            'ordinary+external+honorary+graduate,non-member',
            $form->get('membershipTierOrder')->getData(),
        );
    }

    public function testAnOrderThatNamesNoTierIsTheModifierSwitchedOff(): void
    {
        $list = $this->list();
        $form = $this->submitList(
            [
                'membershipTierOrder' => '',
                'cohortTierOrder' => 'nonsense',
                'programTypeOrder' => 'bachelor,master,doctorate,other',
            ],
            $list,
        );

        self::assertNull($list->getMembershipTierOrder());
        self::assertNull($list->getCohortTierOrder());
        self::assertSame(
            [
                [ProgramType::Bachelor],
                [ProgramType::Master],
                [ProgramType::Doctorate],
                [ProgramType::Other],
            ],
            $list->getProgramTypeOrder(),
        );
        self::assertTrue(
            $form->isValid(),
            (string) $form->getErrors(true),
        );
    }

    public function testAnIncompleteOrderIsCompletedWithTheTiersItLeftOut(): void
    {
        $list = $this->list();
        $this->submitList(
            ['cohortTierOrder' => 'unknown'],
            $list,
        );

        self::assertSame(
            [
                [CohortTier::Unknown],
                [CohortTier::FirstYear],
                [CohortTier::SecondYear],
                [CohortTier::Senior],
            ],
            $list->getCohortTierOrder(),
        );
    }

    public function testNoMoreSeatsMayBeHeldThanTheListHasToGiveOut(): void
    {
        $form = $this->submitList([
            'capacity' => '10',
            'membershipTierOrder' => 'ordinary:8,graduate:2,non-member:0',
            'membershipPriorityMode' => MembershipPriorityMode::ReservedSeats->value,
            'organisingCommitteeSeats' => '3',
        ]);

        self::assertFalse($form->isValid());
        self::assertNotCount(
            0,
            $form->get('capacity')->getErrors(),
        );
    }

    public function testHeldSeatsAreDroppedWhenTheOrderIsNotAppliedByHoldingThem(): void
    {
        $list = $this->list();
        $this->submitList(
            [
                'membershipTierOrder' => 'ordinary:4,graduate,non-member',
                'membershipPriorityMode' => MembershipPriorityMode::Ordering->value,
            ],
            $list,
        );

        self::assertNull($list->getHeldMembershipSeats());
        self::assertSame(
            [],
            $list->getMembershipSeats(),
        );
    }

    public function testAManualMethodKeepsNoPriorityModifiers(): void
    {
        $list = $this->list();
        $this->submitList(
            [
                'allocationMethod' => AllocationMethod::Custom->value,
                'customMethodDescription' => 'The board decides.',
                'membershipTierOrder' => 'member,graduate,non-member',
                'membershipPriorityMode' => MembershipPriorityMode::Ordering->value,
                'cohortTierOrder' => 'first-year,second-year,third-year-and-above,unknown',
                'organisingCommitteeSeats' => '2',
            ],
            $list,
        );

        self::assertNull($list->getMembershipTierOrder());
        self::assertNull($list->getMembershipPriorityMode());
        self::assertNull($list->getCohortTierOrder());
        self::assertNull($list->getOrganisingCommitteeSeats());
        self::assertFalse($list->hasPriorityModifiers());
    }

    public function testThePriorityModifiersAreFrozenOnceTheListHasSignUps(): void
    {
        $form = $this->factory->create(
            SignupListType::class,
            $this->listWithSignUp(),
        );

        foreach (
            [
                'membershipTierOrder',
                'membershipPriorityMode',
                'cohortTierOrder',
                'programTypeOrder',
                'organisingCommitteeSeats',
                'roles',
            ] as $name
        ) {
            self::assertTrue(
                $this->isDisabled(
                    $form,
                    $name,
                ),
                sprintf(
                    'Expected "%s" to be frozen once the list has sign-ups.',
                    $name,
                ),
            );
        }
    }

    public function testSeatsAreNotHeldForNonMembersOnAMembersOnlyList(): void
    {
        $list = $this->list();
        $list->setOnlyGEWIS(true);

        $this->submitList(
            [
                'membershipTierOrder' => 'ordinary:4,graduate,non-member:2',
                'membershipPriorityMode' => MembershipPriorityMode::ReservedSeats->value,
            ],
            $list,
        );

        // The list is members-only, so the rank the non-members stood on is gone and the seats held for it with it.
        self::assertSame(
            [MembershipTier::Ordinary->value => 4],
            $list->getHeldMembershipSeats(),
        );
        // The tiers the order left out are appended as the one rank the association would rank them on, and that
        // rank holds nothing until somebody says otherwise.
        self::assertSame(
            [
                MembershipTier::Ordinary->value => 4,
                MembershipTier::Graduate->value => 0,
                MembershipTier::External->value . '+' . MembershipTier::Honorary->value => 0,
            ],
            $list->getMembershipSeats(),
        );
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return FormInterface<mixed>
     */
    private function submitList(
        array $overrides,
        ?SignupList $list = null,
    ): FormInterface {
        $form = $this->factory->create(
            SignupListType::class,
            $list ?? $this->list(),
        );

        $form->submit($overrides + [
            'name' => [
                'valueNL' => 'Naam',
                'valueEN' => 'Name',
            ],
            'openDate' => '2030-01-01T12:00',
            'closeDate' => '2030-02-01T12:00',
            'limitedCapacity' => '1',
            'capacity' => '10',
            'allocationMethod' => AllocationMethod::FirstComeFirstServed->value,
            'fields' => [],
            'roles' => [],
        ]);

        return $form;
    }

    private function listWithSignUp(): SignupList
    {
        $list = $this->list();
        $list->getSignUps()->add(new ExternalSignup());

        return $list;
    }

    private function list(): SignupList
    {
        $list = new SignupList();
        $list->setName(new ActivityLocalisedText(
            'Naam',
            'Name',
        ));

        return $list;
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function isDisabled(
        FormInterface $form,
        string $name,
    ): bool {
        return $form->get($name)->getConfig()->getDisabled();
    }
}
