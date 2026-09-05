<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Activity;

use App\Entity\Activity\Enums\AllocationMethod;
use App\Entity\Activity\Enums\DrawCutoffRule;
use App\Entity\Activity\Enums\MembershipPriorityMode;
use App\Entity\Activity\Enums\MembershipTier;
use App\Entity\Activity\ExternalSignup;
use App\Entity\Activity\SignupList;
use App\Entity\Decision\Member;
use App\Service\Activity\DrawManager;
use App\Tests\Integration\DatabaseTestCase;
use DateTime;

use function array_map;
use function count;
use function json_encode;

/**
 * The shared draw runner behind both the board's manual draw and the automated deadline draw. Pinned here: the guard
 * differences between the two paths (a manual draw needs close or a passed draw moment, an automated draw its own
 * moment), the one-shot lock, the audit stamp (a member for manual, null for automated), the refusal to touch
 * manual-allocation lists and that the manual fallback draws the same cutoff snapshot as an on-time automated draw.
 * Dates are pinned explicitly per scenario, so nothing depends on seed freshness.
 *
 * Activity #9 (the Gala) has an open limited list (#6, capacity 2, four waitlisted sign-ups); activity #13 (the
 * Excursion) has a closed limited list (#11, capacity 2, four sign-ups).
 */
final class DrawManagerTest extends DatabaseTestCase
{
    public function testManualDrawOnAClosedListAdmitsUpToCapacityAndStampsTheMember(): void
    {
        $this->pinDates(
            11,
            closeDate: '-1 hour',
            endTime: '+2 days',
        );
        $list = $this->list(11);
        $board = $this->member(8025);

        self::assertTrue($this->drawManager()->drawManually(
            $list,
            AllocationMethod::ConditionalDraw,
            $board,
        ));

        self::assertNotNull($list->getDrawnAt());
        self::assertSame(
            $board->getLidnr(),
            $list->getDrawnBy()?->getLidnr(),
        );
        self::assertSame(
            2,
            $this->drawnCount($list),
        );
    }

    public function testADrawIsAOneShotEvent(): void
    {
        $this->pinDates(
            11,
            closeDate: '-1 hour',
            endTime: '+2 days',
        );
        $list = $this->list(11);
        $board = $this->member(8025);

        self::assertTrue($this->drawManager()->drawManually(
            $list,
            AllocationMethod::ConditionalDraw,
            $board,
        ));
        // The runner refreshes the row inside its lock, so compare at the column's (second) precision.
        $drawnAt = $list->getDrawnAt()?->format('Y-m-d H:i:s');

        // The second draw bails on the lock recheck: the result of a lottery never changes.
        self::assertFalse($this->drawManager()->drawManually(
            $list,
            AllocationMethod::ConditionalDraw,
            $board,
        ));
        self::assertSame(
            $drawnAt,
            $list->getDrawnAt()?->format('Y-m-d H:i:s'),
        );
    }

    public function testManualDrawIsRefusedBeforeCloseWhenNoDrawMomentHasPassed(): void
    {
        $this->pinDates(
            6,
            closeDate: '+1 week',
            endTime: '+8 days',
        );
        $list = $this->list(6);

        // On-close list, still open: a board member cannot pre-empt the announced moment.
        self::assertFalse($this->drawManager()->drawManually(
            $list,
            AllocationMethod::ConditionalDraw,
            $this->member(8025),
        ));
        self::assertNull($list->getDrawnAt());
    }

    public function testManualDrawIsAllowedBeforeCloseOnceTheDrawMomentHasPassed(): void
    {
        // The if-full-before cutoff passed an hour ago but the automated draw did not run (say the scheduler was
        // down): the board may step in even though sign-up is still open.
        $this->pinDates(
            6,
            closeDate: '+1 week',
            endTime: '+8 days',
            rule: DrawCutoffRule::IfFullBefore,
            cutoffAt: '-1 hour',
        );
        $list = $this->list(6);

        self::assertTrue($this->drawManager()->drawManually(
            $list,
            AllocationMethod::ConditionalDraw,
            $this->member(8025),
        ));
    }

