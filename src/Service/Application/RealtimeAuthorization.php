<?php

declare(strict_types=1);

namespace App\Service\Application;

use App\Entity\User\Enums\UserRoles;
use App\Service\User\SessionManager;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\Exception\RuntimeException as MercureRuntimeException;
use Symfony\Component\Security\Core\User\UserInterface;

use function sprintf;

/**
 * What the browser holding this request may hear over Mercure, and the single cookie that says so.
 */
final class RealtimeAuthorization
{
    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        private readonly SessionManager $sessionManager,
        #[Autowire(service: 'security.firewall.map')]
        private readonly FirewallMap $firewallMap,
        private readonly Authorization $authorization,
    ) {
    }

    /**
     * @return string[]
     */
    public function topics(): array
    {
        $topics = ['gewis/public'];

        // Someone who still has a second factor to clear has a user on the token but has not signed in yet, so they
        // get no more than a passer-by does. Anything else would push their notifications to whoever holds the
        // password.
        if (!$this->security->isGranted('IS_AUTHENTICATED_REMEMBERED')) {
            return $topics;
        }

        // What every member is shown at once, such as the infimum being rotated. A company user is signed in but is
        // not a member, so this sits behind the role rather than behind being signed in at all.
        if ($this->security->isGranted(UserRoles::User->value)) {
            $topics[] = 'gewis/members';
        }

        $user = $this->security->getUser();
        $request = $this->requestStack->getMainRequest();
        if (
            !$user instanceof UserInterface
            || null === $request
        ) {
            return $topics;
        }

        $firewall = $this->firewallMap->getFirewallConfig($request)?->getName();
        if (null === $firewall) {
            return $topics;
        }

        $topics[] = sprintf(
            'gewis/user/%s/%s',
            $firewall,
            $user->getUserIdentifier(),
        );

        $series = $this->sessionManager->currentSeries(
            $request,
            $firewall,
        );
        if (null !== $series) {
            $topics[] = sprintf(
                'gewis/session/%s/%s',
                $firewall,
                $series,
            );
        }

        return $topics;
    }

    /**
     * A browser holds one cookie, so this has to answer the same whatever page mints it; a page-scoped grant was
     * taken away again by the next tab to render a page without it. Templates, so one grant covers every id.
     *
     * @return string[]
     */
    public function grants(): array
    {
        $topics = $this->topics();

        if ($this->security->isGranted(UserRoles::Board->value)) {
            $topics[] = 'photo/album/{album}/cover';
            $topics[] = 'frontpage/page-images/{page}';
            $topics[] = 'frontpage/page-images/pending/{run}';
        }

        return $topics;
    }

    /**
     * @param string[] $topics
     */
    public function authorize(array $topics): void
    {
        $request = $this->requestStack->getMainRequest();
        if (null === $request) {
            return;
        }

        try {
            $this->authorization->setCookie(
                $request,
                $topics,
            );
        } catch (MercureRuntimeException) {
            // Thrown when the hub sits on a different host than the request (a hostless request under test, or a
            // misconfigured hub). Leave realtime off for this page rather than failing the whole render.
        }
    }
}
