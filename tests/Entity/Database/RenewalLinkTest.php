<?php

declare(strict_types=1);

namespace App\Tests\Entity\Database;

use App\Entity\Database\Enums\MembershipTypes;
use App\Entity\Database\Member;
use App\Entity\Database\Membership;
use App\Entity\Database\RenewalLink;
use DateTime;
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
     * The token is the whole credential: it is what `/renew/{token}` is looked up by, and the only thing standing
     * between an anonymous visitor and someone else's renewal. What is kept is a hash of half of it, so a link cannot
     * be written out again from what the register holds -- {@see RenewalLink::getPlainToken()} answers only in the
     * request that minted it.
     */
    public function testCarriesATokenThatIsUnguessableAndIsNotStoredWhole(): void
    {
        $first = $this->link();
        $second = $this->link();

        $token = $first->getPlainToken();
        self::assertNotNull($token);
        self::assertNotSame(
            $token,
            $second->getPlainToken(),
        );
        self::assertGreaterThanOrEqual(
            128,
            strlen($token),
        );
        self::assertFalse(str_contains($token, '/'));
        self::assertStringNotContainsString(
            $token,
            $first->getHashedToken(),
        );
    }

    public function testRecognisesTheVerifierItWasMintedWith(): void
    {
        $link = $this->link();
        $token = $link->getPlainToken();
        self::assertNotNull($token);

        [, $verifier
        ] = explode(
            '.',
            $token,
        );

        self::assertTrue($link->tokenMatches($verifier));
        self::assertFalse($link->tokenMatches('not the verifier'));
    }

    public function testMintingAgainRetiresTheTokenThatWentBefore(): void
    {
        $link = $this->link();
        $before = $link->getPlainToken();
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

        self::assertFalse($link->isUsed());

        $link->setUsed(true);

        self::assertTrue($link->isUsed());
    }

    /**
     * The link records what it would change, and there is nothing to renew towards a date that is already reached.
     */
    public function testRecordsTheExpirationItWouldMoveAndRefusesOneThatIsNotLater(): void
    {
        $member = $this->member('2026-07-01');
        $link = new RenewalLink(
            $member,
            new DateTime('2027-07-01'),
        );

        self::assertSame(
            '2026-07-01',
            $link->getCurrentExpiration()->format('Y-m-d'),
        );
        self::assertSame(
            '2027-07-01',
            $link->getNewExpiration()->format('Y-m-d'),
        );
        self::assertSame(
            $member,
            $link->getMember(),
        );

        $this->expectException(InvalidArgumentException::class);

        new RenewalLink(
            $member,
            new DateTime('2026-07-01'),
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
            new DateTime('+5 years'),
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
            new DateTime('2027-07-01'),
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
                new DateTime('-2 years'),
                new DateTime($expiration),
            ),
        );

        return $member;
    }
}
