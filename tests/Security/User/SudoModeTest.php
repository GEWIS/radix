<?php

declare(strict_types=1);

namespace App\Tests\Security\User;

use App\Security\User\Firewall;
use App\Tests\Support\BuildsSudoMode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class SudoModeTest extends TestCase
{
    use BuildsSudoMode;

    public function testAPasswordJustTypedUnlocksTheFirewallItWasTypedOn(): void
    {
        $session = $this->session();
        $tokenStorage = $this->tokenStorage('8025');

        $sudo = $this->sudoMode(
            $session,
            $tokenStorage,
            'main',
        );
        $sudo->grant();

        self::assertTrue($sudo->isActive());
    }

    public function testAGrantOnOneFirewallIsNotAGrantOnTheOther(): void
    {
        $session = $this->session();
        $tokenStorage = $this->tokenStorage('8025');
        $valkey = $this->valkey();

        $this->sudoMode(
            $session,
            $tokenStorage,
            'company',
            valkey: $valkey,
        )->grant();

        self::assertFalse($this->sudoMode(
            $session,
            $tokenStorage,
            'main',
            valkey: $valkey,
        )->isActive());
    }

    public function testAGrantOnOneSessionIsNotAGrantOnAnother(): void
    {
        $tokenStorage = $this->tokenStorage('8025');
        $valkey = $this->valkey();

        $this->sudoMode(
            $this->session('the-session-it-was-typed-on'),
            $tokenStorage,
            'main',
            valkey: $valkey,
        )->grant();

        self::assertFalse($this->sudoMode(
            $this->session('a-session-it-was-not'),
            $tokenStorage,
            'main',
            valkey: $valkey,
        )->isActive());
    }

    public function testAStatelessFirewallHoldsNoGrant(): void
    {
        $session = $this->session();
        $tokenStorage = $this->tokenStorage('8025');

        $sudo = $this->sudoMode(
            $session,
            $tokenStorage,
            'api',
        );
        $sudo->grant();

        self::assertFalse($sudo->isActive());
    }

    public function testAGrantDoesNotSurviveTheSessionBecomingSomebodyElses(): void
    {
        $session = $this->session();
        $tokenStorage = $this->tokenStorage('8025');

        $sudo = $this->sudoMode(
            $session,
            $tokenStorage,
            'main',
        );
        $sudo->grant();

        $tokenStorage->setToken(new UsernamePasswordToken(
            new InMemoryUser(
                '8001',
                null,
            ),
            'main',
        ));

        self::assertFalse($sudo->isActive());
    }

    public function testAGrantRunsOut(): void
    {
        $session = $this->session();
        $tokenStorage = $this->tokenStorage('8025');
        $clock = new MockClock();

        $sudo = $this->sudoMode(
            $session,
            $tokenStorage,
            'main',
            $clock,
        );
        $sudo->grant();

        $clock->modify('+1801 seconds');

        self::assertFalse($sudo->isActive());
    }

    public function testRevokingClearsOnlyTheFirewallItIsAskedFor(): void
    {
        $session = $this->session();
        $tokenStorage = $this->tokenStorage('8025');
        $valkey = $this->valkey();

        $main = $this->sudoMode(
            $session,
            $tokenStorage,
            'main',
            valkey: $valkey,
        );
        $company = $this->sudoMode(
            $session,
            $tokenStorage,
            'company',
            valkey: $valkey,
        );

        $main->grant();
        $company->grant();
        $company->revoke();

        self::assertTrue($main->isActive());
        self::assertFalse($company->isActive());
    }

    /** A second tab used to overwrite the session it had read, dropping a grant written in the meantime. */
    public function testAConcurrentSessionWriteDoesNotDropAGrant(): void
    {
        $session = $this->session();
        $tokenStorage = $this->tokenStorage('8025');

        $sudo = $this->sudoMode(
            $session,
            $tokenStorage,
            'main',
        );

        $readByTheOtherTab = $session->all();

        $sudo->grant();

        $session->replace($readByTheOtherTab);

        self::assertTrue($sudo->isActive());
    }

    /**
     * The account on the token while impersonating is the one being looked at, but the grant belongs to the
     * administrator who typed a password to get there.
     */
    public function testAnImpersonatorKeepsTheGrantTheyConfirmedThemselves(): void
    {
        $session = $this->session();
        $tokenStorage = $this->tokenStorage('8025');

        $sudo = $this->sudoMode(
            $session,
            $tokenStorage,
        );
        $sudo->grant();

        $administrator = $tokenStorage->getToken();
        self::assertNotNull($administrator);

        $tokenStorage->setToken(new SwitchUserToken(
            new InMemoryUser(
                '8001',
                null,
            ),
            'main',
            ['ROLE_USER'],
            $administrator,
        ));

        self::assertTrue($sudo->isActive());
    }

    public function testAnImpersonationOnASessionThatNeverConfirmedHoldsNoGrant(): void
    {
        $session = $this->session();
        $tokenStorage = $this->tokenStorage('8025');

        $sudo = $this->sudoMode(
            $session,
            $tokenStorage,
        );

        $administrator = $tokenStorage->getToken();
        self::assertNotNull($administrator);

        $tokenStorage->setToken(new SwitchUserToken(
            new InMemoryUser(
                '8001',
                null,
            ),
            'main',
            ['ROLE_USER'],
            $administrator,
        ));

        self::assertFalse($sudo->isActive());
    }

    /** Signing another device out has to drop its grant; the session it belongs to is not this one. */
    public function testRevokingAnotherSessionDropsItsGrant(): void
    {
        $tokenStorage = $this->tokenStorage('8025');
        $valkey = $this->valkey();

        $other = $this->session('the-other-device');
        $this->sudoMode(
            $other,
            $tokenStorage,
            valkey: $valkey,
        )->grant();

        $this->sudoMode(
            $this->session(),
            $tokenStorage,
            valkey: $valkey,
        )->revokeSession(
            Firewall::Main,
            'the-other-device',
        );

        self::assertFalse($this->sudoMode(
            $other,
            $tokenStorage,
            valkey: $valkey,
        )->isActive());
    }

    public function testAWriteKeepsTheGrantAliveBeyondTheIdleWindow(): void
    {
        $clock = new MockClock();
        $sudo = $this->sudoMode(
            $this->session(),
            $this->tokenStorage('8025'),
            clock: $clock,
        );
        $sudo->grant();

        // Working: something is handed in before the window runs out, over and over.
        for ($i = 0; $i < 4; ++$i) {
            $clock->sleep(self::IDLE_SECONDS - 60);
            $sudo->touch();
        }

        self::assertTrue($sudo->isActive());
    }

    public function testAGrantNothingPushesForwardRunsOut(): void
    {
        $clock = new MockClock();
        $sudo = $this->sudoMode(
            $this->session(),
            $this->tokenStorage('8025'),
            clock: $clock,
        );
        $sudo->grant();

        $clock->sleep(self::IDLE_SECONDS + 1);

        self::assertFalse($sudo->isActive());
    }

    public function testTheAbsoluteLimitEndsAGrantThatIsStillBeingUsed(): void
    {
        $clock = new MockClock();
        $sudo = $this->sudoMode(
            $this->session(),
            $this->tokenStorage('8025'),
            clock: $clock,
        );
        $sudo->grant();

        // Busy all day: the idle window never lapses, and the limit from the moment the password was typed does.
        for ($i = 0; $i < 20; ++$i) {
            $clock->sleep(self::IDLE_SECONDS - 60);
            $sudo->touch();
        }

        self::assertFalse($sudo->isActive());
    }

    public function testAGrantThatHasRunOutIsNotBroughtBackByAWrite(): void
    {
        $clock = new MockClock();
        $sudo = $this->sudoMode(
            $this->session(),
            $this->tokenStorage('8025'),
            clock: $clock,
        );
        $sudo->grant();

        $clock->sleep(self::IDLE_SECONDS + 1);
        $sudo->touch();

        self::assertFalse($sudo->isActive());
    }

    public function testAWriteWithoutAGrantLeavesNothingBehind(): void
    {
        $sudo = $this->sudoMode(
            $this->session(),
            $this->tokenStorage('8025'),
        );
        $sudo->touch();

        self::assertFalse($sudo->isActive());
    }
}
