<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\User;

use App\Entity\User\Enums\SecurityEventType;
use App\Entity\User\SecurityLog;
use App\Entity\User\User;
use App\Repository\User\SecurityLogRepository;
use App\Service\User\MultiFactorService;
use App\Tests\Integration\DatabaseTestCase;

/**
 * Turning the second factor on or off is more than setting a column: every session opened before it has to sign in
 * again, because a session left on single-factor defeats the factor.
 */
final class MultiFactorServiceTest extends DatabaseTestCase
{
    public function testEnablingStoresTheSecretAndForcesAFreshSignIn(): void
    {
        $user = $this->user();
        self::assertNull($user->forceReloginAt);

        $this->service()->enable(
            $user,
            'JBSWY3DPEHPK3PXP',
        );

        self::assertSame(
            'JBSWY3DPEHPK3PXP',
            $user->totpSecret,
        );
        self::assertNotNull($user->forceReloginAt);

        $this->entityManager->clear();
        $stored = $this->user();
        self::assertSame(
            'JBSWY3DPEHPK3PXP',
            $stored->totpSecret,
        );
    }

    public function testDisablingClearsTheSecretAndTheBackupCodes(): void
    {
        $user = $this->user();
        $this->service()->enable(
            $user,
            'JBSWY3DPEHPK3PXP',
        );
        $user->setBackupCodeSlots([['code' => 'one', 'used' => false]]);

        $this->service()->disable($user);

        self::assertNull($user->totpSecret);
        self::assertNull($user->getBackupCodeSlots());
    }

    public function testForcingASignInLeavesTheSecretInPlace(): void
    {
        $user = $this->user();
        $this->service()->enable(
            $user,
            'JBSWY3DPEHPK3PXP',
        );
        $user->forceReloginAt = null;

        $this->service()->forceRelogin($user);

        self::assertNotNull($user->forceReloginAt);
        self::assertSame(
            'JBSWY3DPEHPK3PXP',
            $user->totpSecret,
        );
    }

    /**
     * A reset is what the board does for somebody who cannot get in at all, so it is recorded as the administrator's
     * action rather than as the owner turning their own factor off.
     */
    public function testResettingRecordsThatAnAdministratorDidIt(): void
    {
        $user = $this->user();
        $this->service()->enable(
            $user,
            'JBSWY3DPEHPK3PXP',
        );
        $user->setBackupCodeSlots([['code' => 'one', 'used' => false]]);
        $user->forceReloginAt = null;

        $this->service()->reset($user);

        self::assertNull($user->totpSecret);
        self::assertNull($user->getBackupCodeSlots());
        self::assertNotNull($user->forceReloginAt);

        $this->entityManager->clear();
        $log = self::getContainer()->get(SecurityLogRepository::class)->findOneBy([
            'event' => SecurityEventType::MfaDisabled,
            'userIdentifier' => (string) $this->user()->lidnr,
        ]);
        self::assertInstanceOf(
            SecurityLog::class,
            $log,
        );
        self::assertSame(
            'administrator',
            $log->detail['by'],
        );
    }

    private function service(): MultiFactorService
    {
        return self::getContainer()->get(MultiFactorService::class);
    }

    private function user(): User
    {
        $user = $this->entityManager->getRepository(User::class)->find(8000);
        self::assertInstanceOf(
            User::class,
            $user,
            'The seed is expected to contain the administrator.',
        );

        return $user;
    }
}