    public function testManualDrawIsRefusedForAMismatchedMethod(): void
    {
        $this->pinDates(
            11,
            closeDate: '-1 hour',
            endTime: '+2 days',
        );
        $list = $this->list(11);

        // A stale first-come button on a list since reconfigured to a conditional draw must not run anything.
        self::assertFalse($this->drawManager()->drawManually(
            $list,
            AllocationMethod::FirstComeFirstServed,
            $this->member(8025),
        ));
        self::assertNull($list->getDrawnAt());
    }

    public function testAutomaticDrawIsRefusedBeforeTheDrawMoment(): void
    {
        $this->pinDates(
            6,
            closeDate: '+1 week',
            endTime: '+8 days',
        );

        self::assertFalse($this->drawManager()->drawAutomatically($this->list(6)));
    }

    public function testAutomaticDrawAdmitsUpToCapacityWithoutAMemberStamp(): void
    {
        $this->pinDates(
            11,
            closeDate: '-1 hour',
            endTime: '+2 days',
        );
        $list = $this->list(11);

        self::assertTrue($this->drawManager()->drawAutomatically($list));

        self::assertNotNull($list->getDrawnAt());
        self::assertNull($list->getDrawnBy());
        self::assertSame(
            2,
            $this->drawnCount($list),
        );
        // The rest is waitlisted with attendance cleared: you cannot have attended without being admitted.
        foreach ($list->getSignUps() as $signup) {
            if ($signup->isDrawn()) {
                continue;
            }

            self::assertFalse($signup->isPresent());
        }
    }

    public function testAutomaticDrawNeverTouchesAManualAllocationMethod(): void
    {
        $list = $this->manualMethodList();

        self::assertFalse($this->drawManager()->drawAutomatically($list));
        self::assertNull($list->getDrawnAt());
    }

    public function testManualDrawUsesTheSameCutoffSnapshot(): void
    {
        // The board's fallback for a missed automated draw must produce what an on-time draw would have: only the two
        // sign-ups from before the cutoff (deliberately the LAST two by id) join the lottery -- and exactly fill the
        // two places -- while the two that arrived after the cutoff never displace them.
        $ids = $this->signupIds(6);
        $this->pinSignupCreatedAt(
            $ids[2],
            '-2 hours',
        );
        $this->pinSignupCreatedAt(
            $ids[3],
            '-2 hours',
        );
        $this->pinSignupCreatedAt(
            $ids[0],
            '-30 minutes',
        );
        $this->pinSignupCreatedAt(
            $ids[1],
            '-20 minutes',
        );
        $this->pinDates(
            6,
            closeDate: '+1 week',
            endTime: '+8 days',
            rule: DrawCutoffRule::IfFullBefore,
            cutoffAt: '-1 hour',
        );
        $list = $this->list(6);

        self::assertTrue($this->drawManager()->drawManually(
            $list,
            AllocationMethod::ConditionalDraw,
            $this->member(8025),
        ));

        self::assertSame(
            [
                $ids[2],
                $ids[3],
            ],
            $this->drawnIds($list),
        );
    }

    public function testAMembershipOrderDecidesWhoGetsThePlaces(): void
    {
        $this->clearDraw(3);
        $this->pinSubscribedAt(
            3,
            '-2 hours',
        );
        $this->pinDates(
            3,
            closeDate: '-1 hour',
            endTime: '+2 days',
        );
        $this->pinMembershipOrder(
            3,
            MembershipTier::defaultOrder(),
        );
        $list = $this->list(3);
        $external = $this->externalSignupId(3);

        self::assertTrue($this->drawManager()->drawManually(
            $list,
            AllocationMethod::ConditionalDraw,
            $this->member(8025),
        ));

        self::assertNotContains(
            $external,
            $this->drawnIds($list),
        );
    }

