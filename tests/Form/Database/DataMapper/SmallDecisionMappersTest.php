<?php

declare(strict_types=1);

namespace App\Tests\Form\Database\DataMapper;

use App\Entity\Database\Decision;
use App\Entity\Database\Enums\BoardFunctions;
use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Database\SubDecision;
use App\Entity\Database\SubDecision\Annulment;
use App\Entity\Database\SubDecision\Board\Candidacy;
use App\Entity\Database\SubDecision\Board\Candidate;
use App\Entity\Database\SubDecision\Board\Discharge as BoardDischarge;
use App\Entity\Database\SubDecision\Board\Installation as BoardInstallation;
use App\Entity\Database\SubDecision\Board\Release as BoardRelease;
use App\Entity\Database\SubDecision\Continuation;
use App\Entity\Database\SubDecision\Foundation;
use App\Entity\Database\SubDecision\Key\Granting;
use App\Entity\Database\SubDecision\Key\Withdrawal;
use App\Entity\Database\SubDecision\Member\Suspension;
use App\Entity\Database\SubDecision\Member\Warning;
use App\Entity\Database\SubDecision\Minutes;
use App\Entity\Database\SubDecision\Other;
use App\Form\Database\DataMapper\AnnulmentMapper;
use App\Form\Database\DataMapper\Board\CandidacyMapper;
use App\Form\Database\DataMapper\Board\DischargeMapper as BoardDischargeMapper;
use App\Form\Database\DataMapper\Board\InstallMapper as BoardInstallMapper;
use App\Form\Database\DataMapper\Board\ReleaseMapper as BoardReleaseMapper;
use App\Form\Database\DataMapper\ContinuationMapper;
use App\Form\Database\DataMapper\Key\GrantMapper;
use App\Form\Database\DataMapper\Key\WithdrawMapper;
use App\Form\Database\DataMapper\Member\SuspensionMapper;
use App\Form\Database\DataMapper\Member\WarningMapper;
use App\Form\Database\DataMapper\MinutesMapper;
use App\Form\Database\DataMapper\OtherMapper;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function array_map;
use function json_encode;

#[CoversClass(AnnulmentMapper::class)]
#[CoversClass(CandidacyMapper::class)]
#[CoversClass(BoardDischargeMapper::class)]
#[CoversClass(BoardInstallMapper::class)]
#[CoversClass(BoardReleaseMapper::class)]
#[CoversClass(ContinuationMapper::class)]
#[CoversClass(GrantMapper::class)]
#[CoversClass(WithdrawMapper::class)]
#[CoversClass(SuspensionMapper::class)]
#[CoversClass(WarningMapper::class)]
#[CoversClass(MinutesMapper::class)]
#[CoversClass(OtherMapper::class)]
final class SmallDecisionMappersTest extends TestCase
{
    use MapsDecisions;

    /**
     * An annulment takes sequence 0, so it reads before whatever it takes back.
     */
    public function testAnnulmentTakesTheDecisionItTargets(): void
    {
        $target = $this->decision(
            $this->meeting(
                MeetingTypes::ALV,
                12,
            ),
            4,
            2,
        );

        $decision = $this->mapDecision(
            new AnnulmentMapper(),
            ['fdecision' => $target],
        );

        $subDecision = $this->only($decision);
        self::assertInstanceOf(
            Annulment::class,
            $subDecision,
        );
        self::assertSame(
            0,
            $subDecision->sequence,
        );
        self::assertSame(
            $target,
            $subDecision->target,
        );
    }

    public function testAnnulmentWithoutATargetBuildsNothing(): void
    {
        $decision = $this->mapDecision(
            new AnnulmentMapper(),
            ['fdecision' => null],
        );

        self::assertCount(
            0,
            $decision->getSubdecisions(),
        );
    }

    /**
     * Continuing a body keeps the foundation it has had since it was established.
     */
    public function testContinuationReferencesTheExistingFoundation(): void
    {
        $foundation = $this->foundation();

        $decision = $this->mapDecision(
            new ContinuationMapper(),
            ['subdecision' => $foundation],
        );

        $subDecision = $this->only($decision);
        self::assertInstanceOf(
            Continuation::class,
            $subDecision,
        );
        self::assertSame(
            1,
            $subDecision->sequence,
        );
        self::assertSame(
            $foundation,
            $subDecision->foundation,
        );
    }

    public function testAMembershipChangeWithoutAnOrganBuildsNothing(): void
    {
        $decision = $this->mapDecision(
            new ContinuationMapper(),
            ['subdecision' => null],
        );

        self::assertCount(
            0,
            $decision->getSubdecisions(),
        );
    }

