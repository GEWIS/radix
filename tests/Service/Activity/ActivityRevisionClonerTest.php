<?php

declare(strict_types=1);

namespace App\Tests\Service\Activity;

use App\Entity\Activity\Activity;
use App\Entity\Activity\ActivityLabel;
use App\Entity\Activity\ActivityLocalisedText;
use App\Entity\Activity\ActivityRevision;
use App\Entity\Activity\Enums\ActivityCategories;
use App\Entity\Activity\Enums\MembershipPriorityMode;
use App\Entity\Activity\Enums\MembershipTier;
use App\Entity\Activity\Enums\SignupFieldTypes;
use App\Entity\Activity\ExternalSignup;
use App\Entity\Activity\SignupField;
use App\Entity\Activity\SignupList;
use App\Entity\Activity\SignupOption;
use App\Entity\Activity\SignupRole;
use App\Entity\Application\Enums\RevisionStatus;
use App\Entity\Career\Company;
use App\Entity\Decision\Member;
use App\Entity\Decision\Organ;
use App\Service\Activity\ActivityRevisionCloner;
use DateTime;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * Spawning draft N+1 must produce an *independent* copy of the source revision's content: the localised texts have to
 * become fresh rows (the relations are orphan-removing, so a shared row would be deleted with the source), the
 * schedule must be cloned by value, the reference entities (organ, company, labels) must be carried over by reference,
 * and the sign-up lists must be deep-cloned, keeping their lineage id so approval can migrate the live sign-ups onto
 * them, but never carrying the sign-ups themselves (those stay on the live revision until approval). These tests pin
 * that contract; a regression here silently blanks an editor's draft or, worse, lets a draft delete live content.
 */
final class ActivityRevisionClonerTest extends TestCase
{
    private ActivityRevisionCloner $cloner;

    #[Override]
    protected function setUp(): void
    {
        $this->cloner = new ActivityRevisionCloner();
    }

    public function testLinksTheDraftIntoTheChainAsTheNewWorkingHead(): void
    {
        $source = $this->approvedSource();
        $activity = $source->activity;

        $draft = $this->cloner->cloneAsDraft($source);
        self::assertInstanceOf(
            ActivityRevision::class,
            $draft,
        );

        self::assertSame(
            $source,
            $draft->getPreviousRevision(),
        );
        self::assertSame(
            $activity,
            $draft->activity,
        );
        self::assertSame(
            $draft,
            $activity->getCurrentRevision(),
        );
        self::assertTrue($activity->getRevisions()->contains($draft));
    }

    public function testStartsAsDraftNumberedAfterTheSourceCarryingItsAuthor(): void
    {
        $author = self::createStub(Member::class);
        $source = $this->approvedSource($author);
        $source->setRevisionNumber(3);

        $draft = $this->cloner->cloneAsDraft($source);
        self::assertInstanceOf(
            ActivityRevision::class,
            $draft,
        );

        // A spawned draft always reopens as an editable Draft, one past the source, with the source's authorship
        // carried forward (a controller may reassign it afterwards).
        self::assertSame(
            RevisionStatus::Draft,
            $draft->getStatus(),
        );
        self::assertSame(
            4,
            $draft->getRevisionNumber(),
        );
        self::assertSame(
            $author,
            $draft->getAuthor(),
        );
        self::assertNull($draft->getAuthorCompanyUser());
    }

    public function testDeepCopiesTheLocalisedTextsIntoFreshRows(): void
    {
        $source = $this->approvedSource();

        $draft = $this->cloner->cloneAsDraft($source);
        self::assertInstanceOf(
            ActivityRevision::class,
            $draft,
        );

        // The texts must be distinct instances with equal values: the OneToOne relations are orphan-removing, so a
        // shared row would be deleted out from under the source revision when the draft is later discarded.
        $this->assertCopiedNotShared(
            $source->name,
            $draft->name,
        );
        $this->assertCopiedNotShared(
            $source->location,
            $draft->location,
        );
        $this->assertCopiedNotShared(
            $source->costs,
            $draft->costs,
        );
        $this->assertCopiedNotShared(
            $source->description,
            $draft->description,
        );
    }

