<?php

declare(strict_types=1);

namespace App\Security\User;

use App\Attribute\User\Replayable;
use App\Util\Application\ControllerAttribute;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\RouterInterface;

/**
 * The actions whose refused write is re-run once the grant is given: the ones that declare {@see Replayable}.
 *
 * Here rather than in either caller, because two of them need the same answer and have to agree on it:
 * {@see SudoStash} keeps the uploads of a refused write only where they are read back, and {@see SudoReplay} reads
 * them back. A stash that kept what the replay refuses writes bytes nothing ever asks for; one that dropped what the
 * replay wants re-runs a submission with its files missing.
 */
final readonly class ReplayableAction
{
    public function __construct(private RouterInterface $router)
    {
    }

    /** The action the router has already named on the request. */
    public function covers(Request $request): bool
    {
        return $this->declares($request->attributes->get('_controller'));
    }

    /**
     * The same test against a path on its own, for a caller that has one before it has a request to run it on.
     */
    public function coversPath(string $path): bool
    {
        try {
            $route = $this->router->match($path);
        } catch (RoutingException) {
            return false;
        }

        return $this->declares($route['_controller'] ?? null);
    }

    private function declares(mixed $controller): bool
    {
        return ControllerAttribute::isPresent(
            $controller,
            Replayable::class,
        );
    }
}