    /**
     * The board installation records who takes which function from what date, and nothing else: the board year follows
     * from the date, so it is not stored twice.
     */
    public function testBoardInstallationRecordsTheFunctionAndDate(): void
    {
        $member = $this->member(8500);
        $date = new DateTimeImmutable('2026-09-01');

        $decision = $this->mapDecision(
            new BoardInstallMapper(),
            [
                'member' => $member,
                'function' => BoardFunctions::Chair,
                'date' => $date,
            ],
        );

        $subDecision = $this->only($decision);
        self::assertInstanceOf(
            BoardInstallation::class,
            $subDecision,
        );
        self::assertSame(
            1,
            $subDecision->sequence,
        );
        self::assertSame(
            $member,
            $subDecision->getMember(),
        );
        self::assertSame(
            BoardFunctions::Chair,
            $subDecision->function,
        );
        self::assertSame(
            $date,
            $subDecision->date,
        );
    }

    public function testIncompleteBoardInstallationsBuildNothing(): void
    {
        foreach (
            [
                ['member' => null],
                ['function' => null],
                ['date' => null],
                ['function' => 'chair'],
            ] as $overrides
        ) {
            $decision = $this->mapDecision(
                new BoardInstallMapper(),
                $overrides + [
                    'member' => $this->member(8501),
                    'function' => BoardFunctions::Chair,
                    'date' => new DateTimeImmutable('2026-09-01'),
                ],
            );

            self::assertCount(
                0,
                $decision->getSubdecisions(),
                (string) json_encode($overrides),
            );
        }
    }

    public function testBoardReleaseRecordsTheDayTheDutiesEnd(): void
    {
        $installation = $this->boardInstallation();
        $date = new DateTimeImmutable('2027-09-01');

        $decision = $this->mapDecision(
            new BoardReleaseMapper(),
            [
                'subdecision' => $installation,
                'date' => $date,
            ],
        );

        $subDecision = $this->only($decision);
        self::assertInstanceOf(
            BoardRelease::class,
            $subDecision,
        );
        self::assertSame(
            1,
            $subDecision->sequence,
        );
        self::assertSame(
            $installation,
            $subDecision->installation,
        );
        self::assertSame(
            $date,
            $subDecision->date,
        );
    }

    public function testABoardReleaseNeedsBothTheInstallationAndADate(): void
    {
        foreach (
            [
                ['subdecision' => null],
                ['date' => null],
            ] as $overrides
        ) {
            $decision = $this->mapDecision(
                new BoardReleaseMapper(),
                $overrides + [
                    'subdecision' => $this->boardInstallation(),
                    'date' => new DateTimeImmutable('2027-09-01'),
                ],
            );

            self::assertCount(
                0,
                $decision->getSubdecisions(),
                (string) json_encode($overrides),
            );
        }
    }

    public function testBoardDischargeReleasesTheInstallation(): void
    {
        $installation = $this->boardInstallation();

        $decision = $this->mapDecision(
            new BoardDischargeMapper(),
            ['subdecision' => $installation],
        );

        $subDecision = $this->only($decision);
        self::assertInstanceOf(
            BoardDischarge::class,
            $subDecision,
        );
        self::assertSame(
            1,
            $subDecision->sequence,
        );
        self::assertSame(
            $installation,
            $subDecision->installation,
        );
    }

    public function testABoardDischargeNeedsTheInstallation(): void
    {
        $decision = $this->mapDecision(
            new BoardDischargeMapper(),
            ['subdecision' => null],
        );

        self::assertCount(
            0,
            $decision->getSubdecisions(),
        );
    }

    /**
     * A candidacy names the year once, and every candidate after it is a name in the constitutional order the board put
     * them in.
     */
    public function testCandidatesFollowTheYearInTheOrderTheyWereEntered(): void
    {
        $decision = $this->mapDecision(
            new CandidacyMapper(),
            [
                'boardYear' => 2026,
                'candidates' => [
                    $this->member(
                        8502,
                        'First',
                    ),
                    'not a member',
                    $this->member(
                        8503,
                        'Second',
                    ),
                ],
            ],
        );

        $subDecisions = $this->subDecisions($decision);
        self::assertCount(
            3,
            $subDecisions,
        );

        $candidacy = $subDecisions[0];
        self::assertInstanceOf(
            Candidacy::class,
            $candidacy,
        );
        self::assertSame(
            1,
            $candidacy->sequence,
        );
        self::assertSame(
            2026,
            $candidacy->boardYear,
        );

        $candidates = $this->all(
            $decision,
            Candidate::class,
        );
        self::assertSame(
            [
                [
                    2,
                    8502,
                ],
                [
                    3,
                    8503,
                ],
            ],
            array_map(
                static fn (Candidate $candidate): array => [
                    $candidate->sequence,
                    $candidate->getMember()->lidnr,
                ],
                $candidates,
            ),
        );
    }