    public function testTheWaitingListKeepsTheOrderTheDrawGaveIt(): void
    {
        $this->clearDraw(3);
        $this->pinSubscribedAt(
            3,
            '-2 hours',
        );
        $this->pinDates(
            3,
            closeDate: '-1 hour',
            endTime: '+2 days',
        );
        $this->pinMembershipOrder(
            3,
            MembershipTier::defaultOrder(),
        );
        $list = $this->list(3);
        $external = $this->externalSignupId(3);

        self::assertTrue($this->drawManager()->drawManually(
            $list,
            AllocationMethod::ConditionalDraw,
            $this->member(8025),
        ));

        $ranked = [];
        foreach ($this->list(3)->getSignUpsInAdmissionOrder() as $signup) {
            if (null === $signup->getDrawPosition()) {
                continue;
            }

            $ranked[] = $signup;
        }

        // Everybody the draw looked at holds the place it gave them, in that order.
        foreach ($ranked as $place => $signup) {
            self::assertSame(
                $place + 1,
                $signup->getDrawPosition(),
            );
        }

        // The external ranks below every member, so they are last on the waiting list rather than first.
        self::assertSame(
            $external,
            (int) $ranked[count($ranked) - 1]->getId(),
        );
        self::assertFalse($ranked[count($ranked) - 1]->isDrawn());
    }

    public function testTurningTheMembershipOrderAroundServesNonMembersFirst(): void
    {
        $this->clearDraw(3);
        $this->pinSubscribedAt(
            3,
            '-2 hours',
        );
        $this->pinDates(
            3,
            closeDate: '-1 hour',
            endTime: '+2 days',
        );
        $this->pinMembershipOrder(
            3,
            [
                MembershipTier::NonMember,
                MembershipTier::Ordinary,
                MembershipTier::Graduate,
            ],
        );
        $list = $this->list(3);
        $external = $this->externalSignupId(3);

        self::assertTrue($this->drawManager()->drawManually(
            $list,
            AllocationMethod::ConditionalDraw,
            $this->member(8025),
        ));

        self::assertContains(
            $external,
            $this->drawnIds($list),
        );
    }

    public function testAListGuaranteeingARoleIsNeverDrawnAutomatically(): void
    {
        $this->addRole(
            6,
            'Driver',
            1,
        );
        $this->pinDates(
            6,
            closeDate: '+1 week',
            endTime: '+8 days',
            rule: DrawCutoffRule::IfFullBefore,
            cutoffAt: '-1 hour',
        );
        $list = $this->list(6);

        self::assertTrue($list->isAutoDrawDue());
        self::assertFalse($this->drawManager()->drawAutomatically($list));
        self::assertNull($list->getDrawnAt());
    }

    public function testAListGuaranteeingARoleIsNotDrawnByHandBeforeItCloses(): void
    {
        $this->addRole(
            6,
            'Driver',
            1,
        );
        $this->pinDates(
            6,
            closeDate: '+1 week',
            endTime: '+8 days',
            rule: DrawCutoffRule::IfFullBefore,
            cutoffAt: '-1 hour',
        );
        $list = $this->list(6);

        self::assertFalse($this->drawManager()->drawManually(
            $list,
            AllocationMethod::ConditionalDraw,
            $this->member(8025),
        ));
        self::assertNull($list->getDrawnAt());
    }

    public function testTheDrawMakesUpAShortfallInAGuaranteedRole(): void
    {
        $ids = $this->signupIds(11);
        $roleId = $this->addRole(
            11,
            'Driver',
            1,
        );
        $this->assignRole(
            $ids[3],
            $roleId,
        );
        $this->pinSubscribedAt(
            11,
            '-2 hours',
        );
        $this->pinDates(
            11,
            closeDate: '-1 hour',
            endTime: '+2 days',
        );
        $list = $this->list(11);

        self::assertTrue($this->drawManager()->drawManually(
            $list,
            AllocationMethod::ConditionalDraw,
            $this->member(8025),
        ));

        self::assertContains(
            $ids[3],
            $this->drawnIds($list),
        );
        self::assertSame(
            2,
            $this->drawnCount($list),
        );
    }

