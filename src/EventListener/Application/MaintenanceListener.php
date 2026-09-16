<?php

declare(strict_types=1);

namespace App\EventListener\Application;

use App\Entity\Application\Enums\AlertTypes;
use App\Entity\Application\Enums\MaintenanceStatus;
use App\Service\Application\LiveComponentAction;
use App\Service\Application\MaintenanceStatusProvider;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Contracts\Translation\TranslatorInterface;

use function file_get_contents;
use function in_array;
use function is_string;
use function parse_url;
use function str_starts_with;

use const PHP_URL_HOST;
use const PHP_URL_PATH;
use const PHP_URL_QUERY;

/**
 * Serves the maintenance page (and, in read-only mode, blocks writes) while maintenance is in effect. Two levels:
 *  - the `MAINTENANCE` env var forces full maintenance for everyone, for infra-level work where even admins should stay
 *    out (e.g. migrations at startup);
 *  - otherwise the app-level {@see \App\Entity\Application\MaintenanceWindow} covering right now decides, with admins
 *    bypassing it so they can keep working and turn it off again.
 *
 * Runs after the firewall so the admin bypass can see the authenticated user. Under full maintenance a non-admin login
 * is refused earlier, by {@see \App\Security\User\UserChecker}, because the firewall handles the login before this
 * listener runs.
 */
#[AsEventListener(
    event: RequestEvent::class,
    priority: 6,
)]
final readonly class MaintenanceListener
{
    /**
     * Sign-in flow routes that stay reachable while maintenance is in effect, so a logged-out admin can authenticate
     * and lift it. Under full maintenance a non-admin who reaches them is still refused at the credential check by the
     * user checker.
     */
    private const array AUTHENTICATION_ROUTES = [
        'user_login',
        'user_mfa_challenge',
        'user_mfa_challenge_check',
        'user_sudo_confirm',
        'company_user_login',
        'company_user_mfa_challenge',
        'company_user_mfa_challenge_check',
        'company_user_sudo_confirm',
    ];

    /** The container healthcheck, which reports on maintenance rather than being subject to it. */
    private const string HEALTH_ROUTE = 'app_health';

    public function __construct(
        private MaintenanceStatusProvider $maintenanceStatus,
        private Security $security,
        private TranslatorInterface $translator,
        private LiveComponentAction $liveComponentAction,
        #[Autowire('%env(bool:MAINTENANCE)%')]
        private bool $maintenanceEnv,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if (self::HEALTH_ROUTE === $event->getRequest()->attributes->get('_route')) {
            return;
        }

        if ($this->maintenanceEnv) {
            $event->setResponse($this->maintenancePage());

            return;
        }

        $window = $this->maintenanceStatus->activeWindow();
        if (
            null === $window
            || $this->security->isGranted('ROLE_ADMIN')
        ) {
            return;
        }

        $request = $event->getRequest();

        // A logged-out admin must still reach the sign-in flow (login, MFA, sudo) to authenticate and lift
        // maintenance. Under full maintenance a non-admin who reaches it is refused at the credential check by the
        // user checker. The sudo form is a page of the application's own rather than one the firewall handles, so
        // read-only has to say this as well or confirming a password is refused as a write.
        if ($this->isAuthenticationRoute($request)) {
            return;
        }

        if (MaintenanceStatus::Full === $window->status) {
            $event->setResponse($this->maintenancePage());

            return;
        }

        if ($this->isRead($request)) {
            return;
        }

        // Read-only: keep the user on the site and show them the write was refused, rather than dropping them on the
        // maintenance page.
        $this->flashReadOnly($request);
        $event->setResponse(new RedirectResponse(
            $this->returnUrl($request),
            Response::HTTP_SEE_OTHER,
        ));
    }

    /**
     * Whether the request only reads. The method is enough for everything a browser navigates to, and for everything a
     * form posts; a live component sends paging and filtering as a POST like it sends a write, so those declare it
     * themselves with {@see \App\Attribute\Application\ReadOnlySafe}, which
     * {@see \App\Service\Application\LiveComponentAction} reads.
     */
    private function isRead(Request $request): bool
    {
        return $request->isMethodSafe()
            // Undecided counts as a write here, so a request this cannot classify is refused rather than allowed.
            || false === $this->liveComponentAction->writes($request);
    }

    private function flashReadOnly(Request $request): void
    {
        if (!$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        if (!$session instanceof FlashBagAwareSessionInterface) {
            return;
        }

        $session->getFlashBag()->add(
            AlertTypes::Warning->value,
            $this->translator->trans(
                'The website is temporarily read-only for maintenance, so your change was not saved.', // phpcs:ignore Generic.Files.LineLength.TooLong -- user-visible strings should not be split
            ),
        );
    }

    private function isAuthenticationRoute(Request $request): bool
    {
        return in_array(
            $request->attributes->get('_route'),
            self::AUTHENTICATION_ROUTES,
            true,
        );
    }

    /**
     * Where the refused write is sent back to, as a path rather than as an address of its own. What the visitor entered
     * only survives the proxy in `X-Forwarded-Proto` and `X-Forwarded-Host`, and a deployment that does not name that
     * proxy in `SYMFONY_TRUSTED_PROXIES` has neither: naming the host here would send a user on HTTPS to `http://` and
     * leave them to be redirected back, and comparing the referer against it would never match its own site.
     */
    private function returnUrl(Request $request): string
    {
        $referer = $request->headers->get('referer');
        if (null === $referer) {
            return '/';
        }

        if (
            $request->getHost() !== parse_url(
                $referer,
                PHP_URL_HOST,
            )
        ) {
            return '/';
        }

        $path = parse_url(
            $referer,
            PHP_URL_PATH,
        );
        if (
            !is_string($path)
            || !str_starts_with(
                $path,
                '/',
            )
            // A path of its own, never one that reads as `//host` and leaves the site.
            || str_starts_with(
                $path,
                '//',
            )
        ) {
            return '/';
        }

        $query = parse_url(
            $referer,
            PHP_URL_QUERY,
        );

        return is_string($query) && '' !== $query
            ? $path . '?' . $query
            : $path;
    }

    private function maintenancePage(): Response
    {
        $html = file_get_contents($this->projectDir . '/public/errors/maintenance.html');

        return new Response(
            false !== $html ? $html : 'The website is currently offline for maintenance. Please try again later.',
            Response::HTTP_SERVICE_UNAVAILABLE,
            ['Retry-After' => '3600'],
        );
    }
}
