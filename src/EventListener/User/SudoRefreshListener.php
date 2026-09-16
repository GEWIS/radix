<?php

declare(strict_types=1);

namespace App\EventListener\User;

use App\Attribute\Application\ReadOnlySafe;
use App\Security\User\SudoArea;
use App\Security\User\SudoComponents;
use App\Security\User\SudoMode;
use InvalidArgumentException;
use ReflectionException;
use ReflectionMethod;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\UX\TwigComponent\ComponentFactory;

use function is_string;
use function str_ends_with;

/**
 * Extends a sudo grant for a guarded write.
 *
 * A grant expired a fixed half hour after the confirmation, regardless of activity, which interrupted long edits: the
 * expiration passed while the form was being filled in and the submission was then refused.
 *
 * Only a write extends it. A GET does not, and neither do the two edit-lock endpoints, because both are sent on a
 * timer rather than by user interaction.
 *
 * A live component is served outside the addresses {@see SudoArea} names, so a write to one extends a grant only
 * where the component requires the grant itself ({@see SudoComponents}). Every other component writes on pages the
 * grant has nothing to do with.
 *
 * Priority 6 places this below {@see SudoEnforcementListener} at 7, so a request without a grant has already been
 * refused before this runs.
 */
#[AsEventListener(
    event: RequestEvent::class,
    priority: 6,
)]
final readonly class SudoRefreshListener
{
    /** The route every live component request is matched to, for every component and action. */
    private const string LIVE_COMPONENT_ROUTE = 'ux_live_component';

    /** The action a live component specifies when it only re-renders, which invokes no method of the component. */
    private const string LIVE_COMPONENT_RENDER = 'get';

    /**
     * The heartbeat and the release of an edit lock. Both are POSTs on a guarded path, and neither is sent by user
     * interaction: the first is on an interval and the second on the element being removed.
     */
    private const array KEEPALIVE_SUFFIXES = [
        'edit_ping',
        'edit_release',
    ];

    public function __construct(
        private SudoMode $sudoMode,
        private SudoArea $area,
        private SudoComponents $sudoComponents,
        #[Autowire(service: 'ux.twig_component.component_factory')]
        private ComponentFactory $components,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // A safe method is not a write, so it does not extend the expiration.
        if ($request->isMethodSafe()) {
            return;
        }

        if (!$this->isGuardedWrite($request)) {
            return;
        }

        $this->sudoMode->touch();
    }

    private function isGuardedWrite(Request $request): bool
    {
        if (self::LIVE_COMPONENT_ROUTE === $request->attributes->get('_route')) {
            return $this->isLiveComponentWrite($request)
                && $this->sudoComponents->covers($request);
        }

        return $this->area->covers($request)
            && !$this->isKeepAlive($request);
    }

    private function isKeepAlive(Request $request): bool
    {
        $route = $request->attributes->get('_route');
        if (!is_string($route)) {
            return false;
        }

        foreach (self::KEEPALIVE_SUFFIXES as $suffix) {
            if (
                str_ends_with(
                    $route,
                    $suffix,
                )
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * A live component sends every action as a POST, so the method does not indicate whether the request writes. The
     * classification is the one {@see \App\EventListener\Application\MaintenanceListener} uses: a re-render invokes
     * no method, and an action that only changes what is displayed declares {@see ReadOnlySafe}.
     */
    private function isLiveComponentWrite(Request $request): bool
    {
        $component = $request->attributes->get('_live_component');
        $action = $request->attributes->get(
            '_live_action',
            self::LIVE_COMPONENT_RENDER,
        );
        if (
            !is_string($component)
            || !is_string($action)
            || self::LIVE_COMPONENT_RENDER === $action
        ) {
            return false;
        }

        try {
            $method = new ReflectionMethod(
                $this->components->metadataFor($component)->getClass(),
                $action,
            );
        } catch (InvalidArgumentException | ReflectionException) {
            return false;
        }

        return [] === $method->getAttributes(ReadOnlySafe::class);
    }
}
