<?php

declare(strict_types=1);

namespace App\Tests\Security\Database;

use App\Security\Database\RegisterNetworkChecker;
use Override;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Where the register may be read and changed from. This decides whether the office somebody holds counts at all, so
 * what an unconfigured list and an unestablished address mean are pinned here.
 */
final class RegisterNetworkCheckerTest extends TestCase
{
    private const array RANGES = [
        '131.155.68.0/24',
        '10.0.0.0/8',
    ];

    public function testAnAddressInsideAConfiguredRangeMayReachTheRegister(): void
    {
        self::assertTrue($this->checker()->matches('131.155.68.69'));
        self::assertTrue($this->checker()->matches('10.11.12.13'));
    }

    public function testAnAddressOutsideEveryRangeMayNot(): void
    {
        self::assertFalse($this->checker()->matches('8.8.8.8'));
        // Neighbouring the /24 on either side.
        self::assertFalse($this->checker()->matches('131.155.67.255'));
        self::assertFalse($this->checker()->matches('131.155.69.1'));
    }

    /** Refusing is the safe answer here, unlike the campus check, where it would merely grant nothing extra. */
    public function testAnAddressThatCannotBeEstablishedMayNot(): void
    {
        self::assertFalse($this->checker()->matches(null));
        self::assertFalse($this->checker()->matches(''));
        self::assertFalse($this->checker()->matches('not an address'));
    }

    /** A proxy's address arrives when the forwarded chain is missing, and it stands on the opened networks. */
    public function testATrustedProxyMayNeverReachTheRegister(): void
    {
        Request::setTrustedProxies(
            ['131.155.68.202'],
            Request::HEADER_X_FORWARDED_FOR,
        );

        self::assertFalse($this->checker()->matches('131.155.68.202'));
        self::assertTrue($this->checker()->matches('131.155.68.69'));
    }

    /** What every checkout and the test suite run with. */
    public function testAnUnconfiguredListRestrictsNothing(): void
    {
        $checker = new RegisterNetworkChecker(
            new RequestStack(),
            [],
        );

        self::assertFalse($checker->isRestricted());
        self::assertTrue($checker->matches('8.8.8.8'));
        self::assertTrue($checker->matches(null));
        self::assertTrue($checker->allowsCurrentRequest());
    }

    public function testAConfiguredListRestricts(): void
    {
        self::assertTrue($this->checker()->isRestricted());
    }

    public function testTheCurrentRequestDecidesWhenThereIsOne(): void
    {
        self::assertTrue($this->checkerFor('131.155.68.69')->allowsCurrentRequest());
        self::assertFalse($this->checkerFor('8.8.8.8')->allowsCurrentRequest());
    }

    /** A console command, a Messenger worker and the scheduler have no request, and browse nothing. */
    public function testWithoutARequestTheRestrictionDoesNotApply(): void
    {
        self::assertTrue($this->checker()->allowsCurrentRequest());
    }

    #[Override]
    protected function tearDown(): void
    {
        Request::setTrustedProxies(
            [],
            0,
        );
    }

    private function checker(): RegisterNetworkChecker
    {
        return new RegisterNetworkChecker(
            new RequestStack(),
            self::RANGES,
        );
    }

    private function checkerFor(string $clientIp): RegisterNetworkChecker
    {
        $stack = new RequestStack();
        $stack->push(Request::create(
            '/en/admin/members',
            server: ['REMOTE_ADDR' => $clientIp],
        ));

        return new RegisterNetworkChecker(
            $stack,
            self::RANGES,
        );
    }
}
