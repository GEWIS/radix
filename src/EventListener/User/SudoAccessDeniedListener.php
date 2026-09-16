<?php

declare(strict_types=1);

namespace App\EventListener\User;

use App\Security\User\Firewall;
use App\Security\User\SudoVoter;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Throwable;

use function in_array;

/**
 * Intercepts an `AccessDeniedException` with the `SUDO` attribute and redirects to the sudo-confirmation route of the
 * firewall the request matches.
 *
 * Runs at priority 10, ahead of Symfony's per-firewall `ExceptionListener` (priority 1), which would otherwise redirect
 * an `IS_AUTHENTICATED_REMEMBERED` user to the login form. For sudo, a remember-me session must be allowed to step up
 * by re-typing the password instead. `stopPropagation()` ensures the built-in listener does not run for sudo denials;
 * other denials fall through unchanged.
 *
 * A request issued by script is answered differently, because `fetch` follows a redirect transparently and returns
 * the confirmation page to a caller expecting a result. That is why an upload reported success for a file that was
 * never stored.
 */
#[AsEventListener(
    event: ExceptionEvent::class,
    priority: 10,
)]
final class SudoAccessDeniedListener
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        #[Autowire(service: 'security.firewall.map')]
        private readonly FirewallMap $firewallMap,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $accessDenied = $this->findAccessDenied($event->getThrowable());
        if (null === $accessDenied) {
            return;
        }

        if (
            !in_array(
                SudoVoter::ATTRIBUTE,
                $accessDenied->getAttributes(),
                true,
            )
        ) {
            return;
        }

        $request = $event->getRequest();
        $firewall = $this->firewallMap->getFirewallConfig($request)?->getName();
        if (null === $firewall) {
            return;
        }

        $confirmRoute = Firewall::tryFrom($firewall)?->sudoConfirmRoute();
        if (null === $confirmRoute) {
            return;
        }

        // A status the caller can test, rather than a page it cannot distinguish from a result.
        if ($this->isBackgroundRequest($request)) {
            $event->setResponse(new JsonResponse(
                [
                    'error' => 'sudo_required',
                    'confirmUrl' => $this->urlGenerator->generate($confirmRoute),
                ],
                Response::HTTP_UNAUTHORIZED,
            ));
            $event->stopPropagation();

            return;
        }

        // The path is included for every method. A redirect cannot resubmit a form, so the submitted values are
        // still lost, but the user returns to the page instead of to the front page.
        $event->setResponse(new RedirectResponse(
            $this->urlGenerator->generate(
                $confirmRoute,
                ['next' => $request->getRequestUri()],
            ),
        ));
        $event->stopPropagation();
    }

    /**
     * Whether the request was issued by script rather than by a navigation. Live components and the hand-written
     * fetch controllers both set a header.
     */
    private function isBackgroundRequest(Request $request): bool
    {
        return $request->isXmlHttpRequest()
            || $request->headers->has('X-Live-Url');
    }

    private function findAccessDenied(?Throwable $throwable): ?AccessDeniedException
    {
        while (null !== $throwable) {
            if ($throwable instanceof AccessDeniedException) {
                return $throwable;
            }

            $throwable = $throwable->getPrevious();
        }

        return null;
    }
}
