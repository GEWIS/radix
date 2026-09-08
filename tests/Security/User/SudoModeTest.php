<?php

declare(strict_types=1);

namespace App\Tests\Security\User;

use App\Security\User\Firewall;
use App\Tests\Support\BuildsSudoMode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
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

    /** Signing another device out has to take its grant with it; the session it belongs to is not this one. */
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
}
