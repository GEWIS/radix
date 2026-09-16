<?php

declare(strict_types=1);

namespace App\EventListener\Application;

use App\Attribute\Application\RendersOnSuccess;
use ReflectionException;
use ReflectionMethod;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

use function explode;
use function is_string;
use function str_contains;
use function str_starts_with;

/**
 * Sets 422 on a rejected submission, in place of the 200 a re-rendered form returned.
 *
 * Every controller here redirects after an accepted submission, so a rendered response to a submission means the
 * submission was rejected and the form is being displayed again with its errors. Turbo requires the status: it
 * refuses to render a 200 response to a form submission, which leaves the errors invisible.
 *
 * Applied here rather than at each of the seventy-odd render calls, for the reason
 * {@see \App\EventListener\User\SudoEnforcementListener} gives for matching paths rather than actions: a rule a
 * developer has to remember is eventually forgotten. The few actions that render on success declare
 * {@see RendersOnSuccess}.
 *
 * Priority -8 places this after Symfony's `ResponseListener` at 0, which sets the content type on a response that did
 * not set one. Before that listener, a rendered page has no content type to test.
 */
#[AsEventListener(
    event: ResponseEvent::class,
    priority: -8,
)]
final class InvalidSubmissionStatusListener
{
    /** Live components return a rendered 200 for every action, including the ones that write. */
    private const string LIVE_COMPONENT_ROUTE = 'ux_live_component';

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();

        // Only a submission can be rejected, and an accepted submission redirects.
        if (
            $request->isMethodSafe()
            || Response::HTTP_OK !== $response->getStatusCode()
        ) {
            return;
        }

        if (!$this->isRenderedPage($response)) {
            return;
        }

        if (
            self::LIVE_COMPONENT_ROUTE === $request->attributes->get('_route')
            || $this->rendersOnSuccess($request)
        ) {
            return;
        }

        $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * A rendered page, rather than a download, an API document or the empty body a webhook is acknowledged with. A
     * streamed or file response has no content here, which excludes the downloads without listing them.
     */
    private function isRenderedPage(Response $response): bool
    {
        $content = $response->getContent();

        return is_string($content)
            && '' !== $content
            && str_starts_with(
                $response->headers->get('Content-Type') ?? '',
                'text/html',
            );
    }

    private function rendersOnSuccess(Request $request): bool
    {
        $controller = $request->attributes->get('_controller');
        if (!is_string($controller)) {
            return false;
        }

        // An invokable controller is identified by its class alone; everything else appends `::method`.
        [
            $class, $method
        ] = str_contains(
            $controller,
            '::',
        )
            ? explode(
                '::',
                $controller,
                2,
            )
            : [
                $controller,
                '__invoke',
            ];

        try {
            $action = new ReflectionMethod(
                $class,
                $method,
            );
        } catch (ReflectionException) {
            return false;
        }

        return [] !== $action->getAttributes(RendersOnSuccess::class);
    }
}
