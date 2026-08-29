<?php

declare(strict_types=1);

namespace App\EventListener\User;

use App\Security\User\Firewall;
use App\Security\User\SudoMode;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CredentialsInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Grants sudo to a session that has just signed in, so a password typed a moment ago is not asked for again.
 *
 * It keys on the credentials the passport carried rather than on the authenticator, because Symfony reports the
 * remember-me authenticator as interactive: neither `InteractiveLoginEvent` nor the authenticator itself tells a
 * fresh login apart from one restored from a cookie, which presents nothing to check.
 *
 * A login with a second factor pending arrives here twice; the first leaves a `TwoFactorToken`, which is not a login
 * yet, and the second carries the code as its credentials. Priority -64 runs after the session strategy has migrated
 * the session the grant is written to.
 */
#[AsEventListener(
    event: LoginSuccessEvent::class,
    priority: -64,
)]
final class SudoGrantOnLoginListener
{
    public function __construct(
        private readonly SudoMode $sudoMode,
    ) {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        // The API firewall is stateless, so there is no session to write a grant to.
        if (null === Firewall::tryFrom($event->getFirewallName())) {
            return;
        }

        if ($event->getAuthenticatedToken() instanceof TwoFactorTokenInterface) {
            return;
        }

        if (!$this->presentedCredentials($event)) {
            return;
        }

        $this->sudoMode->grant();
    }

    private function presentedCredentials(LoginSuccessEvent $event): bool
    {
        foreach ($event->getPassport()->getBadges() as $badge) {
            if (!$badge instanceof CredentialsInterface) {
                continue;
            }

            return true;
        }

        return false;
    }
}