    public function testACandidacyWithoutAYearBuildsNothing(): void
    {
        foreach (
            [
                ['boardYear' => null],
                ['boardYear' => '2026'],
            ] as $overrides
        ) {
            $decision = $this->mapDecision(
                new CandidacyMapper(),
                $overrides + [
                    'boardYear' => 2026,
                    'candidates' => [$this->member(8504)],
                ],
            );

            self::assertCount(
                0,
                $decision->getSubdecisions(),
                (string) json_encode($overrides),
            );
        }
    }

    public function testAKeyGrantRecordsTheGranteeAndTheDayItExpires(): void
    {
        $member = $this->member(8505);
        $until = new DateTimeImmutable('2026-12-01');

        $decision = $this->mapDecision(
            new GrantMapper(),
            [
                'grantee' => $member,
                'until' => $until,
            ],
        );

        $subDecision = $this->only($decision);
        self::assertInstanceOf(
            Granting::class,
            $subDecision,
        );
        self::assertSame(
            1,
            $subDecision->sequence,
        );
        self::assertSame(
            $member,
            $subDecision->getMember(),
        );
        self::assertSame(
            $until,
            $subDecision->until,
        );
    }

    public function testAKeyGrantNeedsBothTheMemberAndAnExpiry(): void
    {
        foreach (
            [
                ['grantee' => null],
                ['until' => null],
            ] as $overrides
        ) {
            $decision = $this->mapDecision(
                new GrantMapper(),
                $overrides + [
                    'grantee' => $this->member(8506),
                    'until' => new DateTimeImmutable('2026-12-01'),
                ],
            );

            self::assertCount(
                0,
                $decision->getSubdecisions(),
                (string) json_encode($overrides),
            );
        }
    }

    public function testAKeyWithdrawalRecordsTheDayTheKeyWasTakenBack(): void
    {
        $granting = new Granting();
        $granting->until = new DateTimeImmutable('2026-12-01');
        $withdrawnOn = new DateTimeImmutable('2026-11-01');

        $decision = $this->mapDecision(
            new WithdrawMapper(),
            [
                'subdecision' => $granting,
                'withdrawOn' => $withdrawnOn,
            ],
        );

        $subDecision = $this->only($decision);
        self::assertInstanceOf(
            Withdrawal::class,
            $subDecision,
        );
        self::assertSame(
            1,
            $subDecision->sequence,
        );
        self::assertSame(
            $granting,
            $subDecision->granting,
        );
        self::assertSame(
            $withdrawnOn,
            $subDecision->withdrawnOn,
        );
    }

    public function testAKeyWithdrawalNeedsTheGrantingAndADate(): void
    {
        foreach (
            [
                ['subdecision' => null],
                ['withdrawOn' => null],
            ] as $overrides
        ) {
            $decision = $this->mapDecision(
                new WithdrawMapper(),
                $overrides + [
                    'subdecision' => new Granting(),
                    'withdrawOn' => new DateTimeImmutable('2026-11-01'),
                ],
            );

            self::assertCount(
                0,
                $decision->getSubdecisions(),
                (string) json_encode($overrides),
            );
        }
    }

    public function testAWarningNamesTheMemberItIsIssuedTo(): void
    {
        $member = $this->member(8507);

        $decision = $this->mapDecision(
            new WarningMapper(),
            ['member' => $member],
        );

        $subDecision = $this->only($decision);
        self::assertInstanceOf(
            Warning::class,
            $subDecision,
        );
        self::assertSame(
            1,
            $subDecision->sequence,
        );
        self::assertSame(
            $member,
            $subDecision->getMember(),
        );
    }

    public function testAWarningWithoutAMemberBuildsNothing(): void
    {
        $decision = $this->mapDecision(
            new WarningMapper(),
            ['member' => null],
        );

        self::assertCount(
            0,
            $decision->getSubdecisions(),
        );
    }

    public function testASuspensionRecordsBothEndsOfThePeriod(): void
    {
        $member = $this->member(8508);
        $since = new DateTimeImmutable('2026-02-01');
        $until = new DateTimeImmutable('2026-03-01');

        $decision = $this->mapDecision(
            new SuspensionMapper(),
            [
                'member' => $member,
                'since' => $since,
                'until' => $until,
            ],
        );

        $subDecision = $this->only($decision);
        self::assertInstanceOf(
            Suspension::class,
            $subDecision,
        );
        self::assertSame(
            1,
            $subDecision->sequence,
        );
        self::assertSame(
            $member,
            $subDecision->getMember(),
        );
        self::assertSame(
            $since,
            $subDecision->since,
        );
        self::assertSame(
            $until,
            $subDecision->until,
        );
    }

