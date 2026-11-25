<?php

declare(strict_types=1);

namespace App\Tests\Entity\Activity;

use App\Entity\Activity\Activity;
use App\Entity\Activity\ActivityRevision;
use App\Entity\Activity\Enums\AllocationMethod;
use App\Entity\Activity\Enums\CohortTier;
use App\Entity\Activity\Enums\DrawCutoffRule;
use App\Entity\Activity\Enums\MembershipPriorityMode;
use App\Entity\Activity\Enums\MembershipTier;
use App\Entity\Activity\SignupField;
use App\Entity\Activity\SignupList;
use App\Entity\Activity\SignupRole;
use App\Entity\Activity\UserSignup;
use App\Entity\Database\Enums\ProgramType;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

use function array_keys;

#[CoversClass(SignupList::class)]
final class SignupListTest extends TestCase
{
    /**
     * The deadline shown is from the open list that closes soonest, or from the list that opens soonest when none is
     * open.
     */
    public function testTheRelevantDeadlineIsTheSoonestOpenOrUpcomingOne(): void
    {
        $now = new DateTimeImmutable();

        $closed = $this->list(
            '-2 days',
            '-1 day',
        );
        $openLate = $this->list(
            '-1 day',
            '+5 days',
        );
        $openSoon = $this->list(
            '-1 day',
            '+1 day',
        );
        $upcomingSoon = $this->list(
            '+1 day',
            '+3 days',
        );
        $upcomingLate = $this->list(
            '+2 days',
            '+4 days',
        );

        self::assertSame(
            $openSoon,
            SignupList::relevantAmong(
                [
                    $closed,
                    $openLate,
                    $openSoon,
                    $upcomingSoon,
                    $upcomingLate,
                ],
                $now,
            ),
        );
        self::assertSame(
            $upcomingSoon,
            SignupList::relevantAmong(
                [
                    $closed,
                    $upcomingLate,
                    $upcomingSoon,
                ],
                $now,
            ),
        );
        self::assertNull(SignupList::relevantAmong([$closed], $now));
        self::assertNull(SignupList::relevantAmong([], $now));
    }

    /**
     * The next moment the page changes is the earliest future open or close of any list; a boundary exactly now is
     * skipped.
     */
    public function testTheNextTransitionIsTheEarliestFutureOpenOrClose(): void
    {
        $now = new DateTimeImmutable();

        $list = $this->list(
            '-1 day',
            '+2 days',
        );
        $second = $this->list(
            '+1 day',
            '+5 days',
        );
        $exactlyNow = $this->list(
            $now->format('Y-m-d H:i:s'),
            null,
        );

        self::assertNotNull($second->openDate);
        self::assertSame(
            $second->openDate->format('c'),
            SignupList::nextTransitionAmong([$list, $second, $exactlyNow], $now)?->format('c'),
        );
        self::assertNull(SignupList::nextTransitionAmong([$exactlyNow], $now));
    }

    /**
     * Two lists opening at the same instant are equally soon; the first one wins so the answer does not depend on the
     * order the caller happened to pass them in.
     */
    public function testAnUpcomingTieKeepsTheFirstList(): void
    {
        $now = new DateTimeImmutable();
        $first = $this->list(
            '+1 day',
            '+3 days',
        );
        $second = $this->list(
            '+1 day',
            '+4 days',
        );

        self::assertSame(
            $first,
            SignupList::relevantAmong(
                [
                    $first,
                    $second,
                ],
                $now,
            ),
        );
    }

    /**
     * A moment that is not in the future is skipped, but it must not stop the remaining lists from being read.
     */
    public function testAMomentAtTheBoundaryDoesNotStopLaterLists(): void
    {
        $now = new DateTimeImmutable('2026-01-01 12:00:00');
        $boundary = $this->list(
            '2026-01-01 12:00:00',
            null,
        );
        $future = $this->list(
            '2026-01-02 12:00:00',
            null,
        );

        self::assertSame(
            $future->openDate,
            SignupList::nextTransitionAmong(
                [
                    $boundary,
                    $future,
                ],
                $now,
            ),
        );
    }

