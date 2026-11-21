<?php

declare(strict_types=1);

namespace App\Tests\Entity\Decision;

use App\Entity\Database\Enums\BoardFunctions;
use App\Entity\Database\Enums\InstallationFunctions;
use App\Entity\Decision\BoardMember;
use App\Entity\Decision\Keyholder;
use App\Entity\Decision\Member;
use App\Entity\Decision\OrganMember;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What the projection of a member derives about them: their age on a date, whether a key grant, an organ membership or
 * a board seat of theirs is still running, and whether they are the board's secretary right now. These are the
 * questions the register's access rules ask, so a boundary a day either way is the whole of the behaviour.
 */
#[CoversClass(Member::class)]
final class MemberTest extends TestCase
{
    public function testAgeIsReachedOnTheBirthdayItself(): void
    {
        $member = $this->member('2008-06-15');

        self::assertFalse($member->hasReached16(new DateTimeImmutable('2024-06-14')));
        self::assertTrue($member->hasReached16(new DateTimeImmutable('2024-06-15')));

        $adult = $this->member('2005-06-15');
        self::assertFalse($adult->hasReached18(new DateTimeImmutable('2023-06-14')));
        self::assertTrue($adult->hasReached18(new DateTimeImmutable('2023-06-15')));

        self::assertFalse($adult->hasReached21(new DateTimeImmutable('2026-06-14')));
        self::assertTrue($adult->hasReached21(new DateTimeImmutable('2026-06-15')));
    }

    public function testAMemberIsOnlyAKeyholderWhileAGrantOfTheirsRuns(): void
    {
        $member = $this->member('2000-01-01');
        self::assertFalse($member->isKeyholder());

        $expired = new Keyholder();
        $expired->expirationDate = new DateTimeImmutable('-1 day');
        $member->getKeyGrantings()->add($expired);
        self::assertFalse(
            $member->isKeyholder(),
            'A grant that has run out no longer makes anybody a keyholder.',
        );

        $withdrawn = new Keyholder();
        $withdrawn->expirationDate = new DateTimeImmutable('+1 month');
        $withdrawn->withdrawnDate = new DateTimeImmutable('-1 day');
        $member->getKeyGrantings()->add($withdrawn);
        self::assertFalse(
            $member->isKeyholder(),
            'A withdrawn grant is over even though it had time left.',
        );

        $current = new Keyholder();
        $current->expirationDate = new DateTimeImmutable('+1 month');
        $member->getKeyGrantings()->add($current);
        self::assertTrue($member->isKeyholder());
    }

    public function testASeatReleasedInTheFutureIsStillRunning(): void
    {
        $member = $this->member('2000-01-01');
        $seat = $this->seat(
            BoardFunctions::Chair,
            new DateTimeImmutable('-1 month'),
        );
        $seat->releaseDate = new DateTimeImmutable('+1 month');
        $seat->dischargeDate = new DateTimeImmutable('+2 months');

        $member->getBoardInstallations()->add($seat);

        self::assertTrue($member->isBoardMember());
        self::assertTrue($member->isCurrentBoard($seat));
    }

    public function testASeatThatHasNotTakenEffectYetIsNotCurrent(): void
    {
        $member = $this->member('2000-01-01');
        $seat = $this->seat(
            BoardFunctions::Chair,
            new DateTimeImmutable('+1 month'),
        );

        $member->getBoardInstallations()->add($seat);

        self::assertFalse($member->isCurrentBoard($seat));
        self::assertFalse($member->isBoardMember());
    }

    public function testTheSecretaryIsOnlyServingWhileTheirSeatRuns(): void
    {
        $member = $this->member('2000-01-01');

        // A chair is on the board but is not the secretary.
        $chair = $this->seat(
            BoardFunctions::Chair,
            new DateTimeImmutable('-1 month'),
        );
        $member->getBoardInstallations()->add($chair);
        self::assertFalse($member->isServingSecretary());

        $chair->releaseDate = new DateTimeImmutable('-1 day');

        $secretary = $this->seat(
            BoardFunctions::Secretary,
            new DateTimeImmutable('-1 month'),
        );
        $member->getBoardInstallations()->add($secretary);
        self::assertTrue($member->isServingSecretary());
        self::assertFalse($member->isReleasedButUndischargedSecretary());

        // Relieved yesterday and not yet discharged: they can still read the register, but no longer write it.
        $secretary->releaseDate = new DateTimeImmutable('-1 day');
        self::assertFalse($member->isServingSecretary());
        self::assertTrue($member->isReleasedButUndischargedSecretary());

        $secretary->dischargeDate = new DateTimeImmutable('-1 day');
        self::assertFalse($member->isServingSecretary());
        self::assertFalse($member->isReleasedButUndischargedSecretary());
    }

    public function testOrphanMembershipOfAnOrganOnlyCountsWhileItRuns(): void
    {
        $member = $this->member('2000-01-01');

        $current = $this->organSeat(
            InstallationFunctions::Member,
            new DateTimeImmutable('-1 month'),
        );
        $member->getOrganInstallations()->add($current);
        self::assertTrue($member->isActive());
        self::assertCount(
            1,
            $member->getCurrentOrganInstallations(),
        );

        $current->dischargeDate = new DateTimeImmutable('-1 day');
        self::assertFalse($member->isActive());
    }

    public function testAnInactiveMemberIsOnlyInactiveForTheCountThatExcludesThem(): void
    {
        $member = $this->member('2000-01-01');
        $member->getOrganInstallations()->add($this->organSeat(
            InstallationFunctions::InactiveMember,
            new DateTimeImmutable('-1 month'),
        ));

        self::assertCount(
            1,
            $member->getCurrentOrganInstallations(),
        );
        self::assertCount(
            0,
            $member->getCurrentOrganInstallations(false),
        );
        self::assertFalse($member->isActive());
    }

    public function testAMembershipIsExpiredOnceItsDateHasPassed(): void
    {
        $expired = $this->member('2000-01-01');
        $expired->expiration = new DateTimeImmutable('-1 day');
        self::assertTrue($expired->isExpired());

        $running = $this->member('2000-01-01');
        $running->expiration = new DateTimeImmutable('+1 day');
        self::assertFalse($running->isExpired());
    }

    public function testTheSelfRoleNamesTheMember(): void
    {
        $member = $this->member('2000-01-01');

        self::assertSame(
            'ROLE_USER_' . $member->lidnr,
            $member->getSelfRole(),
        );
    }

    private function member(string $birth): Member
    {
        $member = new Member();
        $member->lidnr = 4242;
        $member->initials = 'T.';
        $member->firstName = 'Test';
        $member->middleName = '';
        $member->lastName = 'Lid';
        $member->birth = new DateTimeImmutable($birth);
        $member->expiration = new DateTimeImmutable('+1 year');

        return $member;
    }

    private function seat(
        BoardFunctions $function,
        DateTimeImmutable $installDate,
    ): BoardMember {
        $seat = new BoardMember();
        $seat->function = $function;
        $seat->installDate = $installDate;

        return $seat;
    }

    private function organSeat(
        InstallationFunctions $function,
        DateTimeImmutable $installDate,
    ): OrganMember {
        $seat = new OrganMember();
        $seat->function = $function;
        $seat->installDate = $installDate;

        return $seat;
    }
}