    public function testClonesTheScheduleByValueAndCopiesTheFlagsAndCategory(): void
    {
        $source = $this->approvedSource();

        $draft = $this->cloner->cloneAsDraft($source);
        self::assertInstanceOf(
            ActivityRevision::class,
            $draft,
        );

        // The schedule is mutable state, so it is cloned (equal value, distinct instance) rather than shared.
        self::assertEquals(
            $source->beginTime,
            $draft->beginTime,
        );
        self::assertNotSame(
            $source->beginTime,
            $draft->beginTime,
        );
        self::assertEquals(
            $source->endTime,
            $draft->endTime,
        );
        self::assertNotSame(
            $source->endTime,
            $draft->endTime,
        );

        self::assertSame(
            ActivityCategories::Workshop,
            $draft->category,
        );
        self::assertTrue($draft->requireGEFLITST);
        self::assertTrue($draft->requireZettle);
    }

    public function testCarriesTheReferenceEntitiesOverByReference(): void
    {
        $organ = self::createStub(Organ::class);
        $company = self::createStub(Company::class);
        $label = self::createStub(ActivityLabel::class);
        $source = $this->approvedSource(
            organ: $organ,
            company: $company,
            label: $label,
        );

        $draft = $this->cloner->cloneAsDraft($source);
        self::assertInstanceOf(
            ActivityRevision::class,
            $draft,
        );

        // Organ, company and labels are shared reference entities (not owned content), so the draft points at the very
        // same instances rather than copies.
        self::assertSame(
            $organ,
            $draft->organ,
        );
        self::assertSame(
            $company,
            $draft->company,
        );
        self::assertTrue($draft->getLabels()->contains($label));
    }

    public function testDeepClonesSignupListsKeepingLineageButDroppingSignups(): void
    {
        $source = $this->approvedSource();
        $sourceList = $source->getSignupLists()->getValues()[0];

        $draft = $this->cloner->cloneAsDraft($source);
        self::assertInstanceOf(
            ActivityRevision::class,
            $draft,
        );

        $draftLists = $draft->getSignupLists()->getValues();
        self::assertCount(
            1,
            $draftLists,
        );
        $draftList = $draftLists[0];

        // A fresh list owned by the draft, but on the same lineage so approval can migrate the live sign-ups onto it.
        self::assertNotSame(
            $sourceList,
            $draftList,
        );
        self::assertSame(
            $draft,
            $draftList->revision,
        );
        self::assertTrue($draftList->lineageId->equals($sourceList->lineageId));
        // The sign-ups are deliberately NOT carried: they stay on the live revision until the approval migration.
        self::assertTrue($draftList->getSignUps()->isEmpty());
        self::assertFalse($sourceList->getSignUps()->isEmpty());

        // Fields and options are deep-cloned (distinct instances, equal layout) so editing the draft cannot mutate the
        // live list's structure.
        $sourceField = $sourceList->getFields()->getValues()[0];
        $draftField = $draftList->getFields()->getValues()[0];
        self::assertNotSame(
            $sourceField,
            $draftField,
        );
        self::assertSame(
            $sourceField->type,
            $draftField->type,
        );
        $this->assertCopiedNotShared(
            $sourceField->name,
            $draftField->name,
        );

        $sourceOption = $sourceField->getOptions()->getValues()[0];
        $draftOption = $draftField->getOptions()->getValues()[0];
        self::assertNotSame(
            $sourceOption,
            $draftOption,
        );
        $this->assertCopiedNotShared(
            $sourceOption->value,
            $draftOption->value,
        );

        // The reorder position and the default marker are carried forward too, else a reordered list or a chosen
        // default would silently revert to id order / no default on the next draft.
        self::assertSame(
            2,
            $draftField->position,
        );
        self::assertSame(
            3,
            $draftOption->position,
        );
        self::assertTrue($draftOption->isDefault);
    }

    public function testCarriesThePriorityModifiersAndDeepClonesTheRoles(): void
    {
        $source = $this->approvedSource();
        $sourceList = $source->getSignupLists()->getValues()[0];

        $draft = $this->cloner->cloneAsDraft($source);
        self::assertInstanceOf(
            ActivityRevision::class,
            $draft,
        );

        $draftList = $draft->getSignupLists()->getValues()[0];

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
            $draftList->getMembershipTierOrder(),
        );
        self::assertSame(
            MembershipPriorityMode::ReservedPlaces,
            $draftList->membershipPriorityMode,
        );
        self::assertSame(
            [MembershipTier::Ordinary->value => 3],
            $draftList->getHeldMembershipPlaces(),
        );
        self::assertSame(
            2,
            $draftList->organisingCommitteePlaces,
        );

