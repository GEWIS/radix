<?php

declare(strict_types=1);

namespace App\Tests\Service\Database;

use App\Entity\Database\ActionLink;
use App\Entity\Database\Enums\MembershipTypes;
use App\Entity\Database\Member;
use App\Entity\Database\Membership;
use App\Entity\Database\PaymentLink;
use App\Entity\Database\RenewalLink;
use App\Repository\Database\ActionLinkRepository;
use App\Service\Database\ActionLinkService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * The service decides whether an emailed token still opens anything, and stores a token as a hash that is valid once
 * and briefly.
 */
#[CoversClass(ActionLinkService::class)]
class ActionLinkServiceTest extends TestCase
{
    public function testARenewalTokenThatIsNotTwoPartsCannotBeResolved(): void
    {
        $repository = $this->createMock(ActionLinkRepository::class);
        $repository->expects(self::never())->method('findRenewalBySelector');

        self::assertNull($this->service($repository)->resolveRenewal('not-a-token'));
    }

    public function testARenewalTokenForASelectorThatIsNotKnownIsRefused(): void
    {
        $repository = self::createStub(ActionLinkRepository::class);
        $repository->method('findRenewalBySelector')->willReturn(null);

        self::assertNull($this->service($repository)->resolveRenewal('selector.verifier'));
    }

    public function testARenewalLinkThatWasAlreadyClickedIsRefused(): void
    {
        $link = $this->renewalLink();
        $link->used = true;

        self::assertNull($this->service($this->repositoryFor($link))->resolveRenewal(self::plainToken($link)));
    }

    public function testARenewalLinkThatIsMoreThanThirtyDaysPastItsExpirationIsRefused(): void
    {
        $link = $this->renewalLink(
            new DateTimeImmutable()->modify('-31 days'),
        );

        self::assertTrue($link->linkExpired());
        self::assertNull($this->service($this->repositoryFor($link))->resolveRenewal(self::plainToken($link)));
    }

    public function testARenewalLinkWithAnotherVerifierIsRefused(): void
    {
        $link = $this->renewalLink();
        $forged = $link->selector . '.' . str_repeat(
            'a',
            96,
        );

        self::assertNull($this->service($this->repositoryFor($link))->resolveRenewal($forged));
    }

    public function testAValidRenewalTokenResolvesToItsLink(): void
    {
        $link = $this->renewalLink();

        self::assertSame(
            $link,
            $this->service($this->repositoryFor($link))->resolveRenewal(self::plainToken($link)),
        );
    }

    public function testAPaymentTokenThatIsNotTwoPartsCannotBeResolved(): void
    {
        $repository = $this->createMock(ActionLinkRepository::class);
        $repository->expects(self::never())->method('findPaymentBySelector');

        self::assertNull($this->service($repository)->resolvePayment('not-a-token'));
    }

    public function testAPaymentTokenForASelectorThatIsNotKnownIsRefused(): void
    {
        $repository = self::createStub(ActionLinkRepository::class);
        $repository->method('findPaymentBySelector')->willReturn(null);

        self::assertNull($this->service($repository)->resolvePayment('selector.verifier'));
    }

    public function testAPaymentTokenWithAnotherVerifierIsRefused(): void
    {
        $link = new PaymentLink();
        $forged = $link->selector . '.' . str_repeat(
            'b',
            96,
        );

        self::assertNull($this->service($this->paymentRepositoryFor($link))->resolvePayment($forged));
    }

    /**
     * A payment link may be followed again and does not expire: only the token is checked.
     */
    public function testAUsedPaymentTokenStillResolves(): void
    {
        $link = new PaymentLink();
        $link->used = true;

        self::assertSame(
            $link,
            $this->service($this->paymentRepositoryFor($link))->resolvePayment(self::plainToken($link)),
        );
    }

