<?php

declare(strict_types=1);

namespace App\Tests\Security\User;

use App\Security\User\Firewall;
use App\Tests\Support\BuildsSudoMode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

use function time;

/**
 * The edges of a grant: what an expired one does, what a value that is not a grant does, and what signing another
 * device out does to it.
 */
final class SudoModeBranchesTest extends TestCase
{
    use BuildsSudoMode;

    public function testSigningAnotherDeviceOutDropsItsGrant(): void
    {
        $session = $this->session();
        $tokenStorage = $this->tokenStorage('8025');
        $valkey = $this->valkey();

        $sudo = $this->sudoMode(
            $session,
            $tokenStorage,
            valkey: $valkey,
        );
        $sudo->grant();

        $sudo->revokeSession(
            Firewall::Main,
            $session->getId(),
        );

        self::assertFalse($sudo->isActive());
    }

    public function testSigningOutWithoutASessionIdDoesNothing(): void
    {
        $session = $this->session();
        $tokenStorage = $this->tokenStorage('8025');

        $sudo = $this->sudoMode(
            $session,
            $tokenStorage,
        );
        $sudo->grant();

        $sudo->revokeSession(
            Firewall::Main,
            '',
        );

        self::assertTrue($sudo->isActive());
    }

    /**
     * This runs on every authenticated write, and would otherwise create a key for a user who never confirmed.
     */
    public function testExtendingWithoutAGrantWritesNothing(): void
    {
        $sudo = $this->sudoMode(
            $this->session(),
            $this->tokenStorage('8025'),
        );

        $sudo->touch();

        self::assertFalse($sudo->isActive());
    }

    public function testAnExpiredGrantIsNotRevived(): void
    {
        $clock = new MockClock();
        $sudo = $this->sudoMode(
            $this->session(),
            $this->tokenStorage('8025'),
            clock: $clock,
        );
        $sudo->grant();

        $clock->modify('+' . (self::IDLE_SECONDS + 1) . ' seconds');
        $sudo->touch();

        self::assertFalse($sudo->isActive());
    }

    public function testTheRemainingTimeCountsDown(): void
    {
        $clock = new MockClock();
        $sudo = $this->sudoMode(
            $this->session(),
            $this->tokenStorage('8025'),
            clock: $clock,
        );
        $sudo->grant();

        $clock->modify('+100 seconds');

        self::assertSame(
            self::IDLE_SECONDS - 100,
            $sudo->remainingSeconds(),
        );
    }

    public function testAValueThatIsNotAGrantIsNoGrant(): void
    {
        $session = $this->session();
        $tokenStorage = $this->tokenStorage('8025');
        $valkey = $this->valkey();

        $valkey->setex(
            $this->key($session),
            100,
            'not a grant',
        );

        self::assertSame(
            0,
            $this->sudoMode(
                $session,
                $tokenStorage,
                valkey: $valkey,
            )->remainingSeconds(),
        );
    }

    public function testAGrantWrittenForAnotherAccountIsNoGrant(): void
    {
        $session = $this->session();
        $tokenStorage = $this->tokenStorage('8025');
        $valkey = $this->valkey();

        $now = time();
        $valkey->setex(
            $this->key($session),
            100,
            $now . ':' . $now . ':8001',
        );

        self::assertSame(
            0,
            $this->sudoMode(
                $session,
                $tokenStorage,
                valkey: $valkey,
            )->remainingSeconds(),
        );
    }

    public function testAGrantWhoseTimestampsAreNotNumbersIsNoGrant(): void
    {
        $session = $this->session();
        $tokenStorage = $this->tokenStorage('8025');
        $valkey = $this->valkey();

        $valkey->setex(
            $this->key($session),
            100,
            'now:now:8025',
        );

        self::assertSame(
            0,
            $this->sudoMode(
                $session,
                $tokenStorage,
                valkey: $valkey,
            )->remainingSeconds(),
        );
    }

    public function testRevokingWithoutAKeyDoesNothing(): void
    {
        $this->expectNotToPerformAssertions();

        $this->sudoMode(
            $this->session(''),
            $this->tokenStorage('8025'),
        )->revoke();
    }

    private function key(SessionInterface $session): string
    {
        return 'gws_sudo_main_' . $session->getId();
    }
}
