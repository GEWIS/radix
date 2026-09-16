<?php

declare(strict_types=1);

namespace App\EventListener\User;

use App\Security\User\SudoMode;
use App\Security\User\SudoVoter;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Event\SwitchUserEvent;
use Symfony\Component\Security\Http\SecurityEvents;

/**
 * Puts starting an impersonation behind sudo, so that the grant an administrator has can be used in place of the one
 * the account being impersonated would otherwise have to give. An administrator does not know the password of the
 * member they are impersonating, so without this the administration is unreachable from inside an impersonation.
 *
 * `SwitchUserListener` has already decided the administrator may switch by the time this runs, and has not yet put
 * the new token in storage, so {@see SudoMode} still reads the administrator here. Refusing with the `SUDO` attribute
 * sends them through {@see SudoAccessDeniedListener} to the confirmation form, which returns them to the address
 * they requested, impersonation parameter and all.
 *
 * The event that ends an impersonation has the original token rather than a `SwitchUserToken`, and is never refused:
 * an administrator whose grant expired while impersonating has to be able to get back to their own account.
 */
#[AsEventListener(event: SecurityEvents::SWITCH_USER)]
final class RequireSudoToImpersonateListener
{
    public function __construct(private readonly SudoMode $sudoMode)
    {
    }

    public function __invoke(SwitchUserEvent $event): void
    {
        if (!$event->getToken() instanceof SwitchUserToken) {
            return;
        }

        if ($this->sudoMode->isActive()) {
            return;
        }

        $exception = new AccessDeniedException('Impersonating somebody is behind sudo.');
        $exception->setAttributes(SudoVoter::ATTRIBUTE);

        throw $exception;
    }
}
