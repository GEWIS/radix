<?php

declare(strict_types=1);

namespace App\EventListener\Application;

use App\Attribute\Application\ReadOnlySafe;
use App\Entity\Application\Enums\AlertTypes;
use App\Entity\Application\Enums\MaintenanceStatus;
use App\Service\Application\MaintenanceStatusProvider;
use InvalidArgumentException;
use JsonException;
use ReflectionException;
use ReflectionMethod;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\TwigComponent\ComponentFactory;

use function file_get_contents;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function parse_url;
use function str_starts_with;

use const JSON_THROW_ON_ERROR;
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

    /** Where every live component request lands, whichever component and action it is for. */
    private const string LIVE_COMPONENT_ROUTE = 'ux_live_component';

    /**
     * The action a live component request carries when it is only re-rendering itself against changed props. It runs
     * no method of the component's own, so there is nothing for it to write.
     */
    private const string LIVE_COMPONENT_RENDER = 'get';

    /**
     * The action a live component request carries when it holds several actions the browser fired while an earlier
     * one was still in flight. What it may do is what those actions may do.
     */
    private const string LIVE_COMPONENT_BATCH = '_batch';

    public function __construct(
        private MaintenanceStatusProvider $maintenanceStatus,
        private Security $security,
        private TranslatorInterface $translator,
        #[Autowire(service: 'ux.twig_component.component_factory')]
        private ComponentFactory $components,
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
        // user checker. The sudo form is a page of the application's own rather than one the firewall answers, so
        // read-only has to say this as well or confirming a password is refused as a write.
        if ($this->isAuthenticationRoute($request)) {
            return;
        }

        if (MaintenanceStatus::Full === $window->getStatus()) {
            $event->setResponse($this->maintenancePage());

            return;
        }

        if ($this->isRead($request)) {
            return;
        }

        // Read-only: keep the user on the site and tell them the write was refused, rather than dropping them on the
        // maintenance page.
        $this->flashReadOnly($request);
        $event->setResponse(new RedirectResponse(
            $this->returnUrl($request),
            Response::HTTP_SEE_OTHER,
        ));
    }

    /**
     * Whether the request only reads. The method answers for everything a browser navigates to, and for everything a
     * form posts; a live component sends paging and filtering as a POST like it sends a write, so those say for
     * themselves with {@see ReadOnlySafe}.
     */
    private function isRead(Request $request): bool
    {
        return $request->isMethodSafe()
            || $this->isReadOnlySafeLiveAction($request);
    }

    private function isReadOnlySafeLiveAction(Request $request): bool
    {
        if (self::LIVE_COMPONENT_ROUTE !== $request->attributes->get('_route')) {
            return false;
        }

        $component = $request->attributes->get('_live_component');
        $action = $request->attributes->get(
            '_live_action',
            self::LIVE_COMPONENT_RENDER,
        );
        if (
            !is_string($component)
            || !is_string($action)
        ) {
            return false;
        }

        // A re-render runs no method of the component's own: it rehydrates the props it was sent and renders again.
        if (self::LIVE_COMPONENT_RENDER === $action) {
            return true;
        }

        try {
            $class = $this->components->metadataFor($component)->getClass();
        } catch (InvalidArgumentException) {
            return false;
        }

        if (self::LIVE_COMPONENT_BATCH !== $action) {
            return $this->isReadOnlySafe(
                $class,
                $action,
            );
        }

        $batched = $this->batchedActions($request);
        if ([] === $batched) {
            return false;
        }

        foreach ($batched as $name) {
            if (
                !$this->isReadOnlySafe(
                    $class,
                    $name,
                )
            ) {
                return false;
            }
        }

        return true;
    }

    private function isReadOnlySafe(
        string $class,
        string $action,
    ): bool {
        try {
            $method = new ReflectionMethod(
                $class,
                $action,
            );
        } catch (ReflectionException) {
            return false;
        }

        return [] !== $method->getAttributes(ReadOnlySafe::class);
    }

    /**
     * The actions a batched request holds, by name. An empty list for anything that cannot be read as one, so a body
     * this does not understand is refused rather than waved through.
     *
     * @return list<string>
     */
    private function batchedActions(Request $request): array
    {
        $data = $request->request->get('data');
        if (!is_string($data)) {
            return [];
        }

        try {
            $decoded = json_decode(
                $data,
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            return [];
        }

        if (
            !is_array($decoded)
            || !is_array($decoded['actions'] ?? null)
        ) {
            return [];
        }

        $names = [];
        foreach ($decoded['actions'] as $batched) {
            if (
                !is_array($batched)
                || !is_string($batched['name'] ?? null)
            ) {
                return [];
            }

            $names[] = $batched['name'];
        }

        return $names;
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
     * Where the refused write is sent back to, as a path rather than as an address of its own. What the visitor typed
     * only survives the proxy in `X-Forwarded-Proto` and `X-Forwarded-Host`, and a deployment that does not name that
     * proxy in `SYMFONY_TRUSTED_PROXIES` has neither: naming the host here would send somebody on HTTPS to `http://`
     * and leave them to be bounced back, and comparing the referer against it would never match its own site.
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