        $sourceRole = $sourceList->getRoles()->getValues()[0];
        $draftRole = $draftList->getRoles()->getValues()[0];
        self::assertNotSame(
            $sourceRole,
            $draftRole,
        );
        self::assertSame(
            'Driver',
            $draftRole->name,
        );
        self::assertSame(
            4,
            $draftRole->minimum,
        );
        self::assertSame(
            1,
            $draftRole->position,
        );
        self::assertSame(
            $draftList,
            $draftRole->signupList,
        );
    }

    private function assertCopiedNotShared(
        ActivityLocalisedText $source,
        ActivityLocalisedText $draft,
    ): void {
        self::assertNotSame(
            $source,
            $draft,
            'localised text must be a fresh row, not the shared (orphan-removing) source row',
        );
        self::assertSame(
            $source->getValueNL(),
            $draft->getValueNL(),
        );
        self::assertSame(
            $source->getValueEN(),
            $draft->getValueEN(),
        );
    }

    /**
     * An approved source revision attached to an activity, populated across every kind of field the cloner handles:
     * owned localised texts, a mutable schedule, value flags/category, reference entities, and a sign-up list that
     * already has a sign-up (which must NOT be carried over).
     */
    private function approvedSource(
        ?Member $author = null,
        ?Organ $organ = null,
        ?Company $company = null,
        ?ActivityLabel $label = null,
    ): ActivityRevision {
        $activity = new Activity();

        $source = new ActivityRevision();
        $activity->addRevision($source);
        $activity->setCurrentRevision($source);

        $source->setStatus(RevisionStatus::Approved);
        $source->setRevisionNumber(1);
        $source->setAuthor($author ?? self::createStub(Member::class));
        $source->name = $this->text(
            'Lecture',
            'College',
        );
        $source->location = $this->text(
            'Aula',
            'Aula',
        );
        $source->costs = $this->text(
            'Free',
            'Gratis',
        );
        $source->description = $this->text(
            'A talk.',
            'Een praatje.',
        );
        $source->beginTime = new DateTime('2026-07-01 18:00');
        $source->endTime = new DateTime('2026-07-01 22:00');
        $source->category = ActivityCategories::Workshop;
        $source->requireGEFLITST = true;
        $source->requireZettle = true;
        $source->organ = $organ ?? self::createStub(Organ::class);
        $source->company = $company ?? self::createStub(Company::class);
        $source->addLabel($label ?? self::createStub(ActivityLabel::class));
        $source->addSignupList($this->signupListWithSignup());

        return $source;
    }

    private function signupListWithSignup(): SignupList
    {
        $list = new SignupList();
        $list->name = $this->text(
            'Attendees',
            'Aanwezigen',
        );

        $field = new SignupField();
        $field->name = $this->text(
            'Colour',
            'Kleur',
        );
        $field->type = SignupFieldTypes::Choice;
        $field->position = 2;

        $option = new SignupOption();
        $option->value = $this->text(
            'Red',
            'Rood',
        );
        $option->position = 3;
        $option->isDefault = true;
        $field->addOption($option);
        $list->addField($field);

        $role = new SignupRole();
        $role->name = 'Driver';
        $role->minimum = 4;
        $role->position = 1;
        $list->addRole($role);

        $list->setMembershipTierOrder([
            [MembershipTier::NonMember],
            [MembershipTier::Ordinary],
            [MembershipTier::Graduate],
        ]);
        $list->membershipPriorityMode = MembershipPriorityMode::ReservedPlaces;
        $list->setHeldMembershipPlaces([MembershipTier::Ordinary->value => 3]);
        $list->organisingCommitteePlaces = 2;

        $signup = new ExternalSignup();
        $signup->signupList = $list;
        $list->getSignUps()->add($signup);

        return $list;
    }

    private function text(
        string $en,
        string $nl,
    ): ActivityLocalisedText {
        return new ActivityLocalisedText(
            $en,
            $nl,
        );
    }
}
