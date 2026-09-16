<?php

declare(strict_types=1);

namespace App\Service\Activity;

use App\Entity\Activity\ActivityRevision;
use App\Entity\Activity\SignupField;
use App\Entity\Activity\SignupList;
use App\Entity\Activity\SignupOption;
use App\Entity\Activity\SignupRole;
use App\Entity\Application\AbstractRevision;
use App\Entity\Application\RevisionInterface;
use App\Workflow\AbstractRevisionCloner;
use DateTimeImmutable;
use Override;

use function assert;

/**
 * Spawns the next Draft {@see ActivityRevision} from an existing one (for "changes requested", reopening, or editing
 * an approved activity). The localised texts are deep-copied into fresh rows so orphan-removal can never delete the
 * source revision's content; the schedule, category and flags are copied by value, and the organ, company and labels
 * (reference entities) are carried over by reference. The sign-up lists (with their fields and options) are
 * deep-cloned too, carrying their lineage id forward but never their sign-ups; on approval the sign-ups are migrated
 * from the outgoing live revision's lists onto these clones. The shared workflow wiring is defined in
 * {@see AbstractRevisionCloner}.
 */
final readonly class ActivityRevisionCloner extends AbstractRevisionCloner
{
    #[Override]
    public function supports(RevisionInterface $revision): bool
    {
        return $revision instanceof ActivityRevision;
    }

    #[Override]
    protected function spawnDraft(RevisionInterface $source): ActivityRevision
    {
        assert($source instanceof ActivityRevision);

        $activity = $source->activity;

        $draft = new ActivityRevision();
        $draft->setPreviousRevision($source);
        $activity->addRevision($draft);
        $activity->setCurrentRevision($draft);

        return $draft;
    }

    #[Override]
    protected function copyContent(
        RevisionInterface $source,
        AbstractRevision $draft,
    ): void {
        assert($source instanceof ActivityRevision);
        assert($draft instanceof ActivityRevision);

        $draft->name = $source->name->copy();
        $draft->location = $source->location->copy();
        $draft->costs = $source->costs->copy();
        $draft->description = $source->description->copy();
        $draft->beginTime = $this->copyDate($source->beginTime);
        $draft->endTime = $this->copyDate($source->endTime);
        $draft->category = $source->category;
        $draft->requireGEFLITST = $source->requireGEFLITST;
        $draft->requireZettle = $source->requireZettle;
        // Organ and company are reference entities, copied by reference; the labels (also references) are re-assigned
        // to the draft. Without this the draft would lose the organiser and labels of the source revision.
        $draft->organ = $source->organ;
        $draft->company = $source->company;
        $draft->addLabels($source->getLabels()->toArray());

        foreach ($source->getSignupLists() as $list) {
            $draft->addSignupList($this->copySignupList($list));
        }
    }

    private function copyDate(?DateTimeImmutable $source): ?DateTimeImmutable
    {
        return null !== $source
            ? clone $source
            : null;
    }

    /**
     * Deep-clone a sign-up list onto the new draft: a copy of the name, the same dates, flags and lineage id, and
     * deep-cloned fields/options. Sign-ups are deliberately not copied (they stay on the live revision until approval
     * migration).
     */
    private function copySignupList(SignupList $source): SignupList
    {
        $list = new SignupList();
        $list->name = $source->name->copy();
        $list->openDate = $source->openDate;
        $list->closeDate = $source->closeDate;
        $list->onlyGEWIS = $source->onlyGEWIS;
        $list->displaySubscribedNumber = $source->displaySubscribedNumber;
        $list->limitedCapacity = $source->limitedCapacity;
        $list->capacity = $source->capacity;
        // Snapshot the draw lock + its audit (and presence, below) at clone time so a fresh draft opens showing the
        // live state. This is only a starting point: the live list stays authoritative until approval, and
        // {@see SignupListMigrator::migrate()} re-syncs these onto the clone then in case a draw or presence sweep
        // happened while the draft was open.
        $list->drawnAt = $source->drawnAt;
        $list->drawnBy = $source->drawnBy;
        // Allocation method + its per-method settings are list config, carried forward like the other settings.
        $list->allocationMethod = $source->allocationMethod;
        $list->drawCutoffRule = $source->drawCutoffRule;
        $list->drawCutoffAt = $source->drawCutoffAt;
        $list->drawAfterDurationHours = $source->drawAfterDurationHours;
        $list->externalPolicyUrl = $source->externalPolicyUrl;
        $list->externalForceOrdering = $source->externalForceOrdering;
        $list->externalPaymentByExternal = $source->externalPaymentByExternal;
        $list->customMethodDescription = $source->customMethodDescription;
        $list->setMembershipTierOrder($source->getMembershipTierOrder());
        $list->membershipPriorityMode = $source->membershipPriorityMode;
        $list->setHeldMembershipPlaces($source->getHeldMembershipPlaces());
        $list->setCohortTierOrder($source->getCohortTierOrder());
        $list->setProgramTypeOrder($source->getProgramTypeOrder());
        $list->organisingCommitteePlaces = $source->organisingCommitteePlaces;
        $list->presenceTaken = $source->presenceTaken;
        $list->promoted = $source->promoted;
        // Carry the lineage forward so approval can migrate the live sign-ups onto this clone.
        $list->lineageId = $source->lineageId;

        foreach ($source->getFields() as $field) {
            $list->addField($this->copySignupField($field));
        }

        foreach ($source->getRoles() as $role) {
            $list->addRole($this->copySignupRole($role));
        }

        return $list;
    }

    private function copySignupRole(SignupRole $source): SignupRole
    {
        $role = new SignupRole();
        $role->name = $source->name;
        $role->minimum = $source->minimum;
        $role->position = $source->position;

        return $role;
    }

    private function copySignupField(SignupField $source): SignupField
    {
        $field = new SignupField();
        $field->name = $source->name->copy();
        $field->type = $source->type;
        $field->isSensitive = $source->isSensitive;
        $field->minimumValue = $source->minimumValue;
        $field->maximumValue = $source->maximumValue;
        // Carry the display order forward, otherwise a reordered list would revert to id order on the next draft.
        $field->position = $source->position;

        foreach ($source->getOptions() as $option) {
            $field->addOption($this->copySignupOption($option));
        }

        return $field;
    }

    private function copySignupOption(SignupOption $source): SignupOption
    {
        $option = new SignupOption();
        $option->value = $source->value->copy();
        // Carry the display order and the default marker forward, like the field's own position.
        $option->position = $source->position;
        $option->isDefault = $source->isDefault;

        return $option;
    }
}