    /**
     * A list before its open date is neither open nor closed; a list after its close date is closed and no longer
     * open. The boundaries are the instants themselves.
     */
    public function testOpenAndClosedAreNotEachOthersInverse(): void
    {
        $upcoming = $this->list(
            '+1 minute',
            '+2 minutes',
        );
        self::assertFalse($upcoming->isOpen());
        self::assertFalse($upcoming->isClosed());

        $open = $this->list(
            '-1 minute',
            '+1 minute',
        );
        self::assertTrue($open->isOpen());
        self::assertFalse($open->isClosed());

        $closed = $this->list(
            '-2 minutes',
            '-1 minute',
        );
        self::assertFalse($closed->isOpen());
        self::assertTrue($closed->isClosed());

        $undated = new SignupList();
        self::assertFalse($undated->isOpen());
        self::assertFalse($undated->isClosed());
    }

    /**
     * The automated draw runs when the list's allocation rule says it should: an unlimited list and a manual method
     * never draw, a first-come-first-served list draws at close, and a conditional draw follows its cutoff rule.
     */
    public function testTheAutomaticDrawMomentFollowsTheAllocationRule(): void
    {
        $list = $this->list(
            '-2 hours',
            '+2 hours',
        );
        self::assertNull($list->getAutoDrawAt());

        $list->limitedCapacity = true;
        $list->allocationMethod = AllocationMethod::FirstComeFirstServed;
        self::assertSame(
            $list->closeDate,
            $list->getAutoDrawAt(),
        );

        $list->allocationMethod = AllocationMethod::ExternalParty;
        self::assertNull($list->getAutoDrawAt());

        $list->allocationMethod = AllocationMethod::Custom;
        self::assertNull($list->getAutoDrawAt());

        $list->allocationMethod = AllocationMethod::ConditionalDraw;
        $list->drawCutoffRule = DrawCutoffRule::OnClose;
        self::assertSame(
            $list->closeDate,
            $list->getAutoDrawAt(),
        );

        $cutoff = new DateTimeImmutable('+1 hour');
        $list->drawCutoffRule = DrawCutoffRule::IfFullBefore;
        $list->drawCutoffAt = $cutoff;
        self::assertSame(
            $cutoff,
            $list->getAutoDrawAt(),
        );

        $list->drawCutoffRule = DrawCutoffRule::AfterDurationOpen;
        $list->drawAfterDurationHours = 3;
        $list->openDate = new DateTimeImmutable('-1 hour');
        self::assertEquals(
            $list->openDate->modify('+3 hours'),
            $list->getAutoDrawAt(),
        );

        $list->drawAfterDurationHours = null;
        self::assertNull($list->getAutoDrawAt());

        $list->drawAfterDurationHours = 3;
        $list->openDate = null;
        self::assertNull($list->getAutoDrawAt());
    }

    /**
     * A moment in the past is due whether or not the command ran; a list without a moment is never due.
     */
    public function testADrawIsDueOnceItsMomentHasPassed(): void
    {
        $list = $this->list(
            '-2 hours',
            '-1 hour',
        );
        self::assertNull($list->getAutoDrawAt());
        self::assertFalse($list->isAutoDrawDue());

        $list->limitedCapacity = true;
        self::assertTrue($list->isAutoDrawDue());

        $list->closeDate = new DateTimeImmutable('+1 hour');
        self::assertFalse($list->isAutoDrawDue());
    }

    /**
     * A members-only list cannot admit non-members, so that tier is not offered and its rank is not shown. The list
     * can still contain the tier internally, which is why the order is filtered here.
     */
    public function testAMembersOnlyListDropsTheNonMemberTier(): void
    {
        $list = new SignupList();
        $list->onlyGEWIS = true;

        self::assertSame(
            [
                MembershipTier::Ordinary,
                MembershipTier::External,
                MembershipTier::Honorary,
                MembershipTier::Graduate,
            ],
            $list->membershipTiers(),
        );

        self::assertSame(
            [
                [
                    MembershipTier::Ordinary,
                    MembershipTier::External,
                    MembershipTier::Honorary,
                ],
                [MembershipTier::Graduate],
            ],
            $list->membershipRanks(),
        );

        $list->onlyGEWIS = false;
        self::assertSame(
            MembershipTier::defaultOrder(),
            $list->membershipTiers(),
        );
        self::assertSame(
            MembershipTier::defaultRanks(),
            $list->membershipRanks(),
        );
    }