    public function testASuspensionNeedsAMemberAndBothDates(): void
    {
        foreach (
            [
                ['member' => null],
                ['since' => null],
                ['until' => null],
            ] as $overrides
        ) {
            $decision = $this->mapDecision(
                new SuspensionMapper(),
                $overrides + [
                    'member' => $this->member(8509),
                    'since' => new DateTimeImmutable('2026-02-01'),
                    'until' => new DateTimeImmutable('2026-03-01'),
                ],
            );

            self::assertCount(
                0,
                $decision->getSubdecisions(),
                (string) json_encode($overrides),
            );
        }
    }

    /**
     * The minutes are about another meeting than the one the decision is taken in, which is why the form asks for both.
     */
    public function testMinutesRecordTheMeetingTheyAreOfAndWhoWroteThem(): void
    {
        $target = $this->meeting(
            MeetingTypes::ALV,
            42,
            '2026-06-01',
        );
        $author = $this->member(8510);

        $decision = $this->mapDecision(
            new MinutesMapper(),
            [
                'fmeeting' => $target,
                'author' => $author,
                'approve' => 1,
                'changes' => 1,
            ],
        );

        $subDecision = $this->only($decision);
        self::assertInstanceOf(
            Minutes::class,
            $subDecision,
        );
        self::assertSame(
            1,
            $subDecision->sequence,
        );
        self::assertSame(
            $target,
            $subDecision->getTarget(),
        );
        self::assertSame(
            $author,
            $subDecision->getMember(),
        );
        self::assertTrue($subDecision->approval);
        self::assertTrue($subDecision->changes);
    }

    public function testMinutesNeedBothTheMeetingAndTheAuthor(): void
    {
        foreach (
            [
                ['fmeeting' => null],
                ['author' => null],
            ] as $overrides
        ) {
            $decision = $this->mapDecision(
                new MinutesMapper(),
                $overrides + [
                    'fmeeting' => $this->meeting(),
                    'author' => $this->member(8511),
                ],
            );

            self::assertCount(
                0,
                $decision->getSubdecisions(),
                (string) json_encode($overrides),
            );
        }
    }

    /**
     * A free-form decision is literal text in both languages; a translation that was not written is stored as such
     * rather than copied, so the fallback stays visible.
     */
    public function testAFreeDecisionStoresBothTexts(): void
    {
        $decision = $this->mapDecision(
            new OtherMapper(),
            [
                'contentNL' => 'De vereniging doet mee.',
                'contentEN' => 'The association takes part.',
            ],
        );

        $subDecision = $this->only($decision);
        self::assertInstanceOf(
            Other::class,
            $subDecision,
        );
        self::assertSame(
            1,
            $subDecision->sequence,
        );
        self::assertSame(
            'De vereniging doet mee.',
            $subDecision->contentNL,
        );
        self::assertSame(
            'The association takes part.',
            $subDecision->contentEN,
        );
    }

    public function testAFreeDecisionWithoutBothTextsBuildsNothing(): void
    {
        foreach (
            [
                ['contentNL' => null],
                ['contentEN' => null],
                [
                    'contentNL' => [
                        'not',
                        'a',
                        'string',
                    ],
                ],
            ] as $overrides
        ) {
            $decision = $this->mapDecision(
                new OtherMapper(),
                $overrides + [
                    'contentNL' => 'De vereniging doet mee.',
                    'contentEN' => 'The association takes part.',
                ],
            );

            self::assertCount(
                0,
                $decision->getSubdecisions(),
                (string) json_encode($overrides),
            );
        }
    }

    private function foundation(): Foundation
    {
        $foundation = new Foundation();
        $foundation->abbr = 'TC';
        $foundation->name = 'Taartcommissie';

        return $foundation;
    }

    private function boardInstallation(): BoardInstallation
    {
        $installation = new BoardInstallation();
        $installation->sequence = 1;
        $installation->setMember($this->member(8512));
        $installation->function = BoardFunctions::Chair;
        $installation->date = new DateTimeImmutable('2026-09-01');

        return $installation;
    }

    private function only(Decision $decision): SubDecision
    {
        $subDecisions = $this->subDecisions($decision);
        self::assertCount(
            1,
            $subDecisions,
        );

        return $subDecisions[0];
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    private function all(
        Decision $decision,
        string $class,
    ): array {
        $matching = [];
        foreach ($this->subDecisions($decision) as $subDecision) {
            if (!($subDecision instanceof $class)) {
                continue;
            }

            $matching[] = $subDecision;
        }

        return $matching;
    }
}
