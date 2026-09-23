<?php

declare(strict_types=1);

namespace App\Service\Application;

use App\Attribute\Application\ReadOnlySafe;
use App\Attribute\Application\WritesOnRender;
use InvalidArgumentException;
use JsonException;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\UX\TwigComponent\ComponentFactory;

use function class_exists;
use function is_array;
use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Whether a live component request writes.
 *
 * Every action is sent as a POST to one route, and a re-render is a GET where the props fit in the address, so the
 * method does not state whether the request writes. A re-render invokes no action of the component, unless the
 * component declares {@see WritesOnRender}, and an action that only changes what is displayed declares
 * {@see ReadOnlySafe}.
 *
 * Two listeners need that answer and have to agree on it:
 * {@see \App\EventListener\Application\MaintenanceListener} refuses a write while a read-only window is open, and
 * {@see \App\EventListener\User\SudoRefreshListener} extends a sudo grant for one. They were written separately and
 * had already diverged on batched requests.
 *
 * {@see writes()} returns null where the answer cannot be decided, because the safe answer differs between the two
 * callers: maintenance refuses a request it cannot classify, and a sudo grant is not extended for one. Each caller
 * compares against the answer it acts on rather than reading null as a decision.
 */
final readonly class LiveComponentAction
{
    /** The route every live component request is matched to, for every component and action. */
    private const string ROUTE = 'ux_live_component';

    /** The action specified when the request only re-renders against changed props. */
    private const string RENDER = 'get';

    /** The action specified when the request carries several actions the browser fired while one was in flight. */
    private const string BATCH = '_batch';

    public function __construct(
        #[Autowire(service: 'ux.twig_component.component_factory')]
        private ComponentFactory $components,
    ) {
    }

    public function isRequest(Request $request): bool
    {
        return self::ROUTE === $request->attributes->get('_route');
    }

    /**
     * Whether the request invokes an action that writes, or null where that cannot be decided. Null for a request
     * that is not a live component request at all.
     */
    public function writes(Request $request): ?bool
    {
        if (!$this->isRequest($request)) {
            return null;
        }

        $component = $request->attributes->get('_live_component');
        $action = $request->attributes->get(
            '_live_action',
            self::RENDER,
        );
        if (
            !is_string($component)
            || !is_string($action)
        ) {
            return null;
        }

        try {
            $class = $this->components->metadataFor($component)->getClass();
        } catch (InvalidArgumentException) {
            return null;
        }

        // The component applies what it was sent before it renders, so every request to it writes.
        if ($this->rendersAWrite($class)) {
            return true;
        }

        // A re-render runs no other method of the component's own: it rehydrates the props it was sent and renders
        // again.
        if (self::RENDER === $action) {
            return false;
        }

        if (self::BATCH !== $action) {
            return $this->actionWrites(
                $class,
                $action,
            );
        }

        $batched = $this->batchedActions($request);
        if ([] === $batched) {
            return null;
        }

        // What a batch may do is what the actions in it may do, so one write in it makes the request a write and one
        // action that cannot be classified leaves the whole request undecided.
        foreach ($batched as $name) {
            $writes = $this->actionWrites(
                $class,
                $name,
            );

            if (null === $writes) {
                return null;
            }

            if ($writes) {
                return true;
            }
        }

        return false;
    }

    private function rendersAWrite(string $class): bool
    {
        if (!class_exists($class)) {
            return false;
        }

        return [] !== new ReflectionClass($class)->getAttributes(WritesOnRender::class);
    }

    private function actionWrites(
        string $class,
        string $action,
    ): ?bool {
        try {
            $method = new ReflectionMethod(
                $class,
                $action,
            );
        } catch (ReflectionException) {
            return null;
        }

        return [] === $method->getAttributes(ReadOnlySafe::class);
    }

    /**
     * The actions a batched request contains, by name. An empty list for anything that cannot be read as one, so a
     * body this does not understand leaves the request undecided rather than classified.
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
}
