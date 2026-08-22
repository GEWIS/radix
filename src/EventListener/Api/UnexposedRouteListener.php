<?php

declare(strict_types=1);

namespace App\EventListener\Api;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

use function in_array;
use function is_string;
use function str_starts_with;

/**
 * Below the firewall at 8, so a caller without a token is challenged rather than told the route is missing: every
 * address under `/api` answers 401 first.
 */
#[AsEventListener(
    event: KernelEvents::REQUEST,
    priority: 7,
)]
final readonly class UnexposedRouteListener
{
    private const array ROUTES = [
        '_api_errors',
        'api_genid',
        'api_validation_errors',
    ];

    private const string VALIDATION_ERRORS_PREFIX = '_api_validation_errors_';

    private const string NOT_EXPOSED_CONTROLLER = 'api_platform.action.not_exposed';

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $route = $request->attributes->get('_route');

        if (!is_string($route)) {
            return;
        }

        if (
            !in_array(
                $route,
                self::ROUTES,
                true,
            )
            && !str_starts_with(
                $route,
                self::VALIDATION_ERRORS_PREFIX,
            )
            && self::NOT_EXPOSED_CONTROLLER !== $request->attributes->get('_controller')
        ) {
            return;
        }

        throw new NotFoundHttpException(
            $request->getPathInfo() . ' does not exist.',
            new ResourceNotFoundException(),
        );
    }
}
