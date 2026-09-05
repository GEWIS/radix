<?php

declare(strict_types=1);

namespace App\Tests\Form\Activity;

use App\Entity\Activity\Activity;
use App\Entity\Activity\ActivityLocalisedText;
use App\Entity\Activity\ActivityRevision;
use App\Entity\Activity\Enums\AllocationMethod;
use App\Entity\Activity\Enums\CohortTier;
use App\Entity\Activity\Enums\DrawCutoffRule;
use App\Entity\Activity\Enums\MembershipPriorityMode;
use App\Entity\Activity\Enums\MembershipTier;
use App\Entity\Activity\Enums\SignupFieldTypes;
use App\Entity\Activity\ExternalSignup;
use App\Entity\Activity\SignupList;
use App\Entity\Application\Enums\RevisionStatus;
use App\Entity\Database\Enums\ProgramType;
use App\Form\Activity\Enums\SignupListSection;
use App\Form\Activity\SignupFieldType;
use App\Form\Activity\SignupListType;
use App\Form\Activity\SignupOptionType;
use App\Form\Activity\SignupRoleType;
use App\Form\Application\LocalisedTextType;
use DateTime;
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
        $form = $this->section(
            SignupListSection::Allocation,
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

        self::assertFalse(
            $this->isDisabled(
                $form,
                'capacity',
            ),
        );

        $basics = $this->section(
            SignupListSection::Basics,
            $this->listWithSignUp(),
        );
        foreach (
            [
                'closeDate',
                'displaySubscribedNumber',
                'promoted',
            ] as $name
        ) {
            self::assertFalse(
                $this->isDisabled(
                    $basics,
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
        $form = $this->section(
            SignupListSection::Allocation,
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
        $form = $this->section(
            SignupListSection::Questions,
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
        $list->setOnlyGEWIS(true);
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

    public function testAnOpenListRanksOnNeitherStudyPhaseNorCohort(): void
    {
        $list = $this->list();
        $list->setOnlyGEWIS(false);

        $this->submitList(
            [
                'cohortTierOrder' => 'first-year,second-year,third-year-and-above,unknown',
                'programTypeOrder' => 'master,bachelor,doctorate,other',
            ],
            $list,
        );

        self::assertNull($list->getCohortTierOrder());
        self::assertNull($list->getProgramTypeOrder());
        self::assertFalse($list->hasPriorityModifiers());
    }

    public function testAnIncompleteOrderIsCompletedWithTheTiersItLeftOut(): void
    {
        $list = $this->list();
        $list->setOnlyGEWIS(true);
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
        $form = $this->section(
            SignupListSection::Allocation,
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

    public function testAListWithNoWindowIsNotFilledIn(): void
    {
        $list = new SignupList();

        self::assertNull($list->getOpenDate());
        self::assertNull($list->getCloseDate());
        self::assertFalse($list->isOpen());
        self::assertFalse($list->isClosed());
        self::assertFalse(SignupListSection::Basics->isFilledIn($list));
    }

    public function testAListWithANameAndAWindowIsFilledIn(): void
    {
        $list = $this->list();
        $list->setOpenDate(new DateTime('2030-01-01 12:00'));
        $list->setCloseDate(new DateTime('2030-02-01 12:00'));

        self::assertTrue(SignupListSection::Basics->isFilledIn($list));
    }

    public function testAMembersOnlyListDoesNotOfferTheNonMemberTier(): void
    {
        $list = $this->list();
        $list->setOnlyGEWIS(true);
        $list->setMembershipTierOrder(array_map(
            static fn (MembershipTier $tier): array => [$tier],
            MembershipTier::defaultOrder(),
        ));

        self::assertSame(
            [
                [MembershipTier::Ordinary],
                [MembershipTier::External],
                [MembershipTier::Honorary],
                [MembershipTier::Graduate],
            ],
            $list->getMembershipTierOrder(),
        );

        $form = $this->section(
            SignupListSection::Allocation,
            $list,
        );

        self::assertSame(
            [
                [MembershipTier::Ordinary],
                [MembershipTier::External],
                [MembershipTier::Honorary],
                [MembershipTier::Graduate],
            ],
            $form->createView()->vars['membershipTierOrderTiers'],
        );
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

    public function testTheWindowOfAListThatIsNotLiveStaysEditable(): void
    {
        $list = $this->list();
        $list->setOpenDate(new DateTime('-1 week'));
        $list->setCloseDate(new DateTime('-1 day'));
        $this->attachToDraft($list);

        $form = $this->section(
            SignupListSection::Basics,
            $list,
        );

        self::assertFalse(
            $this->isDisabled(
                $form,
                'openDate',
            ),
        );
        self::assertFalse(
            $this->isDisabled(
                $form,
                'closeDate',
            ),
        );
    }

    public function testTheWindowOfALiveListThatHasOpenedIsFixed(): void
    {
        $list = $this->list();
        $this->attachToDraft(
            $list,
            live: true,
        );

        $form = $this->section(
            SignupListSection::Basics,
            $list,
        );

        self::assertTrue(
            $this->isDisabled(
                $form,
                'openDate',
            ),
        );
        self::assertTrue(
            $this->isDisabled(
                $form,
                'closeDate',
            ),
        );
    }

    private function attachToDraft(
        SignupList $list,
        bool $live = false,
    ): void {
        $activity = new Activity();

        $draft = new ActivityRevision();
        $activity->addRevision($draft);
        $activity->setCurrentRevision($draft);
        $draft->addSignupList($list);

        if (!$live) {
            return;
        }

        $liveRevision = new ActivityRevision();
        $liveRevision->setStatus(RevisionStatus::Approved);
        $activity->addRevision($liveRevision);
        $activity->setLiveRevision($liveRevision);

        $liveList = new SignupList();
        $liveList->setName(new ActivityLocalisedText());
        $liveList->setLineageId($list->getLineageId());
        $liveList->setOpenDate(new DateTime('-1 week'));
        $liveList->setCloseDate(new DateTime('-1 day'));
        $liveRevision->addSignupList($liveList);
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
        $form = $this->section(
            SignupListSection::Allocation,
            $list ?? $this->list(),
        );

        $form->submit($overrides + [
            'limitedCapacity' => '1',
            'capacity' => '10',
            'allocationMethod' => AllocationMethod::FirstComeFirstServed->value,
            'roles' => [],
        ]);

        return $form;
    }

    /**
     * @return FormInterface<mixed>
     */
    private function section(
        SignupListSection $section,
        SignupList $list,
    ): FormInterface {
        return $this->factory->create(
            SignupListType::class,
            $list,
            ['section' => $section],
        );
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