    public function testTheSeededListWithEverythingAtOnceAdmitsBothDrivers(): void
    {
        // ÅLLOC-F2: closed, two drivers handed out, one place for the organising body, an order and a cohort order.
        $list = $this->list(31);

        self::assertTrue($this->drawManager()->drawManually(
            $list,
            AllocationMethod::ConditionalDraw,
            $this->member(8025),
        ));

        $drivers = 0;
        foreach ($list->getSignUps() as $signup) {
            if (
                null === $signup->getRole()
                || !$signup->isDrawn()
            ) {
                continue;
            }

            ++$drivers;
        }

        self::assertSame(
            2,
            $drivers,
        );
        self::assertSame(
            6,
            $this->drawnCount($list),
        );
    }

    public function testARoleHolderWhoSignedUpAfterTheDrawMomentStillGetsAPlace(): void
    {
        $ids = $this->signupIds(11);
        $roleId = $this->addRole(
            11,
            'Driver',
            1,
        );
        $this->pinSubscribedAt(
            11,
            '-3 hours',
        );
        $this->pinDates(
            11,
            closeDate: '-1 hour',
            endTime: '+2 days',
        );
        // The last to sign up did so after the list had closed, which puts them behind the on-time pool; the role
        // is handed out afterwards, to anybody on the list.
        $this->entityManager->getConnection()->update(
            'Signup',
            ['createdAt' => $this->sqlDateTime('-30 minutes')],
            ['id' => $ids[3]],
        );
        $this->entityManager->clear();
        $this->assignRole(
            $ids[3],
            $roleId,
        );
        $list = $this->list(11);

        self::assertTrue($this->drawManager()->drawManually(
            $list,
            AllocationMethod::ConditionalDraw,
            $this->member(8025),
        ));

        self::assertContains(
            $ids[3],
            $this->drawnIds($list),
        );
        self::assertSame(
            2,
            $this->drawnCount($list),
        );
    }

    private function pinSubscribedAt(
        int $listId,
        string $modifier,
    ): void {
        $at = $this->sqlDateTime($modifier);
        $connection = $this->entityManager->getConnection();
        $connection->update(
            'Signup',
            ['createdAt' => $at],
            ['signuplist_id' => $listId],
        );
        $connection->executeStatement(
            'UPDATE Signup SET verifiedAt = ? WHERE signuplist_id = ? AND verifiedAt IS NOT NULL',
            [
                $at,
                $listId,
            ],
        );

        $this->entityManager->clear();
    }

    private function clearDraw(int $listId): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->update(
            'SignupList',
            [
                'drawnAt' => null,
                'drawnBy_id' => null,
            ],
            ['id' => $listId],
        );
        $connection->update(
            'Signup',
            ['drawn' => 0],
            ['signuplist_id' => $listId],
        );

