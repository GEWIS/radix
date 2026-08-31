<?php

declare(strict_types=1);

namespace App\EventListener\User;

use App\Repository\User\SessionRepository;
use App\Security\User\CredentialsSignature;
use App\Security\User\Firewall;
use App\Security\User\HandlerRegistry;
use App\Security\User\UserAgentParser;
use App\Service\Application\RealtimeAuthorization;
use App\Service\User\KnownDeviceRegistry;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function assert;

/**
 * For every authenticated request:
 *  1. If the remember-me cookie is missing OR references a series not on file, force a logout (`managed_sessions` row
 *     removed if present, PHP session invalidated, redirect to log in). Our policy is that a remember-me cookie is
 *     always issued at login, so absence is anomalous and warrants a hard reset rather than silent recovery. Otherwise,
 *     a stolen PHP session cookie could self-upgrade to a persistent remember-me cookie.
 *  2. Otherwise (cookie present + row found) rebind `phpSessionId` if the stored value has drifted from the current
 *     request's session ID (happens for example when the SessionAuthenticationStrategy migrates the ID after
 *     rememberme-resumed login), and bump `lastUsedAt` (throttled), so the security UI's "Last seen" column tracks real
 *     activity rather than just cookie-rotation moments.
 *
 * Unauthenticated requests are left alone. Mid-2FA requests are also left alone, since scheb's `TwoFactorToken` does
 * not grant `IS_AUTHENTICATED_REMEMBERED` and the remember-me cookie is not issued until 2FA completes.
 *
 * The lookup pivot here is the cookie's **series**, not the PHP session ID. Series is the authoritative identifier (it
 * does not change across token rotations or session migrations), whereas `phpSessionId` is just the pointer we keep so
 * "log out this device" can destroy the matching Valkey entry directly.
 */
#[AsEventListener(event: RequestEvent::class)]
final class StaleSessionGuardListener
{
    /**
     * Do not write lastUsedAt more than once per this many seconds to spare the DB.
     */
    private const int LAST_USED_THROTTLE_SECONDS = 180;

    public function __construct(
        private readonly SessionRepository $repository,
        private readonly HandlerRegistry $registry,
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private readonly EntityManagerInterface $entityManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
        private readonly Security $security,
        #[Autowire(service: 'security.firewall.map')]
        private readonly FirewallMap $firewallMap,
        private readonly UserAgentParser $userAgentParser,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly KnownDeviceRegistry $knownDevices,
        private readonly CredentialsSignature $credentials,
        private readonly RealtimeAuthorization $realtime,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if (!$this->security->isGranted('IS_AUTHENTICATED_REMEMBERED')) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->hasPreviousSession()) {
            return;
        }

        $phpSessionId = $request->getSession()->getId();
        if ('' === $phpSessionId) {
            return;
        }

        $firewall = $this->firewallMap->getFirewallConfig($request)?->getName();
        if (null === $firewall) {
            return;
        }

        $handler = $this->registry->get($firewall);
        if (null === $handler) {
            return;
        }

        $series = $handler->getSeriesFromCookie($request);

        // Missing cookie on an authenticated request -> tear down.
        if (null === $series) {
            $orphan = $this->repository->findOneByPhpSessionId($phpSessionId);
            if (null !== $orphan) {
                $this->entityManager->remove($orphan);
                $this->entityManager->flush();
            }

            $this->forceLogout(
                $firewall,
                $event,
            );

            return;
        }

        // Cookie present -> look the row up by its series (the authoritative identifier).
        $managedSession = $this->repository->findOneBySeries($series);

        // Cookie references a series with no row -> also anomalous, tear down.
        if (null === $managedSession) {
            $this->forceLogout(
                $firewall,
                $event,
            );

            return;
        }

        // Cross-firewall token replay attempt (a cookie from one firewall on another firewall's URL). Refuse -> leave
        // the data alone and let the request fall through unauthenticated.
        if ($managedSession->getFirewallName() !== $firewall) {
            return;
        }

