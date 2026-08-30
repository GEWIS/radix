<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Decision;

use App\Entity\Decision\Member;
use App\Repository\Decision\MemberRepository;
use App\Service\Decision\MemberInfoService;
use App\Tests\Integration\DatabaseTestCase;

final class MemberInfoServiceTest extends DatabaseTestCase
{
    private const int SERVING_CHAIR = 8025;
    private const int FORMER_CHAIR = 8054;

    public function testTheBoardSomebodyIsOnIsCurrent(): void
    {
        $memberships = $this->service()->getBoardMemberships($this->member(self::SERVING_CHAIR));

        self::assertCount(
            1,
            $memberships['current'],
        );
        self::assertSame(
            'Chair',
            $memberships['current'][0]['function'],
        );
        self::assertNull($memberships['current'][0]['releaseDate']);
    }

    public function testABoardSomebodyHasBeenReleasedFromIsHistorical(): void
    {
        $memberships = $this->service()->getBoardMemberships($this->member(self::FORMER_CHAIR));

        self::assertSame(
            [],
            $memberships['current'],
        );
        self::assertCount(
            1,
            $memberships['historical'],
        );
        self::assertNotNull($memberships['historical'][0]['releaseDate']);
    }

    public function testAMemberWhoWasNeverOnTheBoardHasNoInstallations(): void
    {
        $memberships = $this->service()->getBoardMemberships($this->member(2));

        self::assertSame(
            [],
            $memberships['current'],
        );
        self::assertSame(
            [],
            $memberships['historical'],
        );
    }

    private function service(): MemberInfoService
    {
        return self::getContainer()->get(MemberInfoService::class);
    }

    private function member(int $lidnr): Member
    {
        return self::getContainer()->get(MemberRepository::class)->find($lidnr)
            ?? self::fail('The seed is expected to contain such a member.');
    }
}
