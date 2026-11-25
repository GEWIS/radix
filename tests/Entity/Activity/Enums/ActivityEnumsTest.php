<?php

declare(strict_types=1);

namespace App\Tests\Entity\Activity\Enums;

use App\Entity\Activity\Enums\ActivityCategories;
use App\Entity\Activity\Enums\AllocationMethod;
use App\Entity\Activity\Enums\AnnouncementPlaceholder;
use App\Entity\Activity\Enums\BudgetClearance;
use App\Entity\Activity\Enums\CalendarEntryKind;
use App\Entity\Activity\Enums\CohortTier;
use App\Entity\Activity\Enums\DateOptionStatus;
use App\Entity\Activity\Enums\MembershipTier;
use App\Entity\Activity\Enums\ProposalLimitSource;
use App\Entity\Activity\Enums\RecipientScope;
use App\Entity\Activity\Enums\SignupFilter;
use App\Entity\Database\Enums\MembershipTypes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

use function count;

#[CoversClass(ActivityCategories::class)]
#[CoversClass(AllocationMethod::class)]
#[CoversClass(BudgetClearance::class)]
#[CoversClass(CalendarEntryKind::class)]
#[CoversClass(DateOptionStatus::class)]
#[CoversClass(RecipientScope::class)]
#[CoversClass(SignupFilter::class)]
#[CoversClass(MembershipTier::class)]
#[CoversClass(CohortTier::class)]
#[CoversClass(ProposalLimitSource::class)]
final class ActivityEnumsTest extends TestCase
{
    /**
     * The migration-only category is not offered for a new activity, but stays translatable for activities that
     * already carry it.
     */
    public function testTheUncategorisedCategoryIsNeverSelectable(): void
    {
        $selectable = ActivityCategories::selectableCases();

        self::assertNotContains(
            ActivityCategories::Uncategorised,
            $selectable,
        );
        self::assertCount(
            count(ActivityCategories::cases()) - 1,
            $selectable,
        );

        $translator = $this->translator();
        $labels = [];
        foreach (ActivityCategories::cases() as $category) {
            $labels[$category->trans($translator)] = true;
        }

        self::assertCount(
            count(ActivityCategories::cases()),
            $labels,
        );
        self::assertSame(
            'Uncategorised',
            ActivityCategories::Uncategorised->trans($translator),
        );
    }

    /**
     * Admission decided outside the system is recorded rather than drawn, so the in-app draw is not offered.
     */
    public function testOnlyTheAllocationMethodsThatNeedADrawAreNotManual(): void
    {
        foreach (AllocationMethod::cases() as $method) {
            self::assertSame(
                AllocationMethod::ExternalParty === $method || AllocationMethod::Custom === $method,
                $method->isManual(),
                $method->value,
            );
        }

        self::assertSame(
            'Custom',
            AllocationMethod::Custom->trans($this->translator()),
        );
    }

    /**
     * A proposed date blocks the calendar until it is decided, and an approved date afterwards; a declined or
     * withdrawn date has released it.
     */
    public function testOnlyAnUndecidedOrApprovedDateStillStands(): void
    {
        foreach (DateOptionStatus::cases() as $status) {
            self::assertSame(
                DateOptionStatus::Proposed === $status || DateOptionStatus::Approved === $status,
                $status->isStanding(),
                $status->value,
            );
        }

        self::assertSame(
            'Reserved',
            DateOptionStatus::Approved->trans($this->translator()),
        );
    }

    /**
     * A limited list offers both halves of its draw; an unlimited one has no waiting list. The activity-wide table
     * offers the multi-list and single-list filters instead.
     */
    public function testTheQuickFiltersFollowWhatTheTableCanShow(): void
    {
        self::assertSame(
            [
                SignupFilter::All,
                SignupFilter::Admitted,
                SignupFilter::Waiting,
                SignupFilter::External,
                SignupFilter::Multi,
            ],
            SignupFilter::forList(true),
        );
        self::assertSame(
            [
                SignupFilter::All,
                SignupFilter::External,
                SignupFilter::Multi,
            ],
            SignupFilter::forList(false),
        );
        self::assertSame(
            [
                SignupFilter::All,
                SignupFilter::Multi,
                SignupFilter::One,
                SignupFilter::External,
                SignupFilter::Waiting,
            ],
            SignupFilter::forPeople(),
        );
    }

    public function testTheCalendarBlocksAreDrawnWithOneModifierEach(): void
    {
        foreach (CalendarEntryKind::cases() as $kind) {
            self::assertSame(
                'option-calendar__entry--' . $kind->value,
                $kind->modifier(),
            );
        }
    }

    public function testTheClearanceAndScopeLabelsAreTranslatable(): void
    {
        self::assertSame(
            'Budget approved',
            BudgetClearance::Approved->trans($this->translator()),
        );
        self::assertSame(
            'No budget needed',
            BudgetClearance::NotRequired->trans($this->translator()),
        );
        self::assertSame(
            'All subscribers',
            RecipientScope::All->trans($this->translator()),
        );
        self::assertSame(
            'Admitted, not present',
            RecipientScope::NoShow->trans($this->translator()),
        );
    }

    /**
     * Every label is a different phrase; a copy-paste that leaves two cases with one word makes the picker ambiguous.
     */
    public function testEveryLabelIsDistinctWithinItsEnum(): void
    {
        $translator = $this->translator();

        foreach (
            [
                AllocationMethod::cases(),
                BudgetClearance::cases(),
                CalendarEntryKind::cases(),
                DateOptionStatus::cases(),
                RecipientScope::cases(),
                SignupFilter::cases(),
                MembershipTier::cases(),
                CohortTier::cases(),
                ProposalLimitSource::cases(),
            ] as $cases
        ) {
            $labels = [];
            foreach ($cases as $case) {
                $label = $case->trans($translator);
                self::assertNotSame(
                    '',
                    $label,
                    $case->value,
                );
                $labels[$label] = true;
            }

            self::assertCount(
                count($cases),
                $labels,
            );
        }
    }

    /**
     * A membership tier is the enum counterpart of the membership type, so the mapping has to cover every type and the
     * non-member tier has no type at all.
     */
    public function testAMembershipTierMapsToItsMembershipType(): void
    {
        foreach (MembershipTypes::cases() as $type) {
            self::assertSame(
                $type,
                MembershipTier::of($type)->membershipType(),
            );
        }

        self::assertNull(MembershipTier::NonMember->membershipType());
        self::assertSame(
            'Non-members',
            MembershipTier::NonMember->trans($this->translator()),
        );
    }

    /**
     * Only the list name comes from one list; every other placeholder is available to a message addressed to the whole
     * activity.
     */
    public function testOnlyTheListNamePlaceholderNeedsASignupList(): void
    {
        foreach (AnnouncementPlaceholder::cases() as $placeholder) {
            self::assertSame(
                'SIGNUPLIST_NAME' === $placeholder->value,
                $placeholder->needsSignupList(),
                $placeholder->value,
            );
        }
    }

    private function translator(): TranslatorInterface
    {
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return $translator;
    }
}