    public function testClaimingATokenSetsASingleUseHashWithAnExpiryAndStoresIt(): void
    {
        $link = $this->renewalLink();

        $repository = $this->createMock(ActionLinkRepository::class);
        $repository->expects(self::once())
            ->method('persist')
            ->with($link);

        $tempHash = $this->service($repository)->claim($link);

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/',
            $tempHash,
        );
        self::assertSame(
            $tempHash,
            $link->tempHash,
        );
        self::assertNotNull($link->tempHashExpiresAt);
        self::assertGreaterThan(
            new DateTimeImmutable(),
            $link->tempHashExpiresAt,
        );
        self::assertLessThan(
            new DateTimeImmutable()->modify('+10 minutes'),
            $link->tempHashExpiresAt,
        );
    }

    public function testAnUnknownTempHashDoesNotResolve(): void
    {
        $repository = self::createStub(ActionLinkRepository::class);
        $repository->method('findByTempHash')->willReturn(null);

        self::assertNull($this->service($repository)->findByTempHash('deadbeef'));
    }

    public function testAnExpiredTempHashDoesNotResolve(): void
    {
        $link = $this->renewalLink();
        $link->tempHash = 'deadbeef';
        $link->tempHashExpiresAt = new DateTimeImmutable()->modify('-1 second');

        self::assertNull($this->service($this->tempHashRepositoryFor($link))->findByTempHash('deadbeef'));
    }

    public function testAValidTempHashResolvesToItsLink(): void
    {
        $link = $this->renewalLink();
        $link->tempHash = 'deadbeef';
        $link->tempHashExpiresAt = new DateTimeImmutable()->modify('+1 minute');

        self::assertSame(
            $link,
            $this->service($this->tempHashRepositoryFor($link))->findByTempHash('deadbeef'),
        );
    }

    public function testConsumingATempHashClearsItAndStoresTheLink(): void
    {
        $link = $this->renewalLink();
        $link->tempHash = 'deadbeef';
        $link->tempHashExpiresAt = new DateTimeImmutable()->modify('+1 minute');

        $repository = $this->createMock(ActionLinkRepository::class);
        $repository->expects(self::once())
            ->method('persist')
            ->with($link);

        $this->service($repository)->consumeTempHash($link);

        self::assertNull($link->tempHash);
        self::assertNull($link->tempHashExpiresAt);
    }

    /**
     * Reissuing replaces the token in place, so the old one stops working and the repository returns the new one.
     */
    public function testReissuingRotatesTheTokenAndStoresTheLink(): void
    {
        $link = $this->renewalLink();
        $oldSelector = $link->selector;
        $oldToken = self::plainToken($link);

        $repository = $this->createMock(ActionLinkRepository::class);
        $repository->expects(self::once())
            ->method('persist')
            ->with($link);

        $newToken = $this->service($repository)->reissue($link);

        self::assertNotSame(
            $oldToken,
            $newToken,
        );
        self::assertNotSame(
            $oldSelector,
            $link->selector,
        );
        self::assertSame(
            $newToken,
            $link->plainToken,
        );

        $repository = self::createStub(ActionLinkRepository::class);
        $repository->method('findRenewalBySelector')
            ->willReturnCallback(
                static fn (string $selector): ?RenewalLink => $selector === $link->selector ? $link : null,
            );

        self::assertSame(
            $link,
            $this->service($repository)->resolveRenewal($newToken),
        );
        self::assertNull($this->service($repository)->resolveRenewal($oldToken));
    }

    private function service(ActionLinkRepository $repository): ActionLinkService
    {
        return new ActionLinkService($repository);
    }

    private function repositoryFor(RenewalLink $link): ActionLinkRepository
    {
        $repository = self::createStub(ActionLinkRepository::class);
        $repository->method('findRenewalBySelector')
            ->willReturnCallback(
                static fn (string $selector): ?RenewalLink => $selector === $link->selector ? $link : null,
            );

        return $repository;
    }

    private function paymentRepositoryFor(PaymentLink $link): ActionLinkRepository
    {
        $repository = self::createStub(ActionLinkRepository::class);
        $repository->method('findPaymentBySelector')
            ->willReturnCallback(
                static fn (string $selector): ?PaymentLink => $selector === $link->selector ? $link : null,
            );

        return $repository;
    }

    private function tempHashRepositoryFor(RenewalLink $link): ActionLinkRepository
    {
        $repository = self::createStub(ActionLinkRepository::class);
        $repository->method('findByTempHash')->willReturn($link);

        return $repository;
    }

    private function renewalLink(?DateTimeImmutable $currentExpiration = null): RenewalLink
    {
        $member = new Member();
        $member->setEmail('member@gewis.test');

        $currentExpiration ??= new DateTimeImmutable()->modify('+1 month');

        $member->addMembership(new Membership(
            $member,
            MembershipTypes::Ordinary,
            $currentExpiration->modify('-1 year'),
            $currentExpiration,
        ));

        return new RenewalLink(
            $member,
            $currentExpiration->modify('+1 year'),
        );
    }

    private static function plainToken(ActionLink $link): string
    {
        self::assertNotNull($link->plainToken);

        return $link->plainToken;
    }
}