    /**
     * A stored order covers only the part the organiser rearranged. Tiers it left out are appended beside the tiers
     * the association ranks them with, and a tier that no longer applies is dropped.
     */
    public function testAStoredOrderIsCompletedWithTheMissingTiers(): void
    {
        $list = new SignupList();
        $list->onlyGEWIS = true;
        $list->setMembershipTierOrder([
            [MembershipTier::Graduate],
            [
                MembershipTier::External,
                MembershipTier::Ordinary,
                MembershipTier::Ordinary,
            ],
        ]);
        new ReflectionProperty(
            SignupList::class,
            'membershipTierOrder',
        )->setValue(
            $list,
            [
                ['graduate'],
                [
                    'not a tier',
                    'external',
                ],
            ],
        );

        self::assertSame(
            [
                [MembershipTier::Graduate],
                [MembershipTier::External],
                [
                    MembershipTier::Ordinary,
                    MembershipTier::Honorary,
                ],
            ],
            $list->getMembershipTierOrder(),
        );

        $list->setMembershipTierOrder(null);
        self::assertNull($list->getMembershipTierOrder());

        $list->setMembershipTierOrder([]);
        self::assertNull($list->getMembershipTierOrder());
    }

    /**
     * Places are reserved per rank, and a rank is a set: moving two tiers past one another keeps the number reserved
     * for them.
     */
    public function testPlacesAreReservedPerRankInTheAssociationsOrder(): void
    {
        self::assertSame(
            'ordinary+external+honorary',
            SignupList::rankKey([
                MembershipTier::Honorary,
                MembershipTier::Ordinary,
                MembershipTier::External,
            ]),
        );

        $list = new SignupList();
        $list->onlyGEWIS = true;
        $list->membershipPriorityMode = MembershipPriorityMode::Ordering;
        $list->setMembershipTierOrder([
            [
                MembershipTier::Ordinary,
                MembershipTier::External,
            ],
            [MembershipTier::Honorary],
            [MembershipTier::Graduate],
        ]);
        $list->setHeldMembershipPlaces(['ordinary+external' => 2]);

        self::assertSame(
            [],
            $list->getMembershipPlaces(),
        );
        self::assertSame(
            2,
            $list->getMembershipPlacesForRank([MembershipTier::Ordinary, MembershipTier::External]),
        );
        self::assertNull($list->getMembershipPlacesForRank([MembershipTier::Graduate]));

        $list->membershipPriorityMode = MembershipPriorityMode::ReservedPlaces;

        self::assertSame(
            [
                'ordinary+external' => 2,
                'honorary' => 0,
                'graduate' => 0,
            ],
            $list->getMembershipPlaces(),
        );
        self::assertSame(
            ['ordinary+external' => 2],
            $list->getHeldMembershipPlaces(),
        );

        $list->setHeldMembershipPlaces([]);
        self::assertNull($list->getHeldMembershipPlaces());
    }

    /**
     * Cohort and programme orders are only meaningful on a members-only list; on a list open to externals there is no
     * membership to rank.
     */
    public function testCohortAndProgrammeOrdersOnlyApplyToMembersOnlyLists(): void
    {
        $list = new SignupList();
        $list->onlyGEWIS = false;
        $list->setCohortTierOrder([[CohortTier::SecondYear]]);
        $list->setProgramTypeOrder([[ProgramType::Master]]);

        self::assertNull($list->getCohortTierOrder());
        self::assertNull($list->getProgramTypeOrder());

        $list->onlyGEWIS = true;

        self::assertSame(
            [
                [CohortTier::SecondYear],
                [CohortTier::FirstYear],
                [CohortTier::Senior],
                [CohortTier::Unknown],
            ],
            $list->getCohortTierOrder(),
        );
        self::assertSame(
            [
                [ProgramType::Master],
                [ProgramType::Bachelor],
                [ProgramType::Doctorate],
                [ProgramType::Other],
            ],
            $list->getProgramTypeOrder(),
        );
    }

