<?php

declare(strict_types=1);

namespace App\Service\Career;

use App\Entity\Application\AbstractRevision;
use App\Entity\Application\RevisionInterface;
use App\Entity\Career\VacancyRevision;
use App\Workflow\AbstractRevisionCloner;
use Override;

use function assert;

/**
 * Spawns the next Draft {@see VacancyRevision} from an existing one (for "changes requested", reopening, or editing
 * an approved vacancy). The localised texts are deep-copied into fresh rows so orphan-removal can never delete the
 * source revision's content; the contact details, category and posting window are copied by value. The shared wiring
 * (authorship, revision number, chain link) is defined in {@see AbstractRevisionCloner}.
 */
final readonly class VacancyRevisionCloner extends AbstractRevisionCloner
{
    #[Override]
    public function supports(RevisionInterface $revision): bool
    {
        return $revision instanceof VacancyRevision;
    }

    #[Override]
    protected function spawnDraft(RevisionInterface $source): VacancyRevision
    {
        assert($source instanceof VacancyRevision);

        $vacancy = $source->vacancy;

        $draft = new VacancyRevision();
        $draft->setPreviousRevision($source);
        $vacancy->addRevision($draft);
        $vacancy->setCurrentRevision($draft);

        return $draft;
    }

    #[Override]
    protected function copyContent(
        RevisionInterface $source,
        AbstractRevision $draft,
    ): void {
        assert($source instanceof VacancyRevision);
        assert($draft instanceof VacancyRevision);

        $draft->name = $source->name->copy();
        $draft->location = $source->location->copy();
        $draft->website = $source->website->copy();
        $draft->description = $source->description->copy();
        $draft->attachment = $source->attachment->copy();
        $draft->contactName = $source->contactName;
        $draft->contactPhone = $source->contactPhone;
        $draft->contactEmail = $source->contactEmail;
        $draft->category = $source->category;
        $draft->startDate = $source->startDate;
        $draft->endDate = $source->endDate;
        // Labels (reference entities) are carried over to the draft; without this, editing would blank them.
        $draft->addLabels($source->getLabels()->toArray());
    }
}
