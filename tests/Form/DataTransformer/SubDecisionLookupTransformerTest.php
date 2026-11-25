<?php

declare(strict_types=1);

namespace App\Tests\Form\DataTransformer;

use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Database\SubDecision;
use App\Entity\Database\SubDecision\Foundation;
use App\Entity\Database\SubDecision\Other;
use App\Form\DataTransformer\SubDecisionLookupTransformer;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\LedgerBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Form\Exception\TransformationFailedException;

/**
 * A sub-decision form asks for the five parts of a sub-decision's identity: the meeting, the decision within it,
 * and the sequence within that decision. The class it was built for narrows the answer, so a grant of a key code
 * and the foundation of a body at the same coordinates are never mistaken for one another.
 */
#[CoversClass(SubDecisionLookupTransformer::class)]
final class SubDecisionLookupTransformerTest extends DatabaseTestCase
{
    private LedgerBuilder $build;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $entityManager = self::getContainer()->get('doctrine')->getManager('database');
        self::assertInstanceOf(
            EntityManagerInterface::class,
            $entityManager,
        );

        $this->entityManager = $entityManager;
        $this->build = new LedgerBuilder($this->entityManager);
    }

    public function testNothingIsWrittenOutForNoSubDecision(): void
    {
        self::assertSame(
            [
                'meeting_type' => null,
                'meeting_number' => null,
                'decision_point' => null,
                'decision_number' => null,
                'sequence' => null,
            ],
            $this->transformer(Other::class)->transform(null),
        );
    }

    public function testTheFivePartsOfTheIdentityAreWrittenOut(): void
    {
        $meeting = $this->build->meeting(
            MeetingTypes::BV,
            '2034-02-01',
        );
        $other = $this->build->decideFreely(
            $meeting,
            'Een besluit',
        );

        self::assertSame(
            [
                'meeting_type' => $meeting->type,
                'meeting_number' => (string) $meeting->getNumber(),
                'decision_point' => (string) $other->decision->point,
                'decision_number' => (string) $other->decision->number,
                'sequence' => (string) $other->sequence,
            ],
            $this->transformer(Other::class)->transform($other),
        );
    }

    public function testWhatWasWrittenOutIsReadBackAsTheSameSubDecision(): void
    {
        $meeting = $this->build->meeting();
        $other = $this->build->decideFreely(
            $meeting,
            'Een besluit',
        );
        $transformer = $this->transformer(Other::class);

        self::assertSame(
            $other,
            $transformer->reverseTransform($transformer->transform($other)),
        );
    }

    public function testSubmittingSomethingThatIsNotAnIdentityIsRefused(): void
    {
        $this->expectException(TransformationFailedException::class);

        $this->transformer(Other::class)->reverseTransform(self::submitted('bv 1.1.1.1'));
    }

    public function testAnIncompleteIdentityIsRefused(): void
    {
        $this->expectException(TransformationFailedException::class);

        $this->transformer(Other::class)->reverseTransform(self::submitted([
            'meeting_type' => MeetingTypes::BV,
            'meeting_number' => '1',
            'decision_point' => '1',
            'decision_number' => '1',
        ]));
    }

    public function testAnEmptyPartIsRefused(): void
    {
        $this->expectException(TransformationFailedException::class);

        $this->transformer(Other::class)->reverseTransform([
            'meeting_type' => MeetingTypes::BV,
            'meeting_number' => '1',
            'decision_point' => '1',
            'decision_number' => '1',
            'sequence' => '',
        ]);
    }

    public function testAnIdentityNamingNothingIsRefused(): void
    {
        $this->expectException(TransformationFailedException::class);

        $meeting = $this->build->meeting();
        $other = $this->build->decideFreely(
            $meeting,
            'Een besluit',
        );

        $this->transformer(Other::class)->reverseTransform([
            'meeting_type' => $meeting->type,
            'meeting_number' => (string) $meeting->getNumber(),
            'decision_point' => (string) $other->decision->point,
            'decision_number' => (string) $other->decision->number,
            'sequence' => '99',
        ]);
    }

    /**
     * The sub-decision the same coordinates name in another class is a different record with different meaning, and the
     * transformer was built for one of them.
     */
    public function testTheClassItWasBuiltForNarrowsTheAnswer(): void
    {
        $meeting = $this->build->meeting();
        $other = $this->build->decideFreely(
            $meeting,
            'Een besluit',
        );

        $this->expectException(TransformationFailedException::class);

        $this->transformer(Foundation::class)->reverseTransform([
            'meeting_type' => $meeting->type,
            'meeting_number' => (string) $meeting->getNumber(),
            'decision_point' => (string) $other->decision->point,
            'decision_number' => (string) $other->decision->number,
            'sequence' => (string) $other->sequence,
        ]);
    }

    /**
     * @template T of SubDecision
     *
     * @param class-string<T> $class
     *
     * @return SubDecisionLookupTransformer<T>
     */
    private function transformer(string $class): SubDecisionLookupTransformer
    {
        return new SubDecisionLookupTransformer(
            $this->entityManager,
            $class,
        );
    }

    /**
     * Form submissions are not guaranteed to carry the shape the transformer's contract promises, which is exactly
     * the case the transformer refuses; this keeps such a submission expressible.
     */
    private static function submitted(mixed $value): mixed
    {
        return $value;
    }
}
