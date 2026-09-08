<?php

declare(strict_types=1);

namespace App\EventListener\User;

use App\Entity\User\Enums\SecurityEventType;
use App\Service\User\SecurityEventLogger;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvents;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Exception\AccountStatusException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\RememberMeAuthenticator;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Component\Security\Http\Event\SwitchUserEvent;
use Throwable;

use function strrchr;
use function substr;

/**
 * Records what the firewalls do: who got in, who did not, who left, and who acted as somebody else.
 *
 * It listens rather than editing the authenticators, because the same handful of things happen on both interactive
 * firewalls and through several authenticators, and a listener sees all of them the same way. What the firewalls
 * cannot see from here, such as a session terminated after the fact, a password changed or a second factor turned
 * off, is recorded where it happens.
 */
final readonly class SecurityAuditListener
{
    public function __construct(
        private SecurityEventLogger $securityEvents,
        private TokenStorageInterface $tokenStorage,
        #[Autowire(service: 'security.firewall.map')]
        private FirewallMap $firewallMap,
    ) {
    }

    /**
     * A sign-in that still owes a second factor is recorded here all the same. Somebody whose password was accepted
     * and who then failed the second factor is exactly the sequence worth being able to read back, and recording
     * only completed sign-ins would leave the first half of it out.
     */
    #[AsEventListener(event: LoginSuccessEvent::class)]
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        // Resuming a session from a remembered device is not a sign-in, and is recorded as what it is by
        // {@see \App\Security\User\PersistentSignatureRememberMeHandler}.
        if ($event->getAuthenticator() instanceof RememberMeAuthenticator) {
            return;
        }

        $this->securityEvents->record(
            SecurityEventType::SignInSucceeded,
            $event->getUser()->getUserIdentifier(),
            $event->getFirewallName(),
            ['secondFactorPending' => $event->getAuthenticatedToken() instanceof TwoFactorTokenInterface],
            $event->getRequest(),
        );
    }

    #[AsEventListener(event: LoginFailureEvent::class)]
    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $exception = $event->getException();
        $unknownAccount = self::isUnknownAccount($exception);

        $this->securityEvents->record(
            self::failureType($exception),
            // Never the identifier that was typed when no account answers to it: somebody walking a list of
            // addresses would otherwise write that list into our database, and an address belonging to nobody here
            // is not ours to keep.
            $unknownAccount ? null : self::attemptedIdentifier($event),
            $event->getFirewallName(),
            [
                'reason' => self::reason($exception),
                'unknownAccount' => $unknownAccount,
            ],
            $event->getRequest(),
        );
    }

    #[AsEventListener(event: LogoutEvent::class)]
    public function onLogout(LogoutEvent $event): void
    {
        $token = $event->getToken();

        if (null === $token) {
            return;
        }

        $this->securityEvents->record(
            SecurityEventType::SignedOut,
            $token->getUserIdentifier(),
            $this->firewallFor($event->getRequest()),
            [],
            $event->getRequest(),
        );
    }

    /**
     * Both ends of an impersonation, filed against the member being acted as rather than the administrator doing it:
     * it is the member's account the thing was done to, and their history it has to show up in. The administrator is
     * named as the actor, which {@see SecurityEventLogger} resolves from the token on its own.
     */
    #[AsEventListener(event: SwitchUserEvent::class)]
    public function onSwitchUser(SwitchUserEvent $event): void
    {
        // Symfony hands this listener the freshly built `SwitchUserToken` when an impersonation starts, and the
        // original token that is about to replace it when one ends. Which of the two it is, is the only reliable
        // signal for the direction; the request parameter is named per firewall and says nothing on its own.
        $starting = $event->getToken() instanceof SwitchUserToken;

        $this->securityEvents->record(
            $starting
                ? SecurityEventType::ImpersonationStarted
                : SecurityEventType::ImpersonationStopped,
            $starting
                ? $event->getTargetUser()->getUserIdentifier()
                : ($this->impersonatedIdentifier() ?? $event->getTargetUser()->getUserIdentifier()),
            $this->firewallFor($event->getRequest()),
            [],
            $event->getRequest(),
        );
    }

    #[AsEventListener(event: TwoFactorAuthenticationEvents::SUCCESS)]
    public function onSecondFactorAccepted(TwoFactorAuthenticationEvent $event): void
    {
        $this->securityEvents->record(
            SecurityEventType::MfaChallengeSucceeded,
            $event->getToken()->getUserIdentifier(),
            $this->firewallFor($event->getRequest()),
            [],
            $event->getRequest(),
        );
    }

    #[AsEventListener(event: TwoFactorAuthenticationEvents::FAILURE)]
    public function onSecondFactorRejected(TwoFactorAuthenticationEvent $event): void
    {
        $this->securityEvents->record(
            SecurityEventType::MfaChallengeFailed,
            $event->getToken()->getUserIdentifier(),
            $this->firewallFor($event->getRequest()),
            [],
            $event->getRequest(),
        );
    }

    /**
     * Who is currently being impersonated, read while the impersonation is still standing. The event that ends one
     * names the administrator it returns to, not the member it is leaving.
     */
    private function impersonatedIdentifier(): ?string
    {
        $token = $this->tokenStorage->getToken();

        return $token instanceof SwitchUserToken
            ? $token->getUserIdentifier()
            : null;
    }

    private function firewallFor(Request $request): ?string
    {
        return $this->firewallMap->getFirewallConfig($request)?->getName();
    }

    private static function failureType(AuthenticationException $exception): SecurityEventType
    {
        return match (true) {
            $exception instanceof TooManyLoginAttemptsAuthenticationException => SecurityEventType::SignInThrottled,
            // Everything the `UserChecker` refuses arrives as one of these: a deleted member, an expired membership,
            // a company account outside its window, the site closed to all but administrators.
            $exception instanceof AccountStatusException => SecurityEventType::SignInRefused,
            default => SecurityEventType::SignInFailed,
        };
    }

    /**
     * A password given for an account that does not exist is reported as a wrong password: `hide_user_not_found` is
     * on, as it should be, so the difference only survives as the wrapped exception.
     */
    private static function isUnknownAccount(?Throwable $exception): bool
    {
        while (null !== $exception) {
            if ($exception instanceof UserNotFoundException) {
                return true;
            }

            $exception = $exception->getPrevious();
        }

        return false;
    }

    private static function attemptedIdentifier(LoginFailureEvent $event): ?string
    {
        $passport = $event->getPassport();

        if (
            null === $passport
            || !$passport->hasBadge(UserBadge::class)
        ) {
            return null;
        }

        $badge = $passport->getBadge(UserBadge::class);

        return $badge instanceof UserBadge
            ? $badge->getUserIdentifier()
            : null;
    }

    /**
     * The class of the failure rather than its message: the messages are translated, and several of them are
     * deliberately the same sentence so that a login form gives nothing away.
     */
    private static function reason(AuthenticationException $exception): string
    {
        $class = $exception::class;
        $short = strrchr(
            $class,
            '\\',
        );

        return false === $short
            ? $class
            : substr(
                $short,
                1,
            );
    }
}
