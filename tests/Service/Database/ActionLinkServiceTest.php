<?php

declare(strict_types=1);

namespace App\Tests\Service\Database;

use App\Entity\Database\Enums\MembershipTypes;
use App\Entity\Database\Member;
use App\Entity\Database\Membership;
use App\Entity\Database\RenewalLink;
use App\Repository\Database\ActionLinkRepository;
use App\Service\Database\ActionLinkService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function explode;

#[CoversClass(ActionLinkService::class)]
class ActionLinkServiceTest extends TestCase
{
    public function testResolvesTheLinkItsOwnTokenNames(): void
    {
        $link = $this->renewalLink();
        $token = (string) $link->plainToken;

        self::assertSame(
            $link,
            $this->service($link)->resolveRenewal($token),
        );
    }

    public function testRefusesAVerifierItDidNotMint(): void
    {
        $link = $this->renewalLink();
        [$selector] = explode(
            '.',
            (string) $link->plainToken,
        );

        self::assertNull(
            $this->service($link)->resolveRenewal($selector . '.0123456789abcdef'),
        );
    }

    public function testRefusesATokenThatIsNotSplitInTwo(): void
    {
        $link = $this->renewalLink();

        self::assertNull($this->service($link)->resolveRenewal('no-verifier-here'));
    }

    public function testRefusesALinkThatWasFollowedAlready(): void
    {
        $link = $this->renewalLink();
        $link->used = true;

        self::assertNull(
            $this->service($link)->resolveRenewal((string) $link->plainToken),
        );
    }

    public function testRefusesAClaimHashThatIsPastItsThreeMinutes(): void
    {
        $link = $this->renewalLink();
        $service = $this->service($link);

        $tempHash = $service->claim($link);
        self::assertSame(
            $link,
            $service->findByTempHash($tempHash),
        );

        $link->tempHashExpiresAt = new DateTimeImmutable('-1 second');

        self::assertNull($service->findByTempHash($tempHash));
    }

    private function service(RenewalLink $link): ActionLinkService
    {
        $repository = self::createStub(ActionLinkRepository::class);
        $repository->method('findRenewalBySelector')->willReturn($link);
        $repository->method('findByTempHash')->willReturn($link);

        return new ActionLinkService($repository);
    }

    private function renewalLink(): RenewalLink
    {
        $member = new Member();
        $member->addMembership(
            new Membership(
                $member,
                MembershipTypes::Ordinary,
                new DateTimeImmutable('-2 years'),
                new DateTimeImmutable('+1 month'),
            ),
        );

        return new RenewalLink(
            $member,
            new DateTimeImmutable('+1 year'),
        );
    }
}
