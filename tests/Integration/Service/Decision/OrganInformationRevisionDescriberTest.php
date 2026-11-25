<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Decision;

use App\Entity\Application\Enums\Languages;
use App\Entity\Application\RevisionInterface;
use App\Entity\Decision\Organ;
use App\Entity\Decision\OrganInformationRevision;
use App\Service\Decision\OrganInformationRevisionDescriber;
use App\Tests\Integration\DatabaseTestCase;
use App\ViewModel\Application\Review\RevisionComparison;
use App\ViewModel\Application\Review\RevisionField;
use App\ViewModel\Application\Review\RevisionFieldKind;
use App\ViewModel\Application\Review\RevisionSection;
use App\ViewModel\Application\Review\RevisionTag;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

use function array_map;

/**
 * What the board sees when a body submits its page: the text, link, social links, and both images beside the ones
 * they replace. An unchanged draft must not show as a change.
 */
final class OrganInformationRevisionDescriberTest extends DatabaseTestCase
{
    public function testTheSectionsFollowThePageContactSocialAndImagesOrder(): void
    {
        $revision = $this->submittedRevision('KEUR');

        self::assertSame(
            [
                'Page',
                'Contact details',
                'Social media',
                'Images',
            ],
            $this->headings($this->describe($revision)),
        );
    }

    public function testAChangedDraftDescribesTheTextAndTheSocialLinks(): void
    {
        $revision = $this->submittedRevision('KEUR');
        $comparison = $this->describe($revision);

        $page = $this->section(
            $comparison,
            'Page',
        );
        self::assertTrue($page->hasChanges());
        self::assertSame(
            RevisionFieldKind::Tags,
            $this->field(
                $page,
                'Labels',
            )->kind,
        );

        $social = $this->section(
            $comparison,
            'Social media',
        );
        self::assertTrue($social->hasChanges());
        self::assertSame(
            [
                'Instagram',
                'Discord',
            ],
            array_map(
                fn (RevisionField $field): string => $this->labelOf($field->label),
                $social->fields,
            ),
        );
    }

    public function testTheImagesSectionDescribesBothImagesWithTheirVariants(): void
    {
        $section = $this->section(
            $this->describe($this->submittedRevision('KEUR')),
            'Images',
        );

        self::assertSame(
            [
                RevisionFieldKind::Image,
                RevisionFieldKind::Image,
            ],
            array_map(
                static fn (RevisionField $field): RevisionFieldKind => $field->kind,
                $section->fields,
            ),
        );
        self::assertSame(
            [
                'w320',
                'w640',
            ],
            array_map(
                static fn (RevisionField $field): ?string => $field->option('variant'),
                $section->fields,
            ),
        );
        self::assertTrue($section->hasChanges());
    }

    public function testTheLabelsOfARevisionAreResolvedByName(): void
    {
        $revision = $this->liveRevision('BAC');
        $field = $this->field(
            $this->section(
                $this->describe($revision),
                'Page',
            ),
            'Labels',
        );

        self::assertSame(
            RevisionFieldKind::Tags,
            $field->kind,
        );

        $tags = $field->value()->new;
        self::assertIsArray($tags);
        self::assertCount(
            1,
            $tags,
        );
        self::assertInstanceOf(
            RevisionTag::class,
            $tags[0],
        );
        self::assertSame(
            'Fun',
            $tags[0]->label->getText(Languages::English),
        );
    }

    /**
     * A draft cloned from the live revision has changed nothing yet, so every section must read as unchanged.
     */
    public function testAnUnchangedDraftReportsNoChanges(): void
    {
        $revision = $this->currentRevision('GETÉST');
        self::assertNotNull($revision->getPreviousRevision());

        $comparison = $this->describe($revision);
        self::assertCount(
            4,
            $comparison->sections,
        );

        foreach ($comparison->sections as $section) {
            self::assertFalse(
                $section->hasChanges(),
                $this->labelOf($section->heading),
            );
        }
    }

    public function testAnEmptyRevisionHasNothingToShow(): void
    {
        $comparison = $this->describer()->describe(
            new OrganInformationRevision(),
            null,
        );

        self::assertCount(
            4,
            $comparison->sections,
        );

        foreach ($comparison->sections as $section) {
            self::assertFalse($section->hasChanges());
        }

        $images = $this->section(
            $comparison,
            'Images',
        );

        self::assertSame(
            [
                'No logo.',
                'No banner.',
            ],
            array_map(
                static fn (RevisionField $field): string => $field->emptyLabel instanceof TranslatableMessage
                    ? $field->emptyLabel->getMessage()
                    : '',
                $images->fields,
            ),
        );
    }

    public function testARevisionOfAnotherDomainDescribesNothing(): void
    {
        $revision = self::createStub(RevisionInterface::class);

        self::assertFalse($this->describer()->supports($revision));
        self::assertCount(
            0,
            $this->describer()->describe(
                $revision,
                null,
            )->sections,
        );
    }

    private function describe(OrganInformationRevision $revision): RevisionComparison
    {
        return $this->describer()->describe(
            $revision,
            $revision->getPreviousRevision(),
        );
    }

    private function section(
        RevisionComparison $comparison,
        string $heading,
    ): RevisionSection {
        foreach ($comparison->sections as $section) {
            if ($heading === $this->labelOf($section->heading)) {
                return $section;
            }
        }

        self::fail('No section named ' . $heading . '.');
    }

    private function field(
        RevisionSection $section,
        string $label,
    ): RevisionField {
        foreach ($section->fields as $field) {
            if ($label === $this->labelOf($field->label)) {
                return $field;
            }
        }

        self::fail('No field named ' . $label . '.');
    }

    /**
     * @return list<string>
     */
    private function headings(RevisionComparison $comparison): array
    {
        return array_map(
            fn (RevisionSection $section): string => $this->labelOf($section->heading),
            $comparison->sections,
        );
    }

    private function labelOf(TranslatableInterface $label): string
    {
        self::assertInstanceOf(
            TranslatableMessage::class,
            $label,
        );

        return $label->getMessage();
    }

    private function submittedRevision(string $abbr): OrganInformationRevision
    {
        $revision = $this->currentRevision($abbr);
        self::assertNotNull($revision->getPreviousRevision());

        return $revision;
    }

    private function currentRevision(string $abbr): OrganInformationRevision
    {
        $revision = $this->organ($abbr)->organInformation?->getCurrentRevision();
        self::assertInstanceOf(
            OrganInformationRevision::class,
            $revision,
        );

        return $revision;
    }

    private function liveRevision(string $abbr): OrganInformationRevision
    {
        $revision = $this->organ($abbr)->organInformation?->getLiveRevision();
        self::assertInstanceOf(
            OrganInformationRevision::class,
            $revision,
        );

        return $revision;
    }

    private function organ(string $abbr): Organ
    {
        $organ = $this->entityManager->getRepository(Organ::class)->findOneBy([
            'abbr' => $abbr,
            'abrogationDate' => null,
        ]);
        self::assertInstanceOf(
            Organ::class,
            $organ,
        );

        return $organ;
    }

    private function describer(): OrganInformationRevisionDescriber
    {
        return new OrganInformationRevisionDescriber();
    }
}
