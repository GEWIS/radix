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

use function explode;
use function str_contains;
use function strlen;

#[CoversClass(RenewalLink::class)]
class RenewalLinkTest extends TestCase
{
    /**
     * The token is the whole credential: `/renew/{token}` is looked up by it, and it is the only thing that
     * separates an anonymous visitor from someone else's renewal. Only a hash of half of it is stored, so a link
     * cannot be reconstructed from the register: {@see RenewalLink::$plainToken} is set in the request that
     * generates the token and nowhere else.
     */
    public function testTheTokenIsUnguessableAndIsNotStoredWhole(): void
    {
        $first = $this->link();
        $second = $this->link();

        $token = $first->plainToken;
        self::assertNotNull($token);
        self::assertNotSame(
            $token,
            $second->plainToken,
        );
        self::assertGreaterThanOrEqual(
            128,
            strlen($token),
        );
        self::assertFalse(str_contains($token, '/'));
        self::assertStringNotContainsString(
            $token,
            $first->hashedToken,
        );
    }

    public function testTheVerifierItWasGeneratedWithIsAccepted(): void
    {
        $link = $this->link();
        $token = $link->plainToken;
        self::assertNotNull($token);

        [, $verifier
        ] = explode(
            '.',
            $token,
        );

        self::assertTrue($link->tokenMatches($verifier));
        self::assertFalse($link->tokenMatches('not the verifier'));
    }

    public function testGeneratingAgainInvalidatesThePreviousToken(): void
    {
        $link = $this->link();
        $before = $link->plainToken;
        self::assertNotNull($before);

        [, $verifier
        ] = explode(
            '.',
            $before,
        );

        $after = $link->rotateToken();

        self::assertNotSame(
            $before,
            $after,
        );
        self::assertFalse($link->tokenMatches($verifier));
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
