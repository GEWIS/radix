<?php

declare(strict_types=1);

namespace App\Tests\Entity\Database;

use App\Entity\Database\Enums\MembershipTypes;
use App\Entity\Database\Member;
use App\Entity\Database\Membership;
use App\Entity\Database\RenewalLink;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function str_contains;
use function strlen;

#[CoversClass(RenewalLink::class)]
class RenewalLinkTest extends TestCase
{
    /**
     * The token is the whole credential: it is what `/renew/{token}` is looked up by, and the only thing standing
     * between an anonymous visitor and someone else's renewal.
     */
    public function testCarriesATokenThatIsUnguessableAndFitsInAUrl(): void
    {
        $first = $this->link();
        $second = $this->link();

        self::assertNotSame(
            $first->token,
            $second->token,
        );
        self::assertGreaterThanOrEqual(
            128,
            strlen($first->token),
        );
        self::assertFalse(str_contains($first->token, '/'));
    }

    public function testStartsOutUnused(): void
    {
        $link = $this->link();

        self::assertFalse($link->used);

        $link->used = true;

        self::assertTrue($link->used);
    }

    /**
     * The link records what it would change, and there is nothing to renew towards a date that is already reached.
     */
    public function testRecordsTheExpirationItWouldMoveAndRefusesOneThatIsNotLater(): void
    {
        $member = $this->member('2026-07-01');
        $link = new RenewalLink(
            $member,
            new DateTimeImmutable('2027-07-01'),
        );

        self::assertSame(
            '2026-07-01',
            $link->currentExpiration->format('Y-m-d'),
        );
        self::assertSame(
            '2027-07-01',
            $link->newExpiration->format('Y-m-d'),
        );
        self::assertSame(
            $member,
            $link->member,
        );

        $this->expectException(InvalidArgumentException::class);

        new RenewalLink(
            $member,
            new DateTimeImmutable('2026-07-01'),
        );
    }

    /**
     * A renewal link outlives the membership by 30 days, so someone whose account has just locked can still use the
     * link they were sent.
     */
    #[DataProvider('expirationsAndWhetherTheLinkStillWorks')]
    public function testKeepsWorkingForThirtyDaysAfterTheMembershipRanOut(
        string $currentExpiration,
        bool $expired,
    ): void {
        $link = new RenewalLink(
            $this->member($currentExpiration),
            new DateTimeImmutable('+5 years'),
        );

        self::assertSame(
            $expired,
            $link->linkExpired(),
        );
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function expirationsAndWhetherTheLinkStillWorks(): array
    {
        return [
            'membership has not run out yet' => [
                '+2 months',
                false,
            ],
            'ran out yesterday' => [
                '-1 day',
                false,
            ],
            'ran out within the grace period' => [
                '-10 days',
                false,
            ],
            'ran out well past it' => [
                '-40 days',
                true,
            ],
        ];
    }

    private function link(): RenewalLink
    {
        return new RenewalLink(
            $this->member('2026-07-01'),
            new DateTimeImmutable('2027-07-01'),
        );
    }

    /**
     * A member whose membership ends on $expiration, which is what the link reads.
     */
    private function member(string $expiration): Member
    {
        $member = new Member();
        $member->addMembership(
            new Membership(
                $member,
                MembershipTypes::Ordinary,
                new DateTimeImmutable('-2 years'),
                new DateTimeImmutable($expiration),
            ),
        );

        return $member;
    }
}
