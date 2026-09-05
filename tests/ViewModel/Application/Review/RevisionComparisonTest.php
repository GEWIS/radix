<?php

declare(strict_types=1);

namespace App\Tests\ViewModel\Application\Review;

use App\Entity\Activity\ActivityLocalisedText;
use App\ViewModel\Application\Review\RevisionAudience;
use App\ViewModel\Application\Review\RevisionComparison;
use App\ViewModel\Application\Review\RevisionDateRange;
use App\ViewModel\Application\Review\RevisionField;
use App\ViewModel\Application\Review\RevisionChangeKind;
use App\ViewModel\Application\Review\RevisionFieldKind;
use App\ViewModel\Application\Review\RevisionFieldValue;
use App\ViewModel\Application\Review\RevisionFlag;
use App\ViewModel\Application\Review\RevisionTag;
use App\ViewModel\Application\Review\RevisionSection;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * What the author of a revision is shown against what its reviewer is shown, and when a value counts as changed. Both
 * decide what a reader sees, and both were previously spread across four templates that had quietly drifted apart.
 */
use function array_values;

final class RevisionComparisonTest extends TestCase
{
    public function testAnAuthorIsNotShownAReviewerOnlySection(): void
    {
        $comparison = $this->comparison();

        self::assertSame(
            [
                'Everybody',
                'Mixed',
            ],
            $this->headings($comparison->sectionsFor(RevisionAudience::Everyone)),
        );
        self::assertSame(
            [
                'Everybody',
                'Reviewers',
                'Mixed',
            ],
            $this->headings($comparison->sectionsFor(RevisionAudience::ReviewerOnly)),
        );
    }

    public function testAnAuthorIsNotShownAReviewerOnlyFieldOfASectionTheyDoSee(): void
    {
        $comparison = $this->comparison();

        $mixed = $comparison->sectionsFor(RevisionAudience::Everyone)[1];

        self::assertCount(
            1,
            $mixed->fields,
        );

        $label = $mixed->fields[0]->label;
        self::assertInstanceOf(
            TranslatableMessage::class,
            $label,
        );
        self::assertSame(
            'Shared',
            $label->getMessage(),
        );
    }

    public function testASectionLeftWithNoVisibleFieldsIsDroppedEntirely(): void
    {
        $comparison = $this->comparison();

        self::assertNotContains(
            'Reviewers',
            $this->headings($comparison->sectionsFor(RevisionAudience::Everyone)),
        );
    }

    public function testATranslatableValueIsComparedByWhatItSaysRatherThanByIdentity(): void
    {
        $unchanged = new RevisionFieldValue(
            new TranslatableMessage('Internship'),
            new TranslatableMessage('Internship'),
        );
        $changed = new RevisionFieldValue(
            new TranslatableMessage('Internship'),
            new TranslatableMessage('Job'),
        );

        self::assertFalse($unchanged->isChanged());
        self::assertTrue($changed->isChanged());
    }

    public function testADateRangeIsComparedByTheMomentsItHoldsRatherThanByIdentity(): void
    {
        $unchanged = new RevisionFieldValue(
            new RevisionDateRange(new DateTimeImmutable('2026-01-01 09:00:00')),
            new RevisionDateRange(new DateTimeImmutable('2026-01-01 09:00:00')),
        );
        $changed = new RevisionFieldValue(
            new RevisionDateRange(new DateTimeImmutable('2026-01-01 09:00:00')),
            new RevisionDateRange(
                new DateTimeImmutable('2026-01-01 09:00:00'),
                new DateTimeImmutable('2026-02-01 09:00:00'),
            ),
        );

        self::assertFalse($unchanged->isChanged());
        self::assertTrue($changed->isChanged());
    }

    public function testASetOfSwitchesNobodyTouchedHasNotChanged(): void
    {
        $unchanged = $this->flagField(
            new RevisionFlag(
                new TranslatableMessage('GEFLITST'),
                false,
                false,
            ),
            new RevisionFlag(
                new TranslatableMessage('Zettle'),
                true,
                true,
            ),
        );
        $changed = $this->flagField(
            new RevisionFlag(
                new TranslatableMessage('GEFLITST'),
                false,
                true,
            ),
        );

        self::assertSame(
            RevisionChangeKind::Same,
            $unchanged->changeKind(),
        );
        self::assertSame(
            RevisionChangeKind::Changed,
            $changed->changeKind(),
        );
    }

    public function testLabelsAreComparedByWhichOnesTheyAreRatherThanByTheirOrder(): void
    {
        $reordered = new RevisionFieldValue(
            [
                $this->tag(1),
                $this->tag(2),
            ],
            [
                $this->tag(2),
                $this->tag(1),
            ],
        );
        $swapped = new RevisionFieldValue(
            [$this->tag(1)],
            [$this->tag(3)],
        );

        self::assertFalse($reordered->isChanged());
        self::assertTrue($swapped->isChanged());
    }

    private function tag(int $id): RevisionTag
    {
        return new RevisionTag(
            $id,
            new ActivityLocalisedText(
                'Label',
                'Label',
            ),
        );
    }

    private function flagField(RevisionFlag ...$flags): RevisionField
    {
        return new RevisionField(
            new TranslatableMessage('Facilities'),
            RevisionFieldKind::Flag,
            [
                new RevisionFieldValue(
                    null,
                    array_values($flags),
                ),
            ],
            true,
        );
    }

    /**
     * A field is only comparable once there is an earlier revision to compare it against, and the renderer shows a
     * change only for a field that is.
     */
    public function testAFirstRevisionNeverReadsAsHavingChangedAnything(): void
    {
        $field = new RevisionField(
            new TranslatableMessage('Slogan'),
            RevisionFieldKind::Text,
            [
                new RevisionFieldValue(
                    null,
                    'We hire',
                ),
            ],
        );

        self::assertFalse($field->comparable);
    }

    /**
     * Three sections covering the cases the filter has to get right: one everybody sees, one only a reviewer sees, and
     * one everybody sees that holds a field only a reviewer sees.
     */
    private function comparison(): RevisionComparison
    {
        return new RevisionComparison([
            new RevisionSection(
                'everybody',
                new TranslatableMessage('Everybody'),
                [$this->textField('Slogan')],
            ),
            new RevisionSection(
                'reviewers',
                new TranslatableMessage('Reviewers'),
                [$this->textField('Internal note')],
                RevisionAudience::ReviewerOnly,
            ),
            new RevisionSection(
                'mixed',
                new TranslatableMessage('Mixed'),
                [
                    $this->textField('Shared'),
                    $this->textField(
                        'Private',
                        RevisionAudience::ReviewerOnly,
                    ),
                ],
            ),
        ]);
    }

    private function textField(
        string $label,
        RevisionAudience $audience = RevisionAudience::Everyone,
    ): RevisionField {
        return new RevisionField(
            new TranslatableMessage($label),
            RevisionFieldKind::Text,
            [
                new RevisionFieldValue(
                    null,
                    'something',
                ),
            ],
            audience: $audience,
        );
    }

    /**
     * @param list<RevisionSection> $sections
     *
     * @return list<string>
     */
    private function headings(array $sections): array
    {
        $headings = [];

        foreach ($sections as $section) {
            $heading = $section->heading;
            self::assertInstanceOf(
                TranslatableMessage::class,
                $heading,
            );
            $headings[] = $heading->getMessage();
        }

        return $headings;
    }
}
