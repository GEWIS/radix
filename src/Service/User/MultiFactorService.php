<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Entity\User\CompanyUser;
use App\Entity\User\Enums\SecurityEventType;
use App\Entity\User\User;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Turning multi-factor authentication on and off for an account, and taking it off entirely — which is what the board
 * does for somebody who has lost their second factor and cannot get back in.
 *
 * The secret, the backup codes and the forced re-login go together in one commit: an account left holding a secret it
 * can no longer answer, or one that is not made to sign in again, is half changed and either locked out or still
 * trusting a factor that is gone.
 */
final readonly class MultiFactorService
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private EntityManagerInterface $entityManager,
        private SecurityEventLogger $securityEvents,
    ) {
    }

    /**
     * Turn multi-factor authentication on, once the first code has been verified. Every session opened before now is
     * made to sign in again, so none of them stays behind on single-factor.
     */
    public function enable(
        User|CompanyUser $account,
        string $secret,
    ): void {
        $account->setTotpSecret($secret);
        $account->setForceReloginAt(new DateTime());

        $this->entityManager->flush();
    }

    /**
     * Turn it off again, at the account holder's own request.
     */
    public function disable(User|CompanyUser $account): void
    {
        $account->setTotpSecret(null);
        $account->setBackupCodeSlots(null);

        $this->entityManager->flush();
    }

    /**
     * Make every session sign in again. Done before new backup codes are handed out, so a session that is still
     * holding the old ones cannot go on using them.
     */
    public function forceRelogin(User|CompanyUser $account): void
    {
        $account->setForceReloginAt(new DateTime());

        $this->entityManager->flush();
    }

    public function reset(User|CompanyUser $account): void
    {
        $account->setTotpSecret(null);
        $account->setBackupCodeSlots(null);
        $account->setForceReloginAt(new DateTime());

        $this->entityManager->flush();

        // Only the board reaches this, on behalf of somebody who cannot get in; taking one's own second factor off is
        // {@see self::disable()} and is recorded by the controller that offers it. The administrator who did this is
        // filled in as the actor.
        $this->securityEvents->record(
            SecurityEventType::MfaDisabled,
            $account->getUserIdentifier(),
            $account instanceof CompanyUser ? 'company' : 'main',
            ['by' => 'administrator'],
        );
    }
}
