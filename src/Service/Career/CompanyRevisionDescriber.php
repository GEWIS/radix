<?php

declare(strict_types=1);

namespace App\Service\Career;

use App\Entity\Application\RevisionInterface;
use App\Entity\Career\CompanyRevision;
use App\Service\Application\AbstractRevisionDescriber;
use App\ViewModel\Application\Review\RevisionFieldKind;
use App\ViewModel\Application\Review\RevisionSection;
use Override;

use function assert;
use function Symfony\Component\Translation\t;

/**
 * What a company says about itself: the profile it publishes, how to reach it and the logo it is shown by.
 */
final class CompanyRevisionDescriber extends AbstractRevisionDescriber
{
    #[Override]
    protected function revisionClass(): string
    {
        return CompanyRevision::class;
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
        assert($revision instanceof CompanyRevision);
        assert(null === $previous || $previous instanceof CompanyRevision);

        return [
            new RevisionSection(
                'profile',
                t('Profile'),
                [
                    $this->localisedField(
                        t('Slogan'),
                        $previous?->slogan,
                        $revision->slogan,
                        $comparable,
                    ),
                    $this->localisedField(
                        t('Website'),
                        $previous?->website,
                        $revision->website,
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
                    $this->field(
                        t('Address'),
                        RevisionFieldKind::Text,
                        $previous?->contactAddress,
                        $revision->contactAddress,
                        $comparable,
                    ),
                ],
            ),
            new RevisionSection(
                'social',
                t('Social media'),
                $this->socialFields(
                    $previous?->getSocialLinks(),
                    $revision->getSocialLinks(),
                    $comparable,
                ),
            ),
            new RevisionSection(
                'logos',
                t('Logos'),
                [
                    $this->field(
                        t('Square logo'),
                        RevisionFieldKind::Image,
                        $previous?->squareLogo,
                        $revision->squareLogo,
                        $comparable,
                        [
                            'variant' => 'w320',
                            'class' => 'career-logo-lg',
                        ],
                        emptyLabel: t('No logo.'),
                    ),
                    $this->field(
                        t('Banner logo'),
                        RevisionFieldKind::Image,
                        $previous?->bannerLogo,
                        $revision->bannerLogo,
                        $comparable,
                        [
                            'variant' => 'w640',
                            'class' => 'career-logo-plate',
                        ],
                        emptyLabel: t('No logo.'),
                    ),
                ],
            ),
        ];
    }
}