    /**
     * The overview says the list has priority modifiers only when one applies, not whenever a field was filled in.
     */
    public function testPriorityIsReportedForAnyModifierThatApplies(): void
    {
        $list = new SignupList();
        self::assertFalse($list->hasPriorityModifiers());

        $list->organisingCommitteePlaces = 1;
        self::assertTrue($list->hasPriorityModifiers());

        $list->organisingCommitteePlaces = null;
        $list->setMembershipTierOrder([[MembershipTier::Ordinary]]);
        self::assertTrue($list->hasPriorityModifiers());

        $list->setMembershipTierOrder(null);
        $role = new SignupRole();
        $role->name = 'Voorzitter';
        $list->addRole($role);
        self::assertTrue($list->hasPriorityModifiers());

        $list->removeRole($role);
        self::assertFalse($list->hasPriorityModifiers());
    }

    /**
     * Adding a field or role sets the inverse side too, and adding the same one twice does not duplicate it.
     */
    public function testFieldsAndRolesKeepBothSidesOfTheirAssociation(): void
    {
        $list = new SignupList();

        $field = new SignupField();
        $field->isSensitive = true;
        $list->addField($field);
        $list->addField($field);

        self::assertCount(
            1,
            $list->getFields(),
        );
        self::assertSame(
            $list,
            $field->signupList,
        );
        self::assertTrue($list->hasSensitiveField());

        $list->removeField($field);
        self::assertCount(
            0,
            $list->getFields(),
        );
        self::assertFalse($list->hasSensitiveField());

        $role = new SignupRole();
        $list->addRole($role);
        $list->addRole($role);

        self::assertCount(
            1,
            $list->getRoles(),
        );
        self::assertSame(
            $list,
            $role->signupList,
        );
    }

    /**
     * Draw order is the order the draw left, admitted first and the waiting list behind it; before a draw it is the
     * sign-up order.
     */
    public function testAdmissionOrderFollowsTheDrawOnceItHasRun(): void
    {
        $list = new SignupList();
        $first = $this->signup(
            $list,
            10,
            2,
        );
        $second = $this->signup(
            $list,
            20,
            1,
        );
        $waiting = $this->signup(
            $list,
            5,
            null,
        );

        self::assertSame(
            [
                $first,
                $second,
                $waiting,
            ],
            $list->getSignUpsInAdmissionOrder(),
        );

        $list->drawnAt = new DateTimeImmutable();

        self::assertSame(
            [
                $second,
                $first,
                $waiting,
            ],
            $list->getSignUpsInAdmissionOrder(),
        );
        self::assertTrue($list->isDrawLocked());
        self::assertTrue($list->hasSignUps());
        self::assertTrue($list->hasLineageSignUps());
    }

    /**
     * A drafted list keeps its sign-ups and window on the live revision until approval, so the draft finds its
     * counterpart by lineage rather than in its own empty collections.
     */
    public function testADraftListFindsItsLiveCounterpartByLineage(): void
    {
        $activity = new Activity();
        $live = new ActivityRevision();
        $live->activity = $activity;
        $draft = new ActivityRevision();
        $draft->activity = $activity;
        $activity->setLiveRevision($live);

        $liveList = new SignupList();
        $live->addSignupList($liveList);

        $draftList = new SignupList();
        $draftList->lineageId = $liveList->lineageId;
        $draft->addSignupList($draftList);

        self::assertTrue($draftList->belongsToRevision());
        self::assertSame(
            $liveList,
            $draftList->liveCounterpart(),
        );
        self::assertSame(
            $activity,
            $draftList->getActivity(),
        );

        $other = new SignupList();
        $draft->addSignupList($other);

        self::assertNull($other->liveCounterpart());

        $withoutLiveActivity = new Activity();
        $withoutLive = new ActivityRevision();
        $withoutLive->activity = $withoutLiveActivity;
        $orphan = new SignupList();
        $withoutLive->addSignupList($orphan);

        self::assertNull($orphan->liveCounterpart());

        self::assertNull(new SignupList()->liveCounterpart());
    }

