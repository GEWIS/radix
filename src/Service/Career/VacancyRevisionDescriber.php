<?php

declare(strict_types=1);

namespace App\Service\Career;

use App\Entity\Application\RevisionInterface;
use App\Entity\Career\VacancyRevision;
use App\Service\Application\AbstractRevisionDescriber;
use App\ViewModel\Application\Review\RevisionAudience;
use App\ViewModel\Application\Review\RevisionDateRange;
use App\ViewModel\Application\Review\RevisionFieldKind;
use App\ViewModel\Application\Review\RevisionSection;
use App\ViewModel\Application\Review\RevisionTag;
use Override;

use function assert;
use function Symfony\Component\Translation\t;

/**
 * What a vacancy offers, when it runs and who to talk to about it. Which company it belongs to is shown to reviewers
 * only: a representative is already inside their own company when they read this.
 */
final class VacancyRevisionDescriber extends AbstractRevisionDescriber
{
    #[Override]
    protected function revisionClass(): string
    {
        return VacancyRevision::class;
    }

    /**
     * @inheritDoc
     */
    #[Override]
    protected function sections(
        RevisionInterface $revision,
        ?RevisionInterface $previous,
        bool $comparable,
    ): array {
        assert($revision instanceof VacancyRevision);
        assert(null === $previous || $previous instanceof VacancyRevision);

        return [
            new RevisionSection(
                'general',
                t('General information'),
                [
                    $this->field(
                        t('Company'),
                        RevisionFieldKind::Reference,
                        $previous?->vacancy->getCompany()->name,
                        $revision->vacancy->getCompany()->name,
                        $comparable,
                        ['width' => 'third'],
                        RevisionAudience::ReviewerOnly,
                    ),
                    $this->field(
                        t('Category'),
                        RevisionFieldKind::Badge,
                        $previous?->category->label(),
                        $revision->category->label(),
                        $comparable,
                        [
                            'width' => 'third',
                            'badgeClass' => $revision->category->badgeClass(),
                        ],
                    ),
                    $this->field(
                        t('Posting window'),
                        RevisionFieldKind::DateRange,
                        null === $previous ? null : new RevisionDateRange(
                            $previous->startDate,
                            $previous->endDate,
                        ),
                        new RevisionDateRange(
                            $revision->startDate,
                            $revision->endDate,
                        ),
                        $comparable,
                        ['width' => 'third'],
                    ),
                    $this->field(
                        t('Labels'),
                        RevisionFieldKind::Tags,
                        $this->labels($previous),
                        $this->labels($revision),
                        $comparable,
                        emptyLabel: t('No labels.'),
                    ),
                ],
            ),
            new RevisionSection(
                'details',
                t('Details'),
                [
                    $this->localisedField(
                        t('Title'),
                        $previous?->name,
                        $revision->name,
                        $comparable,
                    ),
                    $this->localisedField(
                        t('Location'),
                        $previous?->location,
                        $revision->location,
                        $comparable,
                    ),
                    $this->localisedField(
                        t('Website'),
                        $previous?->website,
                        $revision->website,
                        $comparable,
                    ),
                    $this->localisedField(
                        t('Attachment link'),
                        $previous?->attachment,
                        $revision->attachment,
                        $comparable,
                    ),
                    $this->localisedField(
                        t('Description'),
                        $previous?->description,
                        $revision->description,
                        $comparable,
                        RevisionFieldKind::LongText,
                    ),
                ],
            ),
            new RevisionSection(
                'contact',
                t('Contact details'),
                [
                    $this->field(
                        t('Contact name'),
                        RevisionFieldKind::Text,
                        $previous?->contactName,
                        $revision->contactName,
                        $comparable,
                    ),
                    $this->field(
                        t('Contact email address'),
                        RevisionFieldKind::Text,
                        $previous?->contactEmail,
                        $revision->contactEmail,
                        $comparable,
                    ),
                    $this->field(
                        t('Contact phone number'),
                        RevisionFieldKind::Text,
                        $previous?->contactPhone,
                        $revision->contactPhone,
                        $comparable,
                    ),
                ],
            ),
        ];
    }

    /**
     * @return list<RevisionTag>
     */
    private function labels(?VacancyRevision $revision): array
    {
        $tags = [];

        foreach ($revision?->getLabels() ?? [] as $label) {
            $tags[] = new RevisionTag(
                $label->id,
                $label->name,
            );
        }

        return $tags;
    }
}