        $this->entityManager->clear();
    }

    /**
     * @param list<MembershipTier> $order
     */
    private function pinMembershipOrder(
        int $listId,
        array $order,
    ): void {
        $this->entityManager->getConnection()->update(
            'SignupList',
            [
                'membershipTierOrder' => json_encode(array_map(
                    static fn (MembershipTier $tier): string => $tier->value,
                    $order,
                )),
                'membershipPriorityMode' => MembershipPriorityMode::Ordering->value,
            ],
            ['id' => $listId],
        );

        $this->entityManager->clear();
    }

    private function addRole(
        int $listId,
        string $name,
        int $minimum,
    ): int {
        $connection = $this->entityManager->getConnection();
        $connection->insert(
            'SignupRole',
            [
                'signuplist_id' => $listId,
                'name' => $name,
                'minimum' => $minimum,
                'position' => 0,
            ],
        );

        $this->entityManager->clear();

        return (int) $connection->lastInsertId();
    }

    private function assignRole(
        int $signupId,
        int $roleId,
    ): void {
        $this->entityManager->getConnection()->update(
            'Signup',
            ['role_id' => $roleId],
            ['id' => $signupId],
        );

        $this->entityManager->clear();
    }

    private function externalSignupId(int $listId): int
    {
        foreach ($this->list($listId)->getSignUps() as $signup) {
            if (!$signup instanceof ExternalSignup) {
                continue;
            }

            return (int) $signup->getId();
        }

        self::fail('The seed is expected to contain a confirmed external sign-up on this list.');
    }

    private function drawManager(): DrawManager
    {
        return self::getContainer()->get(DrawManager::class);
    }

    private function list(int $listId): SignupList
    {
        $list = $this->entityManager->getRepository(SignupList::class)->find($listId);
        self::assertInstanceOf(
            SignupList::class,
            $list,
        );

        return $list;
    }

    private function manualMethodList(): SignupList
    {
        $list = $this->entityManager->createQueryBuilder()
            ->select('sl')
            ->from(
                SignupList::class,
                'sl',
            )
            ->where('sl.allocationMethod = :method')
            ->setParameter(
                'method',
                AllocationMethod::ExternalParty,
            )
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        self::assertInstanceOf(
            SignupList::class,
            $list,
            'The seed is expected to contain an external-party sign-up list.',
        );

        return $list;
    }

    private function member(int $lidnr): Member
    {
        $member = $this->entityManager->getRepository(Member::class)->find($lidnr);
        self::assertInstanceOf(
            Member::class,
            $member,
        );

        return $member;
    }

    private function drawnCount(SignupList $list): int
    {
        $drawn = 0;
        foreach ($list->getSignUps() as $signup) {
            if (!$signup->isDrawn()) {
                continue;
            }

            ++$drawn;
        }

        return $drawn;
    }

    /**
     * The admitted sign-up ids of a list, in id order.
     *
     * @return list<int>
     */
    private function drawnIds(SignupList $list): array
    {
        $ids = [];
        foreach ($list->getSignUps() as $signup) {
            if (!$signup->isDrawn()) {
                continue;
            }

            $ids[] = (int) $signup->getId();
        }

        return $ids;
    }

    /**
     * All sign-up ids of a list, in id (arrival) order.
     *
     * @return list<int>
     */
    private function signupIds(int $listId): array
    {
        $ids = [];
        foreach ($this->list($listId)->getSignUps() as $signup) {
            $ids[] = (int) $signup->getId();
        }

        return $ids;
    }

    /**
     * Pin when a sign-up was created, directly in the database (rolled back with the test), so a late draw's cutoff
     * snapshot can be exercised against explicit participation moments. Clears the entity manager so subsequent reads
     * see the updated row.
     */
    private function pinSignupCreatedAt(
        int $signupId,
        string $modifier,
    ): void {
        $this->entityManager->getConnection()->update(
            'Signup',
            ['createdAt' => $this->sqlDateTime($modifier)],
            ['id' => $signupId],
        );

        $this->entityManager->clear();
    }

    /**
     * Pin a seeded list's timing/cutoff configuration (and its activity's end, which bounds the admission window) to
     * explicit now-relative moments, directly in the database (rolled back with the test). Clears the entity manager
     * so the subsequent reads see the updated rows.
     */
    private function pinDates(
        int $listId,
        ?string $closeDate = null,
        ?string $endTime = null,
        ?DrawCutoffRule $rule = null,
        ?string $cutoffAt = null,
    ): void {
        $revisionId = $this->list($listId)->getRevision()->getId();
        $connection = $this->entityManager->getConnection();

        $fields = [
            'closeDate' => null === $closeDate ? null : $this->sqlDateTime($closeDate),
            'drawCutoffRule' => $rule?->value,
            'drawCutoffAt' => null === $cutoffAt ? null : $this->sqlDateTime($cutoffAt),
        ];
        $data = [];
        foreach ($fields as $field => $value) {
            if (null === $value) {
                continue;
            }

            $data[$field] = $value;
        }

        $connection->update(
            'SignupList',
            $data,
            ['id' => $listId],
        );

        if (null !== $endTime) {
            $connection->update(
                'ActivityRevision',
                ['endTime' => $this->sqlDateTime($endTime)],
                ['id' => $revisionId],
            );
        }

        $this->entityManager->clear();
    }

    private function sqlDateTime(string $modifier): string
    {
        return new DateTime($modifier)->format('Y-m-d H:i:s');
    }
}
