<?php

declare(strict_types=1);

namespace App\Tests\Service\Checker;

use App\Entity\Database\Enums\MembershipProblems;
use App\Entity\Database\Enums\MembershipTypes;
use App\Entity\Database\Member;
use App\Entity\Database\Membership;
use App\Repository\Checker\MemberRepository;
use App\Service\Checker\Membership as MembershipService;
use App\ViewModel\Checker\MembershipError;
use DateTime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;

use function array_map;

#[CoversClass(MembershipService::class)]
class MembershipTest extends TestCase
{
    public function testAcceptsMembershipsThatFollowEachOther(): void
    {
        $member = $this->member([
            [
                '2018-07-01',
                '2019-07-01',
            ],
            [
                '2019-07-01',
                '2020-07-01',
            ],
            [
                '2020-07-01',
                '2021-07-01',
            ],
        ]);

        self::assertSame(
            [],
            $this->service()->problemsOf($member),
        );
    }

    public function testAcceptsAGapBetweenTwoMemberships(): void
    {
        $member = $this->member([
            [
                '2018-07-01',
                '2019-07-01',
            ],
            [
                '2024-07-01',
                '2025-07-01',
            ],
        ]);

        self::assertSame(
            [],
            $this->service()->problemsOf($member),
        );
    }

    public function testReportsTwoMembershipsCoveringTheSameDays(): void
    {
        $member = $this->member([
            [
                '2018-07-01',
                '2020-07-01',
            ],
            [
                '2019-07-01',
                '2021-07-01',
            ],
        ]);

        self::assertSame(
            [MembershipProblems::Overlapping],
            $this->problems($member),
        );
    }

    public function testReportsTwoMembershipsStartingOnTheSameDay(): void
    {
        $member = $this->member([
            [
                '2019-07-01',
                '2020-07-01',
            ],
            [
                '2019-07-01',
                '2020-07-01',
            ],
        ]);

        self::assertSame(
            [MembershipProblems::StartsOnTheSameDay],
            $this->problems($member),
        );
    }

    public function testReadsMembershipsInTheOrderTheyRun(): void
    {
        $member = $this->member([
            [
                '2020-07-01',
                '2021-07-01',
            ],
            [
                '2018-07-01',
                '2019-07-01',
            ],
        ]);

        self::assertSame(
            [],
            $this->service()->problemsOf($member),
        );
    }

    /**
     * @return MembershipProblems[]
     */
    private function problems(Member $member): array
    {
        return array_map(
            static fn (MembershipError $error): MembershipProblems => $error->getProblem(),
            $this->service()->problemsOf($member),
        );
    }

    private function service(): MembershipService
    {
        return new MembershipService(
            self::createStub(MemberRepository::class),
            self::createStub(MailerInterface::class),
            'from@example.org',
            'From',
            'to@example.org',
            'To',
        );
    }

    /**
     * @param array<array{0: string, 1: string}> $memberships
     */
    private function member(array $memberships): Member
    {
        $member = new Member();

        foreach ($memberships as [$start, $end]) {
            $member->addMembership(new Membership(
                $member,
                MembershipTypes::Ordinary,
                new DateTime($start),
                new DateTime($end),
            ));
        }

        return $member;
    }
}