    /**
     * The exported array carries the list's content and settings, including its fields and roles; the GDPR export
     * formats dates as instants and texts as a per-language pair.
     */
    public function testTheExportsCarryTheListsContent(): void
    {
        $list = new SignupList();
        $list->name->updateValues(
            'Name',
            'Naam',
        );
        $list->openDate = new DateTimeImmutable('2026-06-01 12:00:00');
        $list->closeDate = new DateTimeImmutable('2026-06-02 12:00:00');

        $field = new SignupField();
        $field->name->updateValues(
            'Question',
            'Vraag',
        );
        $list->addField($field);

        $role = new SignupRole();
        $role->name = 'Voorzitter';
        $role->minimum = 2;
        $list->addRole($role);

        $secondRole = new SignupRole();
        $secondRole->name = 'Secretaris';
        $secondRole->minimum = 1;
        $list->addRole($secondRole);

        $array = $list->toArray();
        self::assertSame(
            [
                'id',
                'name',
                'nameEn',
                'openDate',
                'closeDate',
                'onlyGEWIS',
                'displaySubscribedNumber',
                'limitedCapacity',
                'capacity',
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
                'membershipPlaces',
                'cohortTierOrder',
                'programTypeOrder',
                'organisingCommitteePlaces',
                'roles',
                'presenceTaken',
                'promoted',
                'fields',
            ],
            array_keys($array),
        );
        self::assertSame(
            [
                'id',
                'sensitive',
                'name',
                'nameEn',
                'type',
                'minimumValue',
                'maximumValue',
                'options',
                'optionsEn',
            ],
            array_keys($array['fields'][0]),
        );
        self::assertSame(
            'Naam',
            $array['name'],
        );
        self::assertSame(
            'Name',
            $array['nameEn'],
        );
        self::assertSame(
            $list->openDate,
            $array['openDate'],
        );
        self::assertSame(
            'Vraag',
            $array['fields'][0]['name'],
        );
        self::assertSame(
            [
                [
                    'name' => 'Voorzitter',
                    'minimum' => 2,
                ],
                [
                    'name' => 'Secretaris',
                    'minimum' => 1,
                ],
            ],
            $array['roles'],
        );

        $gdpr = $list->toGdprArray();
        self::assertSame(
            [
                'id',
                'name',
                'openDate',
                'closeDate',
                'onlyGEWIS',
                'displaySubscribedNumber',
                'limitedCapacity',
                'capacity',
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
                'membershipPlaces',
                'cohortTierOrder',
                'programTypeOrder',
                'organisingCommitteePlaces',
                'roles',
                'presenceTaken',
                'promoted',
                'fields',
            ],
            array_keys($gdpr),
        );
        self::assertSame(
            [
                'id',
                'sensitive',
                'name',
                'type',
                'minimumValue',
                'maximumValue',
                'options',
            ],
            array_keys($gdpr['fields'][0]),
        );
        self::assertSame(
            '2026-06-01T12:00:00+02:00',
            $gdpr['openDate'],
        );
        self::assertSame(
            '2026-06-02T12:00:00+02:00',
            $gdpr['closeDate'],
        );
        self::assertSame(
            [
                'valueEN' => 'Name',
                'valueNL' => 'Naam',
            ],
            $gdpr['name'],
        );
        self::assertSame(
            [
                [
                    'name' => 'Voorzitter',
                    'minimum' => 2,
                ],
                [
                    'name' => 'Secretaris',
                    'minimum' => 1,
                ],
            ],
            $gdpr['roles'],
        );
    }

    private function list(
        ?string $open,
        ?string $close,
    ): SignupList {
        $list = new SignupList();
        $list->openDate = null === $open
            ? null
            : new DateTimeImmutable($open);
        $list->closeDate = null === $close
            ? null
            : new DateTimeImmutable($close);

        return $list;
    }

    private function signup(
        SignupList $list,
        int $id,
        ?int $drawPosition,
    ): UserSignup {
        $signup = new UserSignup();
        $signup->signupList = $list;
        $signup->drawPosition = $drawPosition;
        new ReflectionProperty(
            UserSignup::class,
            'id',
        )->setValue(
            $signup,
            $id,
        );
        $list->getSignUps()->add($signup);

        return $signup;
    }
}
