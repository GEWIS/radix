<?php

declare(strict_types=1);

namespace App\Tests\Entity\Database;

use App\Entity\Database\AuditEntry;
use App\Entity\Database\Member;
use DateTimeImmutable;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

#[CoversClass(AuditEntry::class)]
final class AuditEntryTest extends TestCase
{
    /**
     * An entry belongs to its member permanently: relinking it would rewrite whose record it is.
     */
    public function testAnEntryCannotBeRelinkedToAnotherMember(): void
    {
        $entry = $this->entry();
        $member = $this->member(8100);

        $entry->setMember($member);
        $entry->setMember($member);

        self::assertSame(
            $member,
            $entry->member,
        );

        $this->expectException(LogicException::class);
        $entry->setMember($this->member(8101));
    }

    /**
     * An entry with no member is not about anything, which is a bug rather than a valid state.
     */
    public function testAssertValidRequiresAMember(): void
    {
        $this->expectException(UnexpectedValueException::class);

        $this->entry()->assertValid();
    }

    public function testAssertValidAcceptsALinkedEntry(): void
    {
        $entry = $this->entry();
        $entry->setMember($this->member(8100));

        $entry->assertValid();

        self::assertSame(
            8100,
            $entry->member?->lidnr,
        );
        self::assertNull($entry->getUserLidnr());
    }

    /**
     * The plain body is what a search matches, so markup from the format string is stripped; the arguments stay
     * separate for the template to escape.
     */
    public function testThePlainBodyHasTheMarkupRemoved(): void
    {
        $entry = $this->entry(
            '<strong>%s</strong> noted: %s',
            [
                'Jan',
                'something',
            ],
        );

        self::assertSame(
            [
                'bodyPlain' => '%s noted: %s',
                'bodyFormatted' => '<strong>%s</strong> noted: %s',
                'arguments' => [
                    'Jan',
                    'something',
                ],
            ],
            $entry->getStringPlain(),
        );
    }

    /**
     * Audit entries cannot be edited or removed; a type that is an annotation may opt into being removable.
     */
    public function testEntriesAreImmutableUnlessTheirTypeSaysOtherwise(): void
    {
        self::assertTrue(false === $this->entry()->isEditable());
        self::assertFalse($this->entry()->isDeletable());
        self::assertTrue($this->removableEntry()->isDeletable());
    }

    public function testAnEntryCanBeGivenAnIdentifierAndAnAuthor(): void
    {
        $entry = $this->entry();
        $entry->setId(42);

        $author = $this->member(8026);
        $entry->user = $author;

        self::assertSame(
            42,
            $entry->getId(),
        );
        self::assertSame(
            8026,
            $entry->getUserLidnr(),
        );
    }

    public function testAnEntryWithoutAnAuthorHasNoAuthorNumber(): void
    {
        self::assertNull($this->entry()->getUserLidnr());
    }

    /**
     * @param string[] $arguments
     */
    private function entry(
        string $formatted = 'note',
        array $arguments = [],
    ): AuditEntry {
        return new class ($formatted, $arguments) extends AuditEntry {
            /**
             * @param string[] $arguments
             */
            public function __construct(
                private readonly string $formatted,
                private readonly array $arguments,
            ) {
                $this->updatedAt = new DateTimeImmutable('2026-01-01');
            }

            #[Override]
            protected function getStringBodyFormatted(): string
            {
                return $this->formatted;
            }

            /**
             * @return string[]
             */
            #[Override]
            protected function getStringArguments(): array
            {
                return $this->arguments;
            }
        };
    }

    private function removableEntry(): AuditEntry
    {
        return new class extends AuditEntry {
            protected const bool IMMUTABLE = false;

            #[Override]
            protected function getStringBodyFormatted(): string
            {
                return 'note';
            }

            /**
             * @return string[]
             */
            #[Override]
            protected function getStringArguments(): array
            {
                return [];
            }
        };
    }

    private function member(int $lidnr): Member
    {
        $member = new Member();
        $member->lidnr = $lidnr;
        $member->initials = 'T.';
        $member->firstName = 'Test';
        $member->middleName = '';
        $member->lastName = 'Lid';
        $member->changedOn = new DateTimeImmutable('2026-01-01');

        return $member;
    }
}
