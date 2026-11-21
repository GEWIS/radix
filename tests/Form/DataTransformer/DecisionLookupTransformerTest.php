<?php

declare(strict_types=1);

namespace App\Tests\Form\DataTransformer;

use App\Entity\Database\Enums\MeetingTypes;
use App\Form\DataTransformer\DecisionLookupTransformer;
use App\Repository\Database\MeetingRepository;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\LedgerBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Form\Exception\TransformationFailedException;

/**
 * A decision form asks for the four parts of a decision's identity rather than for the decision itself, so the four
 * parts have to be written out from one and read back into the one they name, and refused when they name nothing.
 */
#[CoversClass(DecisionLookupTransformer::class)]
final class DecisionLookupTransformerTest extends DatabaseTestCase
{
    private DecisionLookupTransformer $transformer;

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
        $this->transformer = new DecisionLookupTransformer(
            self::getContainer()->get(MeetingRepository::class),
        );
        $this->build = new LedgerBuilder($this->entityManager);
    }

    public function testNothingIsWrittenOutForNoDecision(): void
    {
        self::assertSame(
            [
                'meeting_type' => null,
                'meeting_number' => null,
                'point' => null,
                'number' => null,
            ],
            $this->transformer->transform(null),
        );
    }

    public function testTheFourPartsOfTheIdentityAreWrittenOut(): void
    {
        $meeting = $this->build->meeting(
            MeetingTypes::BV,
            '2034-01-01',
        );
        $decision = $this->build->decideFreely(
            $meeting,
            'Een besluit',
        )->decision;

        self::assertSame(
            [
                'meeting_type' => $meeting->type,
                'meeting_number' => (string) $meeting->getNumber(),
                'point' => (string) $decision->point,
                'number' => (string) $decision->number,
            ],
            $this->transformer->transform($decision),
        );
    }

    public function testWhatWasWrittenOutIsReadBackAsTheSameDecision(): void
    {
        $meeting = $this->build->meeting();
        $decision = $this->build->decideFreely(
            $meeting,
            'Een besluit',
        )->decision;

        self::assertSame(
            $decision,
            $this->transformer->reverseTransform($this->transformer->transform($decision)),
        );
    }

    public function testASubmissionThatIsNotAnIdentityIsRefused(): void
    {
        $this->expectException(TransformationFailedException::class);

        $this->transformer->reverseTransform(self::submitted('bv 1.1.1'));
    }

    public function testAnIncompleteIdentityIsRefused(): void
    {
        $this->expectException(TransformationFailedException::class);

        $this->transformer->reverseTransform(self::submitted([
            'meeting_type' => MeetingTypes::BV,
            'meeting_number' => '1',
            'point' => '1',
        ]));
    }

    public function testAnEmptyPartIsRefused(): void
    {
        $this->expectException(TransformationFailedException::class);

        $this->transformer->reverseTransform([
            'meeting_type' => MeetingTypes::BV,
            'meeting_number' => '1',
            'point' => '',
            'number' => '1',
        ]);
    }

    public function testAnIdentityNamingNoDecisionIsRefused(): void
    {
        $this->expectException(TransformationFailedException::class);
        $this->expectExceptionMessageIsOrContains('There is no decision');

        $this->transformer->reverseTransform([
            'meeting_type' => MeetingTypes::BV,
            'meeting_number' => '999999',
            'point' => '1',
            'number' => '1',
        ]);
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