        // The remember-me handler makes the same comparison, but only on a request that hands it the cookie, which a
        // device with a live PHP session never makes: Valkey pushes that session's expiry forward on every request,
        // so without this a password reset would leave whoever it was meant to shut out signed in.
        $user = $this->security->getUser();
        if (
            null !== $user
            && !$this->credentials->matches(
                $managedSession->getSignaturePropertiesHash(),
                $user,
            )
        ) {
            $this->logger?->info(
                'Account credentials changed since this session was opened -> tearing down session.',
                [
                    'series' => $series,
                    'user' => $managedSession->getUserIdentifier(),
                    'firewall' => $firewall,
                ],
            );
            $this->entityManager->remove($managedSession);
            $this->entityManager->flush();
            $this->forceLogout(
                $firewall,
                $event,
            );

            return;
        }

        // Fingerprint check: compare the current request's browser+OS family (names sans version) against what was
        // stored at login. Versions are intentionally ignored so legit updates (Firefox 124 -> 140) do not trip the
        // gate. A mismatch on either side suggests the cookie pair has been replayed from a different device -> tear
        // down.
        $currentMeta = $this->userAgentParser->parseRequest($request);
        $storedBrowser = UserAgentParser::family($managedSession->getBrowser());
        $currentBrowser = UserAgentParser::family($currentMeta['browser']);
        $storedOs = UserAgentParser::family($managedSession->getOperatingSystem());
        $currentOs = UserAgentParser::family($currentMeta['operatingSystem']);

        $browserMismatch = null !== $storedBrowser && null !== $currentBrowser && $storedBrowser !== $currentBrowser;
        $osMismatch = null !== $storedOs && null !== $currentOs && $storedOs !== $currentOs;

        if (
            $browserMismatch
            || $osMismatch
        ) {
            $this->logger?->warning(
                'User-agent family mismatch -> tearing down session.',
                [
                    'series' => $series,
                    'user' => $managedSession->getUserIdentifier(),
                    'firewall' => $firewall,
                    'stored_browser' => $managedSession->getBrowser(),
                    'current_browser' => $currentMeta['browser'],
                    'stored_os' => $managedSession->getOperatingSystem(),
                    'current_os' => $currentMeta['operatingSystem'],
                ],
            );
            $this->entityManager->remove($managedSession);
            $this->entityManager->flush();
            $this->forceLogout(
                $firewall,
                $event,
            );

            return;
        }

        $changed = false;

        // Rebind phpSessionId if it has drifted (Symfony's session migration on a rememberme-resumed login changes the
        // ID between createSession and the next request).
        if ($managedSession->getPhpSessionId() !== $phpSessionId) {
            $managedSession->setPhpSessionId($phpSessionId);
            $changed = true;
        }

        // Throttled lastUsedAt bump so the security UI's "Last seen" reflects real activity rather than only the
        // moments of token rotation.
        $now = new DateTimeImmutable();
        $staleAfter = $now->modify('-' . self::LAST_USED_THROTTLE_SECONDS . ' seconds');
        $inUse = $managedSession->getLastUsedAt() < $staleAfter;
        if ($inUse) {
            $managedSession->setLastUsedAt($now);
            $changed = true;
        }

        if ($changed) {
            $this->entityManager->flush();
        }

        if (!$inUse) {
            return;
        }

        // Somebody working in a device they signed in from months ago is the same reason to keep it recognised as
        // signing in from it again would be, and this is the only place that sees them do it. Behind the same throttle
        // as the bump above, so it costs one lookup per three minutes of activity.
        $this->knownDevices->refresh(
            $managedSession->getUserIdentifier(),
            $firewall,
            $request,
        );
    }

    private function forceLogout(
        string $firewall,
        RequestEvent $event,
    ): void {
        // Clear the in-memory security token BEFORE invalidating the session. If we skip this, Symfony's
        // ContextListener writes the still-active token back to the freshly-created PHP session on kernel.response, and
        // the next request is silently re-authenticated -> this listener fires again -> infinite redirect loop.
        $this->tokenStorage->setToken(null);
        $event->getRequest()->getSession()->invalidate();

        $this->realtime->revoke();

        $loginRoute = Firewall::tryFrom($firewall)?->loginRoute();
        if (null === $loginRoute) {
            return;
        }

        $session = $event->getRequest()->getSession();
        assert($session instanceof Session);
        $session->getFlashBag()->add(
            'warning',
            $this->translator->trans('Your session was ended for security reasons. Please sign in again.'),
        );

        $event->setResponse(new RedirectResponse(
            $this->urlGenerator->generate($loginRoute),
        ));
    }
}
