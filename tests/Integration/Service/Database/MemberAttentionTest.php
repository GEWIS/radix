<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Database;

use App\Entity\Database\Enums\AttentionReasons;
use App\Entity\Database\Enums\InstallationFunctions;
use App\Entity\Database\Enums\MembershipTypes;
use App\Entity\Database\Member as MemberModel;
use App\Service\Database\Member;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\LedgerBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;

use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function in_array;

/**
 * The attention overview: which members need a decision from the secretary, why, and which of them the bulk
 * renewal shortcuts may take in one go.
 *
 * Membership numbers are compared rather than counts, because the seed contributes rows of its own that this test
 * has no opinion about.
 */
#[CoversClass(Member::class)]
final class MemberAttentionTest extends DatabaseTestCase
{
    private EntityManagerInterface $ledger;

    private Member $memberService;

    private LedgerBuilder $build;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = self::getContainer()->get(EntityManagerInterface::class);
        $this->memberService = self::getContainer()->get(Member::class);
        $this->build = new LedgerBuilder($this->ledger);
    }

    public function testAnOrdinaryMemberExpiringInsideTheWindowIsListedWithoutItsStudentNumber(): void
    {
        $member = $this->withCompleteProfile(
            $this->build->member(
                MembershipTypes::Ordinary,
                '-1 month',
                '+10 days',
            ),
        );
        $member->studentNumber = null;
        $this->ledger->flush();

        self::assertSame(
            [
                AttentionReasons::MissingStudentNumberOrdinary,
                AttentionReasons::ExpiringOrdinaryNonActive,
            ],
            $this->reasonsFor($member),
        );
    }

    public function testTheDaysBoundaryDecidesWhetherAMembershipIsExpiring(): void
    {
        $inside = $this->withCompleteProfile(
            $this->build->member(
                MembershipTypes::Ordinary,
                '-1 month',
                '+30 days',
            ),
        );
        $outside = $this->withCompleteProfile(
            $this->build->member(
                MembershipTypes::Ordinary,
                '-1 month',
                '+31 days',
            ),
        );

        $result = $this->memberService->getMembersRequiringAttention(30);
        $listed = array_map(
            static fn (array $row): int => $row['member']->lidnr,
            $result['rows'],
        );

        self::assertSame(
            30,
            $result['days'],
        );
        self::assertContains(
            $inside->lidnr,
            $listed,
        );
        self::assertNotContains(
            $outside->lidnr,
            $listed,
        );
        self::assertSame(
            [AttentionReasons::ExpiringOrdinaryNonActive],
            $this->reasonsFor($inside),
        );
    }

    public function testExternalMembersAreSplitIntoActiveAndNonActive(): void
    {
        $active = $this->withCompleteProfile(
            $this->build->member(
                MembershipTypes::External,
                '-1 month',
                '+10 days',
            ),
        );
        $this->makeActive($active);

        $nonActive = $this->withCompleteProfile(
            $this->build->member(
                MembershipTypes::External,
                '-1 month',
                '+10 days',
            ),
        );

        self::assertSame(
            [AttentionReasons::ExpiringExternalActive],
            $this->reasonsFor($active),
        );
        self::assertSame(
            [AttentionReasons::ExpiringExternalNonActive],
            $this->reasonsFor($nonActive),
        );

        $shortcuts = $this->memberService->getMembersRequiringAttention(30)['bulk_renewal_shortcuts'];
        self::assertContains(
            $active->lidnr,
            $shortcuts['expiring_active'],
        );
        self::assertNotContains(
            $nonActive->lidnr,
            $shortcuts['expiring_active'],
        );
        self::assertContains(
            $nonActive->lidnr,
            $shortcuts['expiring_non_active'],
        );
        self::assertNotContains(
            $active->lidnr,
            $shortcuts['expiring_non_active'],
        );
    }

    public function testAnOrdinaryMemberInAnOrganIsActiveInsteadOfNonActive(): void
    {
        $meeting = $this->build->meeting(date: '-1 month');
        $foundation = $this->build->foundOrgan($meeting);

        $active = $this->withCompleteProfile(
            $this->build->member(
                MembershipTypes::Ordinary,
                '-1 month',
                '+10 days',
            ),
        );
        $this->build->install(
            $meeting,
            $foundation,
            $active,
            InstallationFunctions::Member,
        );

        $nonActive = $this->withCompleteProfile(
            $this->build->member(
                MembershipTypes::Ordinary,
                '-1 month',
                '+10 days',
            ),
        );

        self::assertSame(
            [AttentionReasons::ExpiringOrdinaryActive],
            $this->reasonsFor($active),
        );
        self::assertSame(
            [AttentionReasons::ExpiringOrdinaryNonActive],
            $this->reasonsFor($nonActive),
        );

        $shortcuts = $this->memberService->getMembersRequiringAttention(30)['bulk_renewal_shortcuts'];
        self::assertContains(
            $active->lidnr,
            $shortcuts['expiring_active'],
        );
        self::assertNotContains(
            $nonActive->lidnr,
            $shortcuts['expiring_active'],
        );
        self::assertContains(
            $nonActive->lidnr,
            $shortcuts['expiring_non_active'],
        );
        self::assertNotContains(
            $active->lidnr,
            $shortcuts['expiring_non_active'],
        );
    }

    public function testAGraduateInAnOrganIsListedForConversion(): void
    {
        $meeting = $this->build->meeting(date: '-1 month');
        $foundation = $this->build->foundOrgan($meeting);

        $member = $this->withCompleteProfile(
            $this->build->member(
                MembershipTypes::Graduate,
                '-1 month',
                '+10 days',
            ),
        );
        $this->build->install(
            $meeting,
            $foundation,
            $member,
            InstallationFunctions::Member,
        );

        self::assertSame(
            [AttentionReasons::ExpiringGraduateActiveInactive],
            $this->reasonsFor($member),
        );
    }

    public function testAMemberWithSeveralProblemsAppearsOnceWithAllOfThem(): void
    {
        $member = $this->build->member(
            MembershipTypes::Ordinary,
            '-1 month',
            '+10 days',
        );
        $member->setEmail(null);
        $member->studentNumber = null;
        $this->ledger->flush();

        $result = $this->memberService->getMembersRequiringAttention(30);
        $rows = array_values(array_filter(
            $result['rows'],
            static fn (array $row): bool => $row['member']->lidnr === $member->lidnr,
        ));

        self::assertCount(
            1,
            $rows,
        );
        self::assertSame(
            [
                AttentionReasons::MissingEmail,
                AttentionReasons::MissingStudentNumberOrdinary,
                AttentionReasons::ExpiringOrdinaryNonActive,
            ],
            $rows[0]['reasons'],
        );
    }

    public function testARowWithoutEmailIsListedEvenWhenNothingElseIsWrong(): void
    {
        $member = $this->withCompleteProfile(
            $this->build->member(
                MembershipTypes::Ordinary,
                '-6 months',
                '+20 months',
            ),
        );
        $member->setEmail(null);
        $this->ledger->flush();

        self::assertSame(
            [AttentionReasons::MissingEmail],
            $this->reasonsFor($member),
        );
    }

    public function testRowsAreOrderedByExpirationThenName(): void
    {
        $early = $this->withCompleteProfile(
            $this->build->member(
                MembershipTypes::Ordinary,
                '-1 month',
                '+5 days',
            ),
        );
        $early->lastName = 'Zzz';

        $late = $this->withCompleteProfile(
            $this->build->member(
                MembershipTypes::Ordinary,
                '-1 month',
                '+20 days',
            ),
        );
        $late->lastName = 'Aaa';

        $sameDayFirst = $this->withCompleteProfile(
            $this->build->member(
                MembershipTypes::Ordinary,
                '-1 month',
                '+15 days',
            ),
        );
        $sameDayFirst->lastName = 'Aaa';

        $sameDaySecond = $this->withCompleteProfile(
            $this->build->member(
                MembershipTypes::Ordinary,
                '-1 month',
                '+15 days',
            ),
        );
        $sameDaySecond->lastName = 'Bbb';

        $this->ledger->flush();

        $wanted = [
            $early->lidnr,
            $late->lidnr,
            $sameDayFirst->lidnr,
            $sameDaySecond->lidnr,
        ];
        $ordered = [];
        foreach ($this->memberService->getMembersRequiringAttention(30)['rows'] as $row) {
            if (
                !in_array(
                    $row['member']->lidnr,
                    $wanted,
                    true,
                )
            ) {
                continue;
            }

            $ordered[] = $row['member']->lidnr;
        }

        self::assertSame(
            [
                $early->lidnr,
                $sameDayFirst->lidnr,
                $sameDaySecond->lidnr,
                $late->lidnr,
            ],
            $ordered,
        );
    }

    public function testShortcutsHoldEachMemberOnce(): void
    {
        $meeting = $this->build->meeting(date: '-1 month');
        $foundation = $this->build->foundOrgan($meeting);

        $active = $this->withCompleteProfile(
            $this->build->member(
                MembershipTypes::Ordinary,
                '-1 month',
                '+10 days',
            ),
        );
        $this->build->install(
            $meeting,
            $foundation,
            $active,
            InstallationFunctions::Member,
        );

        $shortcuts = $this->memberService->getMembersRequiringAttention(30)['bulk_renewal_shortcuts'];

        self::assertSame(
            array_values(array_unique($shortcuts['expiring_active'])),
            $shortcuts['expiring_active'],
        );
        self::assertSame(
            array_values(array_unique($shortcuts['expiring_non_active'])),
            $shortcuts['expiring_non_active'],
        );
        self::assertContains(
            $active->lidnr,
            $shortcuts['expiring_active'],
        );
    }

    /**
     * The builder leaves the student number empty, which is a reason in its own right.
     */
    private function withCompleteProfile(MemberModel $member): MemberModel
    {
        $member->studentNumber = '1234567';
        $this->ledger->flush();

        return $member;
    }

    private function makeActive(MemberModel $member): void
    {
        $meeting = $this->build->meeting(date: '-1 month');
        $foundation = $this->build->foundOrgan($meeting);
        $this->build->install(
            $meeting,
            $foundation,
            $member,
            InstallationFunctions::Member,
        );
    }

    /**
     * @return AttentionReasons[]
     */
    private function reasonsFor(MemberModel $member): array
    {
        foreach ($this->memberService->getMembersRequiringAttention(30)['rows'] as $row) {
            if ($row['member']->lidnr === $member->lidnr) {
                return $row['reasons'];
            }
        }

        return [];
    }
}
